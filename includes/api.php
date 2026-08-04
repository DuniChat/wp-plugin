<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
============================================
ارسال پیام کاربر به API چت و استریم پاسخ به‌صورت SSE

این تابع یک درخواست POST به اندپوینت:
    https://dunichat.ir/api/v1/chat/messages
با هدرهای زیر می‌فرستد:
    X-API-Key:      <API Key کاربر (رمزگشایی‌شده از دیتابیس)>
    session-id:     <UUID یکتای نشست>
    Accept:         text/event-stream
    Content-Type:   application/json; charset=utf-8

و بدنه‌ی زیر را ارسال می‌کند:
    {
        "message":       <پرامپت کاربر>,
        "model":         <مدل انتخابی از تنظیمات>,
        "system_prompt": <پرامت سیستم از تنظیمات>,
        "stream":        true,
        "visitor_id":    <UUID ذخیره‌شده در کوکی مرورگر>
    }

پاسخ API به‌صورت چانک‌های متوالی است که هر کدام یک JSON با کلید content
هستند، مانند:
    {"content": "سلام!"}

این تابع برای هر چانک دریافتی، callback روی on_chunk را فراخوانی می‌کند.
همچنین اگر زمان دریافت اولین پاسخ (اولین بایت) از مقدار timeout تنظیمات
بیشتر شود، درخواست را قطع کرده و callback روی on_error را با پیام
«ارتباط با سرور برقرار نشد.» فراخوانی می‌کند.

پارامترها:
    $message     : متن پیام کاربر
    $visitor_id  : شناسه‌ی دائمی کاربر (از کوکی)
    $session_id  : شناسه‌ی یکتای نشست (UUID)
    $on_chunk    : callable($content, $raw_chunk) — برای هر چانک محتوا
    $on_done     : callable() — پس از پایان موفق استریم
    $on_error    : callable($message) — در صورت خطا
    $on_escalate : callable($reason, $conversation_id) — وقتی مدل تصمیم به
                   انتقال گفتگو به پشتیبان انسانی می‌گیرد (رویداد escalate).
                   در این حالت هیچ متنی (delta) از سمت مدل ارسال نمی‌شود.

خروجی: آرایه‌ای با کلیدهای:
    status                    => success | error | timeout
    message                   : پیام (در حالت خطا)
    full_content              : کل متن تجمیع‌شده (در حالت success)
    escalate                  : bool — آیا این پاسخ به انتقال به پشتیبان ختم شد
    escalate_reason           : دلیل انتقال به پشتیبان (در صورت escalate)
    escalate_conversation_id  : شناسه‌ی گفتگو در سیستم پشتیبان (در صورت escalate)
