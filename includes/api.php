<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
============================================
ارسال پیام کاربر به API چت و استریم پاسخ به‌صورت SSE

این تابع یک درخواست POST به اندپوینت:
    https://mhtrxz.ir/api/v1/chat/messages
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

خروجی: آرایه‌ای با کلیدهای:
    status       => success | error | timeout
    message      : پیام (در حالت خطا)
    full_content : کل متن تجمیع‌شده (در حالت success)
============================================
*/
function ai_agent_call_api_stream($message, $session_id, $on_chunk = null, $on_done = null, $on_error = null) {

    $settings = ai_agent_get_settings();
    $api_key  = ai_agent_get_api_key();
    $timeout  = max(1, intval($settings['timeout']));

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

    $url = 'https://mhtrxz.ir/api/v1/chat/messages';

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

    // ساخت یک closure برای parse کردن یک خط SSE / JSON
$parser_line = function($line) use (&$full_content, &$api_session_id, &$api_message_id, $on_chunk) {
    $line = trim($line);
    if ($line === '' || $line === '[DONE]') {
        return;
    }

    if (strpos($line, 'data:') === 0) {
        $line = trim(substr($line, 5));
    }
    elseif (strpos($line, 'event:') === 0
         || strpos($line, 'id:') === 0
         || strpos($line, 'retry:') === 0
         || strpos($line, ':') === 0) {
        return;
    }

    if ($line === '' || $line === '[DONE]') {
        return;
    }

    $decoded = json_decode($line, true);
    if (is_array($decoded)) {
        // استخراج session_id و message_id از اولین رویداد SSE
        if (isset($decoded['session_id']) && $api_session_id === null) {
            $api_session_id = $decoded['session_id'];
        }
        if (isset($decoded['message_id']) && $api_message_id === null) {
            $api_message_id = $decoded['message_id'];
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
        'status'       => 'success',
        'full_content' => $full_content,
        'session_id'   => $api_session_id,
        'message_id'   => $api_message_id,
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

    $base_url = 'https://mhtrxz.ir/api/v1/models';
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
اندپوینت: GET https://mhtrxz.ir/api/v1/sync/settings

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
        return false;
    }

    $url = 'https://mhtrxz.ir/api/v1/sync/settings';

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
ارسال (PATCH) مقادیر تنظیمات به سرور همگام‌سازی
اندپوینت: PATCH https://mhtrxz.ir/api/v1/sync/settings

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

    $url = 'https://mhtrxz.ir/api/v1/sync/settings';

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

    $mapping = array(
        'posts'        => 'Posts',
        'pages'        => 'Pages',
        'products'     => 'WooCommerce Products',
        'product_cats' => 'Product Categories',
    );

    $result = array();
    foreach ($internal_types as $type) {
        if (is_string($type) && isset($mapping[$type])) {
            $result[] = $mapping[$type];
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

    // نگاشت برچسب‌های متنی API به کلیدهای داخلی افزونه
    $mapping = array(
        'Posts'                => 'posts',
        'Pages'                => 'pages',
        'WooCommerce Products' => 'products',
        'Product Categories'   => 'product_cats',
    );

    $internal_keys = array('posts', 'pages', 'products', 'product_cats');

    $result = array();
    foreach ($api_types as $type) {
        if (!is_string($type)) {
            continue;
        }
        $type = trim($type);

        // حالت ۱: برچسب متنی API (مثل "Posts")
        if (isset($mapping[$type])) {
            $result[] = $mapping[$type];
        }
        // حالت ۲: کلید داخلی مستقیم (مثل "posts")
        elseif (in_array($type, $internal_keys, true)) {
            $result[] = $type;
        }
    }

    return array_unique($result);
}
/*
============================================
واکشی تاریخچه‌ی پیام‌های یک session از سرور
اندپوینت: GET https://mhtrxz.ir/api/v1/chat/sessions/{session_id}/messages

خروجی: آرایه‌ای از پیام‌ها (هر کدام شامل id, role, content, created_at)
در صورت موفقیت، یا false در صورت خطا/نبود API Key
============================================
*/
function ai_agent_fetch_chat_history($session_id) {

    $api_key = ai_agent_get_api_key();

    if (empty($api_key) || empty($session_id)) {
        return false;
    }

    $url = 'https://mhtrxz.ir/api/v1/chat/sessions/' . rawurlencode($session_id) . '/messages';

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

اندپوینت: POST https://mhtrxz.ir/api/v1/sync/content
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
            "status":       "publish"   // وضعیت انتشار (publish, draft, private, ...)
        }
    ]
}

پارامتر $items: آرایه‌ای از آیتم‌ها با کلیدهای فوق (حداکثر ۱۰۰ آیتم در هر
درخواست توصیه می‌شود؛ اگر بیشتر باشد، تابع خودش آن‌ها را دسته‌بندی و در
چند درخواست مجزا ارسال می‌کند).

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
            'responses'  => array(),
        );
    }

    // 2. Input validation
    if (!is_array($items) || empty($items)) {
        return array(
            'status'     => 'success',
            'message'    => 'No items to send.',
            'sent_count' => 0,
            'responses'  => array(),
        );
    }

    // 2.b Clean every item: cast source_id to string, skip items with
    //     missing source_id or unsupported content_type, and substitute
    //     safe defaults for empty title/content/url so the server doesn't
    //     reject the batch with HTTP 422.
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
        );
    }

    if (empty($clean_items)) {
        return array(
            'status'     => 'error',
            'message'    => 'No valid items to send. ' . $skipped_count . ' items skipped.',
            'sent_count' => 0,
            'responses'  => array(),
        );
    }

    $url = 'https://mhtrxz.ir/api/v1/sync/content';

    // 3. Batch: 20 items per request (down from 100) to avoid
    //    cURL error 56 "Recv failure: Connection was reset" which is
    //    usually caused by nginx client_max_body_size or upstream body limits
    //    when the payload is too large.
    $batches = array_chunk($clean_items, 20);
    $total_sent = 0;
    $all_responses = array();
    $first_error = '';

    foreach ($batches as $batch_index => $batch) {

        $body = wp_json_encode(array('items' => $batch), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AI_AGENT_SYNC content batch #' . ($batch_index + 1) . ' size=' . strlen($body) . ' bytes, items=' . count($batch));
        }

        $response = wp_remote_post($url, array(
            'timeout'     => 60,
            'redirection' => 0,
            'httpversion' => '1.1',
            'headers'     => array(
                'X-API-Key'    => $api_key,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                // Empty Expect header prevents cURL from sending "Expect: 100-continue"
                // which some upstreams reject by resetting the connection (cURL 56).
                'Expect'       => '',
            ),
            'body'        => $body,
        ));

        // One-shot retry for transient connection errors like cURL 56
        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            if (false !== strpos($err_msg, 'Connection was reset')
                || false !== strpos($err_msg, 'Recv failure')
                || false !== strpos($err_msg, 'Could not resolve host')) {
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
                $first_error = 'خطای ارتباطی با سرور همگام‌سازی: ' . $response->get_error_message();
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
                $first_error = 'سرور همگام‌سازی با کد خطای ' . intval($code) . ' پاسخ داد.' . ($err_detail !== '' ? ' ' . $err_detail : '');
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AI_AGENT_SYNC content batch #' . ($batch_index + 1) . ' HTTP ' . intval($code) . ' body=' . $resp_body);
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

        $total_sent += count($batch);
        $all_responses[] = array(
            'batch'     => $batch_index + 1,
            'status'    => 'success',
            'http_code' => intval($code),
            'body'      => $resp_data,
        );
    }

    $skipped_note = ($skipped_count > 0) ? ' (' . $skipped_count . ' آیتم نامعتبر حذف شدند) ' : '';

    if ($total_sent === 0) {
        return array(
            'status'     => 'error',
            'message'    => $first_error !== '' ? $first_error . $skipped_note : 'هیچ آیتمی ارسال نشد.' . $skipped_note,
            'sent_count' => 0,
            'responses'  => $all_responses,
        );
    }

    if ($total_sent < count($clean_items)) {
        return array(
            'status'     => 'partial',
            'message'    => $total_sent . ' از ' . count($clean_items) . ' آیتم با موفقیت ارسال شد. (' . $first_error . ')' . $skipped_note,
            'sent_count' => $total_sent,
            'responses'  => $all_responses,
        );
    }

    return array(
        'status'     => 'success',
        'message'    => 'تمام آیتم‌ها با موفقیت به سرور همگام‌سازی ارسال شدند.' . $skipped_note,
        'sent_count' => $total_sent,
        'responses'  => $all_responses,
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

اندپوینت: POST https://mhtrxz.ir/api/v1/sync/delete
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

    $url = 'https://mhtrxz.ir/api/v1/sync/delete';

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
