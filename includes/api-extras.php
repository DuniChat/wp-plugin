<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
============================================
تماس‌های تازه‌ی افزونه با API دانی‌چت (مطابق مستندات openapi):
    • ربات پیام‌رسان بله            GET / PUT / DELETE  /bots
    • ادامه‌ی گفت‌وگو در بله        GET  /chat/transfer-options
                                   POST /chat/sessions/{id}/transfer
    • حالت گفتار صوتی              POST /speech/transcribe
    • متادیتای جلسه                PATCH /chat/sessions/{id}/metadata

برخلاف includes/api.php که هر اندپوینت را جداگانه و کامل می‌نویسد،
اینجا یک تابع عمومی داریم و بقیه روی آن سوارند. دلیلش ساده است: این
گروه چند اندپوینت دارد و تکرار قالبِ «کلید را بخوان، درخواست بزن،
خطا را ترجمه کن» برای هرکدام، همان کد را چندبار می‌نوشت و چند جای
مختلف برای اشتباه‌کردن می‌ساخت.
============================================
*/

/** ریشه‌ی API. همان مقداری که بقیه‌ی افزونه هم استفاده می‌کند. */
function ai_agent_api_base() {
    return 'https://api.dunichat.ir/api/v1';
}

/*
یک درخواست JSON به API، با کلید سایت.

خروجی همیشه یک آرایه است تا فراخوان مجبور نباشد چند نوع مختلف را
تشخیص بدهد:
    array('ok' => true,  'data' => <بدنه‌ی پاسخ>, 'code' => 200)
    array('ok' => false, 'error' => '<پیام فارسی>',  'code' => <کد یا 0>)

پیام خطا همان چیزی است که سرور در کلید detail فرستاده. سرور این پیام‌ها
را برای همین نوشته که مستقیم به کاربر نشان داده شوند (مثلاً «فرمت صدا
پشتیبانی نمی‌شود»)، و بازنویسی‌شان این‌جا فقط راهنمایی واقعی را با یک
«خطایی رخ داد» عوض می‌کند.
*/
function ai_agent_api_request($method, $path, $body = null, $timeout = 20) {

    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        return array(
            'ok'    => false,
            'code'  => 0,
            'error' => 'اول توکن سایت را در بخش «توکن سایت» ذخیره کنید.',
        );
    }

    $args = array(
        'method'  => strtoupper($method),
        'timeout' => $timeout,
        'headers' => array(
            'X-API-Key' => $api_key,
            'Accept'    => 'application/json',
        ),
    );

    if ($body !== null) {
        $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request(ai_agent_api_base() . $path, $args);

    if (is_wp_error($response)) {
        error_log('AI_AGENT_DEBUG api_request ' . $path . ' WP_Error: ' . $response->get_error_message());
        return array(
            'ok'    => false,
            'code'  => 0,
            'error' => 'ارتباط با سرور دانی‌چت برقرار نشد. اینترنت سرور را بررسی کنید.',
        );
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $raw  = wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    // 204 (No Content) پاسخ موفق بدون بدنه است — مثل حذف ربات.
    if ($code >= 200 && $code < 300) {
        return array('ok' => true, 'code' => $code, 'data' => is_array($data) ? $data : array());
    }

    error_log('AI_AGENT_DEBUG api_request ' . $path . ' HTTP ' . $code . ' body=' . $raw);

    return array(
        'ok'    => false,
        'code'  => $code,
        'error' => ai_agent_api_error_text($data, $code),
    );
}

/*
پیام خطای قابل‌نمایش از بدنه‌ی پاسخ.

FastAPI برای خطاهای اعتبارسنجی، detail را به‌شکل آرایه‌ای از آبجکت‌ها
برمی‌گرداند نه رشته؛ چاپ مستقیم آن به کاربر «Array» نشان می‌داد.
*/
function ai_agent_api_error_text($data, $code) {

    if (is_array($data) && isset($data['detail'])) {
        $detail = $data['detail'];

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        if (is_array($detail)) {
            $messages = array();
            foreach ($detail as $item) {
                if (is_array($item) && isset($item['msg'])) {
                    $messages[] = (string) $item['msg'];
                } elseif (is_string($item)) {
                    $messages[] = $item;
                }
            }
            if (!empty($messages)) {
                return implode('، ', $messages);
            }
        }
    }

    if ($code === 401) {
        return 'توکن سایت پذیرفته نشد. توکن را از پنل دانی‌چت دوباره بگیرید.';
    }
    if ($code === 402) {
        return 'موجودی کیف‌پول سایت تمام شده است. از پنل دانی‌چت شارژ کنید.';
    }
    if ($code === 403) {
        return 'دسترسی مجاز نیست؛ ممکن است موجودی کیف‌پول تمام شده باشد.';
    }
    if ($code === 404) {
        return 'موردی که خواستید پیدا نشد.';
    }
    if ($code === 429) {
        return 'سقف روزانه‌ی این سرویس پر شده یا درخواست‌های هم‌زمان زیاد است؛ کمی بعد دوباره تلاش کنید.';
    }
    if ($code === 503) {
        return 'این سرویس در حال حاضر در دسترس نیست؛ کمی بعد دوباره تلاش کنید.';
    }

    return 'سرور دانی‌چت خطای ' . $code . ' برگرداند.';
}


/*
--------------------------------------------
ربات پیام‌رسان بله
--------------------------------------------
*/

/*
ربات بله‌ی متصل به این سایت، یا null.

مستندات: GET /bots  →  BotStatusResponse یعنی {'bot': BotResponse | null}.
خروجی خام همین‌جا برگردانده می‌شود؛ نرمال‌سازی به شکل «فهرست» در
هندلر AJAX انجام می‌شود.
*/
function ai_agent_fetch_bot() {
    return ai_agent_api_request('GET', '/bots', null, 15);
}

/*
ثبت یا جایگزینی ربات بله‌ی سایت.

مستندات: PUT /bots با بدنه‌ی BotConnectRequest یعنی فقط
{bot_token, is_enabled} — اسم پلتفرم فرستاده نمی‌شود: سرور فقط بله را
پشتیبانی می‌کند و خودش با getMe از بله می‌پرسد. همان چیزی که به
بازدیدکننده گفته می‌شود باید همانی باشد که پلتفرم می‌شناسد، نه چیزی
که مدیر سایت تایپ کرده.

توکن نامعتبر 400 و رباتِ متصل به سایت دیگر 409 برمی‌گرداند؛ پیام
سرور از ai_agent_api_error_text عبور می‌کند.
*/
function ai_agent_save_bot($token, $enabled = true) {
    return ai_agent_api_request('PUT', '/bots', array(
        'bot_token'  => $token,
        'is_enabled' => (bool) $enabled,
    ), 30);
}

/** جداکردن ربات بله. مستندات: DELETE /bots با پاسخ 204 بدون بدنه. */
function ai_agent_delete_bot() {
    return ai_agent_api_request('DELETE', '/bots', null, 30);
}


/*
--------------------------------------------
ادامه‌ی گفت‌وگو در بله
--------------------------------------------
*/

/*
آیا بازدیدکننده می‌تواند گفت‌وگو را در بله ادامه دهد؟

مستندات: GET /chat/transfer-options → TransferOptionsResponse یعنی
{available: bool, bot_username: string|null, bot_link: string|null}.
دکمه‌ی «ادامه در بله» فقط وقتی available=true است نشان داده می‌شود.
*/
function ai_agent_fetch_transfer_options() {
    return ai_agent_api_request('GET', '/chat/transfer-options', null, 10);
}

/*
گرفتن کد ۶ رقمی یک‌بارمصرف (یک ساعت اعتبار) برای ادامه‌ی همین
گفت‌وگو در ربات بله‌ی سایت.

مستندات: POST /chat/sessions/{session_id}/transfer → TransferResponse
یعنی {session_id, code, expires_at, bot_username, bot_link, message}.
پیامِ برگشتی سرور متن آماده‌ی نمایش به بازدیدکننده است.
اگر رباتی متصل نباشد سرور 400 می‌دهد.
*/
function ai_agent_transfer_session($session_id) {
    return ai_agent_api_request(
        'POST',
        '/chat/sessions/' . rawurlencode($session_id) . '/transfer',
        array(),
        20
    );
}

/*
علامت‌گذاری جلسه به‌عنوان «منتقل‌شده به بله» در متادیتای آزاد جلسه.

مستندات: PATCH /chat/sessions/{session_id}/metadata با بدنه‌ی
SessionMetadataUpdateRequest یعنی {metadata: {...}, replace: false}.
به‌صورت پیش‌فرض (replace=false) آبجکت فرستاده‌شده در متادیتای موجود
merge می‌شود، پس فقط همین یک کلید اضافه می‌شود.

چرا لازم است: بعد از رفرش صفحه، ویجت تاریخچه را از سرور می‌خواند؛
فیلد session_metadata در پاسخ GET /chat/sessions/{id}/messages
برمی‌گردد و ویجت با دیدن transferred=true دوباره فیلد پیام را
قفل می‌کند. اگر PATCH ناموفق بود، انتقال به‌هرحال درست انجام شده —
این فراخوانی best-effort است و خطایش فقط لاگ می‌شود.
*/
function ai_agent_mark_session_transferred($session_id) {
    return ai_agent_api_request(
        'PATCH',
        '/chat/sessions/' . rawurlencode($session_id) . '/metadata',
        array(
            'metadata' => array(
                'transferred'    => true,
                'transferred_to' => 'bale',
            ),
        ),
        15
    );
}


/*
--------------------------------------------
حالت گفتار صوتی — تبدیل صدا به متن
--------------------------------------------
*/

/*
ارسال صدای ضبط‌شده‌ی بازدیدکننده به سرور دانی‌چت و گرفتن متن آن.

مستندات: POST /speech/transcribe با بدنه‌ی JSON یعنی
TranscribeRequest {audio_base64, format} — فایل صوتی به‌صورت
base64 و «بدون پیشوند data:». فرمت یکی از wav، webm، ogg، mp3، m4a.
پاسخ: TranscribeResponse {text, duration_seconds, cost_irr}؛ هزینه
بر اساس طولی حساب می‌شود که خود سرویس گزارش می‌کند، نه عددی که
مرورگر می‌گوید — پس چیزی جز متن به مرورگر برنمی‌گردانیم.

نکته‌ی حجم: مرورگر فایل را multipart به وردپرس می‌فرستد (همان منطق
فعلی ویجت) و این‌جا PHP آن را به base64 تبدیل و در JSON می‌فرستد.
base64 حدود یک‌سوم به حجم اضافه می‌کند؛ سقف کل درخواستِ سرور
۵۰ مگابایت است و ضبط ویجت هم از قبل به ۱۲۰ ثانیه محدود است، پس در
عمل به این سقف نمی‌رسیم.
*/
function ai_agent_transcribe_audio($audio_base64, $format) {
    return ai_agent_api_request('POST', '/speech/transcribe', array(
        'audio_base64' => $audio_base64,
        'format'       => $format,
    ), 60);
}