============================================
*/
function ai_agent_call_api_stream($message, $session_id, $on_chunk = null, $on_done = null, $on_error = null, $on_escalate = null, $on_references = null) {

    $settings = ai_agent_get_settings();
    $api_key  = ai_agent_get_api_key();
    $timeout  = max(1, intval($settings['timeout']));
    $references = array();
    // اگر API Key تنظیم نشده بود، کالی زده نمی‌شود
    if (empty($api_key)) {
        if (is_callable($on_error)) {
            $on_error('API Key تنظیم نشده است. لطفاً در صفحه‌ی تنظیمات افزونه کلید معتبر وارد کنید.');
        }
        return array(
            'status'  => 'error',
            'message' => 'API Key تنظیم نشده است.',
        );
    }

    $url = 'https://dunichat.ir/api/v1/chat/messages';

    // ساخت بدنه‌ی درخواست طبق مستندات اندپوینت
    $body = wp_json_encode(array(
        'message'       => $message,
        'model'         => isset($settings['model']) ? $settings['model'] : '',
        'system_prompt' => isset($settings['system_prompt']) ? $settings['system_prompt'] : '',
        'stream'        => true,
    ));

    // هدرها (cURL آرایه‌ی «Key: Value» می‌گیرد)
    // session-id فقط اگر از قبل موجود بود ارسال می‌شود؛ در غیر این صورت
    // API یک session جدید می‌سازد و session_id را در پاسخ برمی‌گرداند.
    $headers = array(
        'X-API-Key: ' . $api_key,
        'Accept: text/event-stream',
        'Content-Type: application/json; charset=utf-8',
    );
    if (!empty($session_id)) {
        $headers[] = 'session-id: ' . $session_id;
    }

    // وضعیت داخلی برای callback ها
    $full_content        = '';
    $first_byte_received = false;
    $timed_out           = false;
    $start_time          = microtime(true);
    $buffer              = ''; // بافر خط‌های ناقص دریافتی از cURL

    // متغیرهایی برای ذخیره‌ی session_id و message_id دریافتی از API
    // این مقادیر در اولین رویداد SSE (که شامل کلیدهای session_id و message_id است)
    // استخراج شده و در نهایت در آرایه‌ی خروجی برگردانده می‌شوند.
    $api_session_id = null;
    $api_message_id = null;

    // وضعیت مربوط به انتقال به پشتیبان (رویداد escalate)
    $escalated                = false;
    $escalate_reason          = null;
    $escalate_conversation_id = null;

    // نام رویداد فعلی SSE (از خط "event: xxx")؛ چون یک رویداد ممکن است
    // چند خط داشته باشد (event: ... سپس data: ...)، باید بین خطوط حفظ شود
    // تا وقتی به خط data می‌رسیم بدانیم متعلق به کدام رویداد است.
    $current_event = '';

    // ساخت یک closure برای parse کردن یک خط SSE / JSON
$parser_line = function($line) use (
    &$full_content, &$api_session_id, &$api_message_id, $on_chunk,
    &$escalated, &$escalate_reason, &$escalate_conversation_id, $on_escalate,
    &$current_event,
    &$references, $on_references
) {
    $line = trim($line);
    if ($line === '' || $line === '[DONE]') {
        // خط خالی یعنی پایان یک رویداد SSE؛ نام رویداد را ریست می‌کنیم
        $current_event = '';
        return;
    }

    if (strpos($line, 'event:') === 0) {
        $current_event = trim(substr($line, 6));
        return;
    }

    if (strpos($line, 'data:') === 0) {
        $line = trim(substr($line, 5));
    }
    elseif (strpos($line, 'id:') === 0
         || strpos($line, 'retry:') === 0
         || strpos($line, ':') === 0) {
        return;
    }

    if ($line === '' || $line === '[DONE]') {
        return;
    }

    $decoded = json_decode($line, true);
    if (is_array($decoded)) {

        // رویداد escalate: مدل تصمیم به انتقال گفتگو به پشتیبان انسانی گرفته
        // است. در این حالت هیچ content ای وجود ندارد، فقط دلیل انتقال.
        if ($current_event === 'escalate') {
            $escalated       = true;
            $escalate_reason = isset($decoded['reason']) ? (string) $decoded['reason'] : '';
            if (isset($decoded['conversation_id'])) {
                $escalate_conversation_id = $decoded['conversation_id'];
            }
            // در بلاک رویداد escalate:
            if (is_callable($on_escalate)) {
                $on_escalate($escalate_reason, $escalate_conversation_id, $api_session_id);
            }
            return;
        }
        // رویداد references: لیست محصولات/صفحات مرتبطی که مدل به آن‌ها استناد کرده
        if ($current_event === 'references') {
            if (isset($decoded['references']) && is_array($decoded['references'])) {
                $references = $decoded['references'];
                if (is_callable($on_references)) {
                    $on_references($references);
                }
            }
            return;
        }

        // استخراج session_id و message_id از اولین رویداد SSE
        if (isset($decoded['session_id']) && $api_session_id === null) {
            $api_session_id = $decoded['session_id'];
        }
        if (isset($decoded['message_id']) && $api_message_id === null) {
            $api_message_id = $decoded['message_id'];
        }

        // رویداد done ممکن است خودش هم escalate:true را گزارش کند
        // (مثلاً اگر به هر دلیلی رویداد escalate جداگانه دریافت نشده باشد)
        if ($current_event === 'done' && !empty($decoded['escalate']) && !$escalated) {
            $escalated       = true;
            $escalate_reason = isset($decoded['escalate_reason']) ? (string) $decoded['escalate_reason'] : '';
            // در بلاک رویداد escalate:
            if (is_callable($on_escalate)) {
                $on_escalate($escalate_reason, $escalate_conversation_id, $api_session_id);
            }
        }

        // پردازش چانک‌های محتوایی
        if (isset($decoded['content'])) {
            $content = (string) $decoded['content'];
            $full_content .= $content;
            if (is_callable($on_chunk)) {
                $on_chunk($content, $line);
            }
        }
    } else {
        // DEBUG
        error_log('AI_AGENT_DEBUG raw line: ' . $line);
    }
};

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => false,
        CURLOPT_RETURNTRANSFER => false, // برای استفاده از WRITEFUNCTION
        CURLOPT_CONNECTTIMEOUT => $timeout, // محدودیت اتصال TCP
        CURLOPT_TIMEOUT        => 0, // بدون محدودیت کلّی (استریم)
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,

        // فعال‌سازی progress function برای بررسی timeout اولین پاسخ
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function($ch, $dl_size, $dl_now, $ul_size, $ul_now)
            use (&$first_byte_received, $start_time, $timeout, &$timed_out) {
            // تا زمانی که اولین بایت دریافت نشده، زمان را چک می‌کنیم
            if (!$first_byte_received) {
                $elapsed = microtime(true) - $start_time;
                if ($elapsed > $timeout) {
                    $timed_out = true;
                    // برگرداندن عدد غیرصفر باعث توقف cURL می‌شود
                    return 1;
                }
            }
            return 0;
        },

        // write callback: هر چانک دریافتی از سرور اینجا می‌رسد
        CURLOPT_WRITEFUNCTION  => function($ch, $chunk)
            use (&$full_content, &$first_byte_received, &$buffer, $parser_line, &$api_session_id, &$api_message_id) {
            // اولین بایت دریافت شد → timeout دیگر اعمال نمی‌شود
            $first_byte_received = true;

            $buffer .= $chunk;

            // پردازش خط‌های کامل (جدا شده با \n)
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line   = rtrim($line, "\r");
                $parser_line($line);
            }

            // cURL انتظار دارد تعداد بایت‌های پردازش‌شده برگردانده شود
            return strlen($chunk);
        },
    ));

    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    //DEBUG
    error_log("AI_AGENT_DEBUG curl -> errno=$errno error=$error code=$code full_content_len=" . strlen($full_content) . " timed_out=" . ($timed_out ? '1' : '0'));
    // پردازش باقیمانده‌ی بافر (در صورتی که آخرین چانک \n نداشته باشد)
    if ($buffer !== '') {
        $parser_line($buffer);
        $buffer = '';
    }

    // ۱) خطای timeout اولین پاسخ
    if ($timed_out) {
        if (is_callable($on_error)) {
            $on_error('ارتباط با سرور برقرار نشد.');
        }
        return array(
            'status'  => 'timeout',
            'message' => 'ارتباط با سرور برقرار نشد.',
        );
    }

    // ۲) خطای ارتباطی (شبکه، SSL، ...)
    //    CURLE_WRITE_ERROR معمولا ناشی از abort در progress function است
    //    که در حالت timeout قبلاً هندل شده، پس اینجا نادیده گرفته می‌شود.
    if ($errno !== 0 && !($timed_out && $errno === CURLE_WRITE_ERROR)) {
        if (is_callable($on_error)) {
            $on_error('خطای ارتباطی با سرور.');
        }
        return array(
            'status'  => 'error',
            'message' => 'خطای ارتباطی با سرور: ' . $error,
        );
    }

    // ۳) کد HTTP غیر موفق
    if ($code < 200 || $code >= 300) {
        if (is_callable($on_error)) {
            $on_error('سرور با کد خطای ' . intval($code) . ' پاسخ داد.');
        }
        return array(
            'status'  => 'error',
            'message' => 'کد خطای سرور: ' . intval($code),
        );
    }

    // ۴) موفقیت
    if (is_callable($on_done)) {
        $on_done();
    }

    return array(
        'status'                   => 'success',
        'full_content'             => $full_content,
        'session_id'               => $api_session_id,
        'message_id'               => $api_message_id,
        'escalate'                 => $escalated,
        'escalate_reason'          => $escalate_reason,
        'escalate_conversation_id' => $escalate_conversation_id,
        'references'               => $references,
    );
}

/*
============================================
واکشی لیست مدل‌های هوش مصنوعی از سرور اختصاصی
اگر $query خالی باشد، بدون پارامتر q کال می‌زند (طبق درخواست)
============================================
*/
function ai_agent_fetch_models($query = '', $limit = 10) {

    $args = array();

    if (!empty($query)) {
        $args['q'] = $query;
    }
    if (!empty($limit)) {
        $args['limit'] = intval($limit);
    }

    $base_url = 'https://dunichat.ir/api/v1/models';
    $url = !empty($args) ? $base_url . '?' . http_build_query($args) : $base_url;

    $response = wp_remote_get($url, array(
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data)) {
        return false;
    }

    // بسته به ساختار واقعی پاسخ API، لیست مدل‌ها ممکن است داخل یکی از این کلیدها باشد
    if (isset($data['models']) && is_array($data['models'])) {
        return $data['models'];
    }
    if (isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }

    // اگر خود پاسخ مستقیماً یک آرایه از مدل‌هاست
    return $data;
}

/*
============================================
واکشی تنظیمات همگام‌سازی از سرور اختصاصی
اندپوینت: GET https://dunichat.ir/api/v1/sync/settings

این تابع در هر بار باز شدن صفحه‌ی تنظیمات افزونه فراخوانی می‌شود
و مقادیر فعلی مدل انتخاب‌شده، پرامت سیستم و منابع مجاز را از سرور
می‌گیرد. API Key به‌صورت رمزشده در دیتابیس (wp_options) نگهداری
می‌شود و در این‌جا رمزگشایی شده و در هدر X-API-Key ارسال می‌شود.

خروجی:
- آرایه‌ی تنظیمات در صورت موفقیت (شامل selected_model, system_prompt, allowed_content_types, ...)
- false در صورت خطا یا نبود API Key
============================================
*/
function ai_agent_fetch_sync_settings() {

    // کلید API را به‌صورت رمزگشایی‌شده از دیتابیس می‌خوانیم
    $api_key = ai_agent_get_api_key();

    // اگر کاربر هنوز API Key وارد نکرده بود، کالی زده نمی‌شود
    if (empty($api_key)) {
        error_log('AI_AGENT_DEBUG sync_settings: API Key خالی است، درخواست ارسال نشد.');
        return false;
    }

    $url = 'https://dunichat.ir/api/v1/sync/settings';

    $response = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => array(
            'X-API-Key' => $api_key,
            'Accept'    => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        error_log('AI_AGENT_DEBUG sync_settings WP_Error: ' . $response->get_error_code() . ' - ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code !== 200) {
        error_log('AI_AGENT_DEBUG sync_settings HTTP ' . intval($code) . ' body=' . $body);
        return false;
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        error_log('AI_AGENT_DEBUG sync_settings: پاسخ JSON معتبر نبود. raw body=' . $body);
        return false;
    }

    return $data;
}

/*
============================================
ارسال (PATCH) مقادیر تنظیمات به سرور همگام‌سازی
اندپوینت: PATCH https://dunichat.ir/api/v1/sync/settings

این تابع زمانی فراخوانی می‌شود که کاربر روی دکمه‌ی «ذخیره تنظیمات
افزونه» کلیک کرده و می‌خواهیم مقادیر جدید (مدل انتخابی، پرامت
سیستم، منابع محتوای مجاز، وضعیت‌های مجاز و سقف پیام روزانه) را
به سرور اختصاصی اطلاع دهیم. کلید API از دیتابیس رمزگشایی و در
هدر X-API-Key ارسال می‌شود.

ورودی: آرایه‌ای با کلیدهای مطابق بدنه‌ی درخواست PATCH:
    selected_model, system_prompt, allowed_content_types,
    allowed_statuses, daily_message_limit

خروجی:
- آرایه‌ی پاسخ سرور (decode شده) در صورت موفقیت
- false در صورت نبود API Key یا بروز خطای ارتباطی / کد HTTP غیر 200
============================================
*/
function ai_agent_push_sync_settings($payload) {

    $api_key = ai_agent_get_api_key();

    if (empty($api_key)) {
        return false;
    }

    $url = 'https://dunichat.ir/api/v1/sync/settings';

    $response = wp_remote_request($url, array(
        'method'  => 'PATCH',
        'timeout' => 15,
        'headers' => array(
            'X-API-Key'    => $api_key,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
        ),
        'body' => wp_json_encode($payload),
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return is_array($data) ? $data : true;
}

/*
============================================
نگاشت معکوس: تبدیل کلیدهای داخلی افزونه (posts, pages, ...)
به برچسب‌های متنی مورد انتظار API جهت ارسال در بدنه‌ی PATCH
(معکوس تابع ai_agent_map_content_types)
============================================
*/
function ai_agent_unmap_content_types($internal_types) {

    if (!is_array($internal_types)) {
        return array();
    }

    $map = array(
        'posts'        => 'post',
        'pages'        => 'page',
        'products'     => 'product',
        'product_cats' => 'list',
    );

    $result = array();

    foreach ($internal_types as $type) {
        if (isset($map[$type])) {
            $result[] = $map[$type];
        }
    }

    return array_values(array_unique($result));
}

/*
============================================
نگاشت مقادیر allowed_content_types دریافتی از API به کلیدهای داخلی افزونه

API ممکن است این مقادیر متنی را برگرداند:
- "Posts"                 → posts
- "Pages"                 → pages
- "WooCommerce Products"  → products
- "Product Categories"    → product_cats

همچنین اگر خود API مستقیماً کلیدهای داخلی (posts, pages, products, product_cats)
را برگرداند نیز پشتیبانی می‌شود.

خروجی: آرایه‌ای از کلیدهای داخلی سازگار با sync_types
============================================
*/
function ai_agent_map_content_types($api_types) {

    if (!is_array($api_types)) {
        return array();
    }

    $map = array(
        'post'    => 'posts',
        'page'    => 'pages',
        'product' => 'products',
        'list'    => 'product_cats',
    );

    $result = array();

    foreach ($api_types as $type) {
        if (isset($map[$type])) {
            $result[] = $map[$type];
        }
    }

    return array_values(array_unique($result));
}
/*
============================================
واکشی تاریخچه‌ی پیام‌های یک session از سرور
اندپوینت: GET https://dunichat.ir/api/v1/chat/sessions/{session_id}/messages

خروجی: آرایه‌ای از پیام‌ها (هر کدام شامل id, role, content, created_at)
در صورت موفقیت، یا false در صورت خطا/نبود API Key
============================================
*/
function ai_agent_fetch_chat_history($session_id) {

    $api_key = ai_agent_get_api_key();

    if (empty($api_key) || empty($session_id)) {
        return false;
    }

    $url = 'https://dunichat.ir/api/v1/chat/sessions/' . rawurlencode($session_id) . '/messages';

    $response = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => array(
            'X-API-Key' => $api_key,
            'Accept'    => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data)) {
        return false;
    }

    return $data;
}

/*
============================================
ارسال محتوای همگام‌سازی به اندپوینت /sync/content

اندپوینت: POST https://dunichat.ir/api/v1/sync/content
هدر X-API-Key: کلید API کاربر (رمزگشایی‌شده از دیتابیس)

بدنه‌ی درخواست (JSON):
{
    "items": [
        {
            "source_id":    "string",   // آی‌دی یکتای محتوا در وردپرس
            "content_type": "page",     // post | page | product | product_category
            "title":        "string",
            "content":      "string",
            "url":          "string",
            "status":       "publish",  // وضعیت انتشار (publish, draft, private, ...)
            "images":       ["base64", "base64", ...]  // حداکثر ۴ عکس به‌صورت base64
        }
    ]
}

پارامتر $items: آرایه‌ای از آیتم‌ها با کلیدهای فوق.

نکته‌ی مهم درباره‌ی صف‌بندی: چون فیلد images اضافه شده، حجم هر آیتم
به‌طور قابل‌توجهی بیشتر شده است. برای جلوگیری از ارسال یک‌باره‌ی
حجم زیاد به سرور، آیتم‌ها در صف‌های ۱۰تایی (batch) تقسیم و در چند
درخواست مجزا به API ارسال می‌شوند.

خروجی: آرایه‌ای با کلیدهای:
    status     => success | partial | error
    message    : پیام توضیحی
    sent_count : تعداد آیتم‌هایی که با موفقیت ارسال شدند
    responses  : آرایه‌ای از پاسخ‌های خام سرور برای هر دسته
============================================
*/
function ai_agent_push_sync_content($items) {

    // 1. API Key check
    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        return array(
            'status'     => 'error',
            'message'    => 'API Key not set.',
            'sent_count' => 0,
            'results'    => array(),
        );
    }

    // 2. Input validation
    if (!is_array($items) || empty($items)) {
        return array(
            'status'     => 'success',
            'message'    => 'No items to send.',
            'sent_count' => 0,
            'results'    => array(),
        );
    }

    // 2.b پاکسازی هر آیتم (همون منطق قبلی)
    $allowed_content_types = array('post', 'page', 'product', 'product_category');
    $allowed_statuses      = array('publish', 'draft', 'pending', 'private', 'future');
    $clean_items = array();
    $skipped_count = 0;

    foreach ($items as $item) {
        if (!isset($item['source_id']) || $item['source_id'] === '' || $item['source_id'] === null) {
            $skipped_count++;
            continue;
        }
        if (!isset($item['content_type']) || !in_array($item['content_type'], $allowed_content_types, true)) {
            $skipped_count++;
            continue;
        }

        $title   = isset($item['title'])   ? (string) $item['title']   : '';
        $content = isset($item['content']) ? (string) $item['content'] : '';
        $url     = isset($item['url'])     ? (string) $item['url']     : '';
        $status  = isset($item['status'])  ? (string) $item['status']  : 'publish';

        // استخراج عکس‌ها (حداکثر ۴ عکس)؛ رشته‌های خالی و غیررشته‌ای فیلتر می‌شوند
        $images = array();
        if (isset($item['images']) && is_array($item['images'])) {
            foreach ($item['images'] as $img) {
                if (count($images) >= 4) {
                    break; // سقف ۴ عکس طبق قرارداد API
                }
                if (is_string($img) && trim($img) !== '') {
                    $images[] = $img;
                }
            }
        }

        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'publish';
        }
        if (trim($title) === '') {
            $title = 'بدون عنوان';
        }
        if (trim($content) === '') {
            $content = 'بدون محتوا';
        }
        if (trim($url) === '') {
            $url = home_url('/');
        }

        $clean_items[] = array(
            'source_id'    => (string) $item['source_id'],
            'content_type' => (string) $item['content_type'],
            'title'        => $title,
            'content'      => $content,
            'url'          => $url,
            'status'       => $status,
            'images'       => $images,
        );
    }

    if (empty($clean_items)) {
        return array(
            'status'     => 'error',
            'message'    => 'No valid items to send. ' . $skipped_count . ' items skipped.',
            'sent_count' => 0,
            'results'    => array(),
        );
    }

    $url = 'https://dunichat.ir/api/v1/sync/content';

    // ====================================================================
    // صف‌بندی (Batching):
    // چون فیلد images اضافه شده، حجم هر آیتم به‌طور قابل‌توجهی بیشتر شده است.
    // برای جلوگیری از ارسال یک‌باره‌ی حجم زیاد به سرور و جلوگیری از timeout
    // یا خطاهای nginx/PHP، آیتم‌ها را در دسته‌های ۱۰تایی تقسیم کرده و هر
    // دسته را در یک درخواست مجزا به API ارسال می‌کنیم.
    // در صورت خطای یک دسته، آن دسته رد شده و بقیه دسته‌ها همچنان ارسال می‌شوند.
    // ====================================================================
    $batch_size = 10;
    $batches    = array_chunk($clean_items, $batch_size);

    $total_sent   = 0;
    $all_results  = array();
    $first_error  = '';
    $total_valid  = count($clean_items);
    $batches_total = count($batches);

    foreach ($batches as $batch_index => $batch) {

        $body = wp_json_encode(array('items' => $batch), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AI_AGENT_SYNC content batch #' . ($batch_index + 1) . '/' . $batches_total . ' size=' . strlen($body) . ' bytes, items=' . count($batch));
        }

        $request_args = array(
            'timeout'     => 120, // حجم بیشتر به‌خاطر عکس‌ها → timeout بالاتر
            'redirection' => 0,
            'httpversion' => '1.1',
            'headers'     => array(
                'X-API-Key'    => $api_key,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'Expect'       => '',
            ),
            'body'        => $body,
        );

        $response = wp_remote_post($url, $request_args);

        // یک بار retry برای خطاهای اتصال گذرا
        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            if (false !== strpos($err_msg, 'Connection was reset')
                || false !== strpos($err_msg, 'Recv failure')
                || false !== strpos($err_msg, 'Could not resolve host')) {
                sleep(1);
                $response = wp_remote_post($url, $request_args);
            }
        }

        if (is_wp_error($response)) {
            if ($first_error === '') {
                $first_error = 'خطای ارتباطی با سرور همگام‌سازی: ' . $response->get_error_message();
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AI_AGENT_SYNC content batch #' . ($batch_index + 1) . ' WP_ERROR: ' . $response->get_error_message());
            }
            continue; // رد شدن این دسته، ادامه‌ی دسته‌های بعدی
        }

        $code      = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        $resp_data = json_decode($resp_body, true);

        if ($code < 200 || $code >= 300) {
            $err_detail = ai_agent_parse_sync_error_detail($resp_data, $resp_body);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AI_AGENT_SYNC content batch #' . ($batch_index + 1) . ' HTTP ' . intval($code) . ' body=' . $resp_body);
            }
            if ($first_error === '') {
                $first_error = 'سرور همگام‌سازی با کد خطای ' . intval($code) . ' پاسخ داد.' . ($err_detail !== '' ? ' ' . $err_detail : '');
            }
            continue; // رد شدن این دسته، ادامه‌ی دسته‌های بعدی
        }

        // پارس آرایه‌ی results پاسخ سرور:
        // {"results":[{"source_id":"78","content_type":"page","action":"queued","job_id":"..."}]}
        if (is_array($resp_data) && isset($resp_data['results']) && is_array($resp_data['results'])) {
            foreach ($resp_data['results'] as $r) {
                if (!is_array($r) || !isset($r['source_id'], $r['content_type'])) {
                    continue;
                }
                $all_results[] = array(
                    'source_id'    => (string) $r['source_id'],
                    'content_type' => (string) $r['content_type'],
                    'action'       => isset($r['action']) ? (string) $r['action'] : '',
                    'job_id'       => isset($r['job_id']) ? (string) $r['job_id'] : '',
                );
            }
        }

        // مکث کوتاه بین دسته‌ها برای کاهش بار لحظه‌ای روی سرور (۰.۵ ثانیه)
        if ($batch_index < $batches_total - 1) {
            usleep(500000);
        }
    }

    $sent_count   = count($all_results);
    $skipped_note = ($skipped_count > 0) ? ' (' . $skipped_count . ' آیتم نامعتبر حذف شدند) ' : '';

    // اگر هیچ آیتمی در هیچ دسته‌ای پذیرفته نشد → خطا
    if ($sent_count === 0) {
        $final_msg = ($first_error !== '')
            ? $first_error . $skipped_note
            : 'سرور هیچ آیتمی را در پاسخ results برنگرداند.' . $skipped_note;
        return array(
            'status'     => 'error',
            'message'    => $final_msg,
            'sent_count' => 0,
            'results'    => array(),
        );
    }

    // اگر برخی آیتم‌ها پذیرفته شدند ولی برخی (به‌خاطر خطای دسته یا رد سرور) نشدند → partial
    if ($sent_count < $total_valid) {
        $extra_err = ($first_error !== '') ? ' ' . $first_error : '';
        return array(
            'status'     => 'partial',
            'message'    => $sent_count . ' از ' . $total_valid . ' آیتم توسط سرور پذیرفته شد.' . $skipped_note . $extra_err,
            'sent_count' => $sent_count,
            'results'    => $all_results,
        );
    }

    // همه‌ی آیتم‌ها با موفقیت پذیرفته شدند
    return array(
        'status'     => 'success',
        'message'    => 'تمام آیتم‌ها با موفقیت به سرور همگام‌سازی ارسال شدند.' . $skipped_note,
        'sent_count' => $sent_count,
        'results'    => $all_results,
    );
}

/*
============================================
Parse server error detail from a sync API response.

FastAPI servers return 422 errors with a JSON body like:
    {"detail": [
        {"loc": ["body","items",0,"source_id"], "msg": "value is not a valid integer", "type": "type_error.integer"}
    ]}

Previously the code only handled string `detail`, falling back to the
generic "خطای ناشناخته از سرور." message whenever `detail` was an array -
which is exactly what the user was seeing.

This helper handles:
  1) {"detail": "string"}
  2) {"detail": [{"loc":..., "msg":..., "type":...}, ...]}  (FastAPI 422)
  3) {"message": "string"}
  4) {"errors": {"field": ["msg", ...]}}                     (Laravel-style)
  5) Raw non-JSON body string
============================================
*/
function ai_agent_parse_sync_error_detail($resp_data, $resp_body = '') {

    if (!is_array($resp_data)) {
        if (!empty($resp_body)) {
            $trimmed = trim($resp_body);
            if (function_exists('mb_strlen') && mb_strlen($trimmed) > 300) {
                return mb_substr($trimmed, 0, 300) . '...';
            }
            return $trimmed;
        }
        return '';
    }

    // Case 1: detail is a string
    if (isset($resp_data['detail']) && is_string($resp_data['detail'])) {
        return $resp_data['detail'];
    }

    // Case 2: detail is array of validation errors (FastAPI 422)
    if (isset($resp_data['detail']) && is_array($resp_data['detail'])) {
        $messages = array();
        foreach ($resp_data['detail'] as $err) {
            if (!is_array($err)) {
                $messages[] = (string) $err;
                continue;
            }
            $loc  = isset($err['loc'])  && is_array($err['loc']) ? implode(' > ', array_map('strval', $err['loc'])) : '';
            $msg  = isset($err['msg'])  ? (string) $err['msg']  : '';
            $type = isset($err['type']) ? (string) $err['type'] : '';
            $line = '';
            if ($loc !== '') {
                $line .= '[' . $loc . '] ';
            }
            if ($msg !== '') {
                $line .= $msg;
            }
            if ($type !== '' && $type !== 'value_error.missing') {
                $line .= ' (' . $type . ')';
            }
            $messages[] = $line;
        }
        if (!empty($messages)) {
            return 'خطای اعتبارسنجی سرور: ' . implode(' | ', array_slice($messages, 0, 5));
        }
    }

    // Case 3: simple message field
    if (isset($resp_data['message']) && is_string($resp_data['message'])) {
        return $resp_data['message'];
    }

    // Case 4: errors dict (Laravel)
    if (isset($resp_data['errors']) && is_array($resp_data['errors'])) {
        $messages = array();
        foreach ($resp_data['errors'] as $field => $msgs) {
            if (is_array($msgs)) {
                $messages[] = $field . ': ' . implode(', ', array_map('strval', $msgs));
            } else {
                $messages[] = $field . ': ' . (string) $msgs;
            }
        }
        if (!empty($messages)) {
            return 'خطای اعتبارسنجی سرور: ' . implode(' | ', array_slice($messages, 0, 5));
        }
    }

    // Fallback: return a truncated JSON dump
    $json = wp_json_encode($resp_data, JSON_UNESCAPED_UNICODE);
    if (function_exists('mb_strlen') && mb_strlen($json) > 300) {
        return mb_substr($json, 0, 300) . '...';
    }
    return $json;
}


/*
============================================
ارسال درخواست حذف محتوا به اندپوینت /sync/delete

اندپوینت: POST https://dunichat.ir/api/v1/sync/delete
هدر X-API-Key: کلید API کاربر (رمزگشایی‌شده از دیتابیس)

بدنه‌ی درخواست (JSON):
{
    "items": [
        {
            "source_id":    "string",
            "content_type": "page"
        }
    ]
}

این تابع زمانی فراخوانی می‌شود که محتوایی که قبلاً سینک شده، در
وردپرس حذف شده باشد و بخواهیم به سرور همگام‌سازی اطلاع دهیم که آن
محتوا دیگر وجود ندارد.

پارامتر $items: آرایه‌ای از آیتم‌ها با کلیدهای source_id و content_type

خروجی: آرایه‌ای با کلیدهای:
    status        => success | partial | error
    message       : پیام توضیحی
    deleted_count : تعداد آیتم‌هایی که با موفقیت به‌عنوان حذف ارسال شدند
    responses     : آرایه‌ای از پاسخ‌های خام سرور برای هر دسته
============================================
*/
function ai_agent_push_sync_delete($items) {

    // 1. API Key check
    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        return array(
            'status'        => 'error',
            'message'       => 'API Key not set.',
            'deleted_count' => 0,
            'responses'     => array(),
        );
    }

    // 2. Input validation
    if (!is_array($items) || empty($items)) {
        return array(
            'status'        => 'success',
            'message'       => 'No items to delete.',
            'deleted_count' => 0,
            'responses'     => array(),
        );
    }

    // Keep only required keys, ensure source_id is a string
    $clean_items = array();
    foreach ($items as $item) {
        if (isset($item['source_id'], $item['content_type'])
            && $item['source_id'] !== ''
            && $item['source_id'] !== null) {
            $clean_items[] = array(
                'source_id'    => (string) $item['source_id'],
                'content_type' => (string) $item['content_type'],
            );
        }
    }

    if (empty($clean_items)) {
        return array(
            'status'        => 'success',
            'message'       => 'No valid items to delete.',
            'deleted_count' => 0,
            'responses'     => array(),
        );
    }

    $url = 'https://dunichat.ir/api/v1/sync/delete';

    // 3. Batch (20 per request) - same reasoning as push_sync_content
    $batches = array_chunk($clean_items, 20);
    $total_deleted = 0;
    $all_responses = array();
    $first_error = '';

    foreach ($batches as $batch_index => $batch) {

        $body = wp_json_encode(array('items' => $batch), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AI_AGENT_SYNC delete batch #' . ($batch_index + 1) . ' items=' . count($batch));
        }

        $response = wp_remote_post($url, array(
            'timeout'     => 60,
            'redirection' => 0,
            'httpversion' => '1.1',
            'headers'     => array(
                'X-API-Key'    => $api_key,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'Expect'       => '',
            ),
            'body'        => $body,
        ));

        // One-shot retry for transient connection errors
        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            if (false !== strpos($err_msg, 'Connection was reset')
                || false !== strpos($err_msg, 'Recv failure')) {
                sleep(1);
                $response = wp_remote_post($url, array(
                    'timeout'     => 60,
                    'redirection' => 0,
                    'httpversion' => '1.1',
                    'headers'     => array(
                        'X-API-Key'    => $api_key,
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Expect'       => '',
                    ),
                    'body'        => $body,
                ));
            }
        }

        if (is_wp_error($response)) {
            if ($first_error === '') {
                $first_error = 'خطای ارتباطی با سرور حذف: ' . $response->get_error_message();
            }
            $all_responses[] = array(
                'batch'  => $batch_index + 1,
                'status' => 'error',
                'error'  => $response->get_error_message(),
            );
            continue;
        }

        $code = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        $resp_data = json_decode($resp_body, true);

        if ($code < 200 || $code >= 300) {
            $err_detail = ai_agent_parse_sync_error_detail($resp_data, $resp_body);
            if ($first_error === '') {
                $first_error = 'سرور حذف با کد خطای ' . intval($code) . ' پاسخ داد.' . ($err_detail !== '' ? ' ' . $err_detail : '');
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AI_AGENT_SYNC delete batch #' . ($batch_index + 1) . ' HTTP ' . intval($code) . ' body=' . $resp_body);
            }
            $all_responses[] = array(
                'batch'     => $batch_index + 1,
                'status'    => 'error',
                'http_code' => intval($code),
                'body'      => $resp_data,
                'raw'       => $resp_body,
            );
            continue;
        }

        $total_deleted += count($batch);
        $all_responses[] = array(
            'batch'     => $batch_index + 1,
            'status'    => 'success',
            'http_code' => intval($code),
            'body'      => $resp_data,
        );
    }

    if ($total_deleted === 0) {
        return array(
            'status'        => 'error',
            'message'       => $first_error !== '' ? $first_error : 'هیچ آیتمی برای حذف ارسال نشد.',
            'deleted_count' => 0,
            'responses'     => $all_responses,
        );
    }

    if ($total_deleted < count($clean_items)) {
        return array(
            'status'        => 'partial',
            'message'       => $total_deleted . ' از ' . count($clean_items) . ' آیتم حذف با موفقیت ارسال شد. (' . $first_error . ')',
            'deleted_count' => $total_deleted,
            'responses'     => $all_responses,
        );
    }

    return array(
        'status'        => 'success',
        'message'       => 'تمام درخواست‌های حذف با موفقیت به سرور ارسال شدند.',
        'deleted_count' => $total_deleted,
        'responses'     => $all_responses,
    );
}
/*
============================================
استعلام وضعیت دسته‌ای job_id های ارسال‌شده به سرور
اندپوینت: POST https://dunichat.ir/api/v1/sync/content/status/batch
هدر X-API-Key: کلید API کاربر (رمزگشایی‌شده از دیتابیس)

بدنه‌ی درخواست (JSON):
{
    "job_ids": ["string", ...]
}

پارامتر $job_ids: آرایه‌ای از رشته‌های job_id (از جدول wp_ai_agent_synced_items)

خروجی: آرایه‌ای با کلیدهای:
    status  => success | error
    message : پیام (در حالت خطا)
    results : آرایه‌ی results خام سرور
    summary : آرایه‌ی summary خام سرور (total, queued, processing, completed, failed, not_found)
============================================
*/
function ai_agent_fetch_sync_status_batch($job_ids) {

    $api_key = ai_agent_get_api_key();

    if (empty($api_key)) {
        return array(
            'status'  => 'error',
            'message' => 'API Key تنظیم نشده است.',
            'results' => array(),
            'summary' => null,
        );
    }

    if (!is_array($job_ids) || empty($job_ids)) {
        return array(
            'status'  => 'error',
            'message' => 'هیچ job_id ای برای استعلام یافت نشد.',
            'results' => array(),
            'summary' => null,
        );
    }

    // پاکسازی و حذف مقادیر خالی/تکراری
    $job_ids = array_values(array_unique(array_filter(
        array_map('strval', $job_ids),
        function($v) { return $v !== ''; }
    )));

    if (empty($job_ids)) {
        return array(
            'status'  => 'error',
            'message' => 'هیچ job_id معتبری برای استعلام یافت نشد.',
            'results' => array(),
            'summary' => null,
        );
    }

    $url = 'https://dunichat.ir/api/v1/sync/content/status/batch';

    $body = wp_json_encode(array('job_ids' => $job_ids));

    $response = wp_remote_post($url, array(
        'timeout'     => 30,
        'redirection' => 0,
        'httpversion' => '1.1',
        'headers'     => array(
            'X-API-Key'    => $api_key,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
        ),
        'body' => $body,
    ));

    if (is_wp_error($response)) {
        return array(
            'status'  => 'error',
            'message' => 'خطای ارتباطی با سرور استعلام وضعیت: ' . $response->get_error_message(),
            'results' => array(),
            'summary' => null,
        );
    }

    $code      = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $resp_data = json_decode($resp_body, true);

    if ($code < 200 || $code >= 300) {
        $err_detail = ai_agent_parse_sync_error_detail($resp_data, $resp_body);
        return array(
            'status'  => 'error',
            'message' => 'سرور استعلام وضعیت با کد خطای ' . intval($code) . ' پاسخ داد.' . ($err_detail !== '' ? ' ' . $err_detail : ''),
            'results' => array(),
            'summary' => null,
        );
    }

    if (!is_array($resp_data) || !isset($resp_data['results']) || !is_array($resp_data['results'])) {
        return array(
            'status'  => 'error',
            'message' => 'پاسخ سرور استعلام وضعیت ساختار مورد انتظار را نداشت.',
            'results' => array(),
            'summary' => null,
        );
    }

    return array(
        'status'  => 'success',
        'message' => '',
        'results' => $resp_data['results'],
        'summary' => (isset($resp_data['summary']) && is_array($resp_data['summary'])) ? $resp_data['summary'] : null,
    );
}

/*
============================================
واکشی لیست جلسات چت از سرور
اندپوینت: GET https://dunichat.ir/api/v1/chat/sessions

پارامترها:
    $page : شماره صفحه (پیش‌فرض: 1)
    $page_size : تعداد آیتم در هر صفحه (پیش‌فرض: 10)
    $status_filter : فیلتر وضعیت (اختیاری — اگر خالی باشد ارسال نمی‌شود)

خروجی: آرایه‌ای با کلیدهای:
    status  => success | error
    message : پیام (در حالت خطا)
    items   : آرایه‌ی جلسات
    total   : تعداد کل جلسات
    page    : شماره صفحه فعلی
    page_size : تعداد در هر صفحه
    has_next : آیا صفحه بعدی وجود دارد
============================================
*/
function ai_agent_fetch_chat_sessions($page = 1, $page_size = 10, $status_filter = '') {

    $api_key = ai_agent_get_api_key();

    if (empty($api_key)) {
        return array(
            'status'  => 'error',
            'message' => 'API Key تنظیم نشده است. لطفاً در صفحه‌ی تنظیمات کلید معتبر وارد کنید.',
            'items'   => array(),
            'total'   => 0,
            'page'    => 1,
            'page_size' => $page_size,
            'has_next' => false,
        );
    }

    $url = 'https://dunichat.ir/api/v1/chat/sessions';

    $query_params = array(
        'page'      => max(1, intval($page)),
        'page_size' => max(1, intval($page_size)),
    );

    if ($status_filter !== '') {
        $query_params['status_filter'] = sanitize_text_field($status_filter);
    }

    $url .= '?' . http_build_query($query_params);

    $response = wp_remote_get($url, array(
        'timeout' => 20,
        'headers' => array(
            'X-API-Key' => $api_key,
            'Accept'    => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        return array(
            'status'  => 'error',
            'message' => 'خطای ارتباطی با سرور: ' . $response->get_error_message(),
            'items'   => array(),
            'total'   => 0,
            'page'    => 1,
            'page_size' => $page_size,
            'has_next' => false,
        );
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return array(
            'status'  => 'error',
            'message' => 'سرور با کد خطای ' . intval($code) . ' پاسخ داد.',
            'items'   => array(),
            'total'   => 0,
            'page'    => 1,
            'page_size' => $page_size,
            'has_next' => false,
        );
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data) || !isset($data['items'])) {
        return array(
            'status'  => 'error',
            'message' => 'پاسخ سرور ساختار مورد انتظار را نداشت.',
            'items'   => array(),
            'total'   => 0,
            'page'    => 1,
            'page_size' => $page_size,
            'has_next' => false,
        );
    }

    return array(
        'status'    => 'success',
        'message'   => '',
        'items'     => is_array($data['items']) ? $data['items'] : array(),
        'total'     => isset($data['total']) ? intval($data['total']) : 0,
        'page'      => isset($data['page']) ? intval($data['page']) : 1,
        'page_size' => isset($data['page_size']) ? intval($data['page_size']) : $page_size,
        'has_next'  => isset($data['has_next']) ? (bool) $data['has_next'] : false,
    );
}

/*
============================================
واکشی پیام‌های یک جلسه چت از سرور
اندپوینت: GET https://dunichat.ir/api/v1/chat/sessions/{session_id}/messages

پارامترها:
    $session_id      : شناسه‌ی جلسه (UUID)
    $include_system  : آیا پیام‌های سیستم هم برگردانده شوند (پیش‌فرض: true)
    $page            : شماره صفحه (پیش‌فرض: 1)
    $page_size       : تعداد پیام در هر صفحه (پیش‌فرض: 10)

خروجی: آرایه‌ای با کلیدهای:
    status  => success | error
    message : پیام (در حالت خطا)
    items   : آرایه‌ی پیام‌ها
    total   : تعداد کل پیام‌ها
    page    : شماره صفحه فعلی
    page_size : تعداد در هر صفحه
    has_next : آیا صفحه بعدی وجود دارد
============================================
*/
function ai_agent_fetch_session_messages($session_id, $include_system = true, $page = 1, $page_size = 10) {

    $api_key = ai_agent_get_api_key();

    if (empty($api_key) || empty($session_id)) {
        return array(
            'status'  => 'error',
            'message' => 'API Key یا شناسه‌ی جلسه تنظیم نشده است.',
            'items'   => array(),
            'total'   => 0,
            'page'    => 1,
            'page_size' => $page_size,
            'has_next' => false,
        );
    }

    $url = 'https://dunichat.ir/api/v1/chat/sessions/' . rawurlencode($session_id) . '/messages';

    $query_params = array(
        'include_system' => $include_system ? 'true' : 'false',
        'page'      => max(1, intval($page)),
        'page_size' => max(1, intval($page_size)),
    );

    $url .= '?' . http_build_query($query_params);

    $response = wp_remote_get($url, array(
        'timeout' => 20,
        'headers' => array(
            'X-API-Key' => $api_key,
            'Accept'    => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        return array(
            'status'  => 'error',
            'message' => 'خطای ارتباطی با سرور: ' . $response->get_error_message(),
            'items'   => array(),
            'total'   => 0,
            'page'    => 1,
            'page_size' => $page_size,
            'has_next' => false,
        );
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return array(
            'status'  => 'error',
            'message' => 'سرور با کد خطای ' . intval($code) . ' پاسخ داد.',
            'items'   => array(),
            'total'   => 0,
            'page'    => 1,
            'page_size' => $page_size,
            'has_next' => false,
        );
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (is_array($data) && isset($data['items'])) {
        return array(
            'status'    => 'success',
            'message'   => '',
            'items'     => is_array($data['items']) ? $data['items'] : array(),
            'total'     => isset($data['total']) ? intval($data['total']) : count($data['items']),
            'page'      => isset($data['page']) ? intval($data['page']) : 1,
            'page_size' => isset($data['page_size']) ? intval($data['page_size']) : $page_size,
            'has_next'  => isset($data['has_next']) ? (bool) $data['has_next'] : false,
        );
    }

    if (is_array($data) && !isset($data['detail'])) {
        return array(
            'status'    => 'success',
            'message'   => '',
            'items'     => $data,
            'total'     => count($data),
            'page'      => 1,
            'page_size' => $page_size,
            'has_next'  => false,
        );
    }

    return array(
        'status'  => 'error',
        'message' => 'پاسخ سرور ساختار مورد انتظار را نداشت.',
        'items'   => array(),
        'total'   => 0,
        'page'    => 1,
        'page_size' => $page_size,
        'has_next' => false,
    );
}

/*
============================================
ارسال پاسخ دستی پشتیبان انسانی به یک جلسه‌ی چت
اندپوینت: POST https://dunichat.ir/api/v1/chat/sessions/{session_id}/reply

این تابع زمانی استفاده می‌شود که پشتیبان از داخل پیشخوان وردپرس
(تب تاریخچه چت‌ها → آکاردئون جلسه) برای یک جلسه‌ی «در انتظار
پشتیبان» یا «پشتیبان» پیام می‌نویسد. شناسه‌ی جلسه هم در مسیر URL
و هم در هدر session-id ارسال می‌شود.

پارامترها:
    $session_id : شناسه‌ی جلسه (UUID)
    $message    : متن پیام پشتیبان

خروجی: آرایه‌ای با کلیدهای:
    status  => success | error
    message : پیام (فقط در حالت خطا)
    data    : پاسخ خام سرور (در حالت موفقیت، در صورت وجود)
============================================
*/
function ai_agent_send_session_reply($session_id, $message) {

    $api_key = ai_agent_get_api_key();

    if (empty($api_key)) {
        return array('status' => 'error', 'message' => 'API Key تنظیم نشده است.');
    }

    if (empty($session_id) || !ai_agent_is_valid_uuid($session_id)) {
        return array('status' => 'error', 'message' => 'شناسه‌ی جلسه نامعتبر است.');
    }

    $message = trim((string) $message);
    if ($message === '') {
        return array('status' => 'error', 'message' => 'متن پیام نمی‌تواند خالی باشد.');
    }

    $url = 'https://dunichat.ir/api/v1/chat/sessions/' . rawurlencode($session_id) . '/reply';

    $response = wp_remote_post($url, array(
        'timeout'     => 20,
        'redirection' => 0,
        'httpversion' => '1.1',
        'headers'     => array(
            'X-API-Key'    => $api_key,
            'session-id'   => $session_id,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
        ),
        'body' => wp_json_encode(array('message' => $message), JSON_UNESCAPED_UNICODE),
    ));

    if (is_wp_error($response)) {
        return array(
            'status'  => 'error',
            'message' => 'خطای ارتباطی با سرور: ' . $response->get_error_message(),
        );
    }

    $code      = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $resp_data = json_decode($resp_body, true);

    if ($code < 200 || $code >= 300) {
        $err_detail = ai_agent_parse_sync_error_detail($resp_data, $resp_body);
        return array(
            'status'  => 'error',
            'message' => 'سرور با کد خطای ' . intval($code) . ' پاسخ داد.' . ($err_detail !== '' ? ' ' . $err_detail : ''),
        );
    }

    return array(
        'status'  => 'success',
        'message' => '',
        'data'    => is_array($resp_data) ? $resp_data : null,
    );
}

/*
============================================
پایان دادن به یک جلسه‌ی چت توسط پشتیبان انسانی
اندپوینت: POST https://dunichat.ir/api/v1/chat/sessions/{session_id}/close

بدون بدنه؛ فقط هدرهای X-API-Key و session-id ارسال می‌شوند.
این تابع فقط برای جلسات «در انتظار پشتیبان» یا «پشتیبان» از پنل
تاریخچه چت‌ها فراخوانی می‌شود.

پارامتر: $session_id (UUID)

خروجی: آرایه‌ای با کلیدهای status و message
============================================
*/
function ai_agent_close_session($session_id) {

    $api_key = ai_agent_get_api_key();

    if (empty($api_key)) {
        return array('status' => 'error', 'message' => 'API Key تنظیم نشده است.');
    }

    if (empty($session_id) || !ai_agent_is_valid_uuid($session_id)) {
        return array('status' => 'error', 'message' => 'شناسه‌ی جلسه نامعتبر است.');
    }

    $url = 'https://dunichat.ir/api/v1/chat/sessions/' . rawurlencode($session_id) . '/close';

    $response = wp_remote_post($url, array(
        'timeout'     => 20,
        'redirection' => 0,
        'httpversion' => '1.1',
        'headers'     => array(
            'X-API-Key'  => $api_key,
            'session-id' => $session_id,
            'Accept'     => 'application/json',
        ),
        'body' => '',
    ));

    if (is_wp_error($response)) {
        return array(
            'status'  => 'error',
            'message' => 'خطای ارتباطی با سرور: ' . $response->get_error_message(),
        );
    }

    $code      = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $resp_data = json_decode($resp_body, true);

    if ($code < 200 || $code >= 300) {
        $err_detail = ai_agent_parse_sync_error_detail($resp_data, $resp_body);
        return array(
            'status'  => 'error',
            'message' => 'سرور با کد خطای ' . intval($code) . ' پاسخ داد.' . ($err_detail !== '' ? ' ' . $err_detail : ''),
        );
    }

    return array(
        'status'  => 'success',
        'message' => 'چت با موفقیت بسته شد.',
    );
}
