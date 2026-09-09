<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
============================================
تماس‌های تازه‌ی افزونه با API دانی‌چت:
    • ربات پیام‌رسان بله
    • اسناد و پرسش‌وپاسخ‌هایی که مدیر سایت دستی اضافه می‌کند

برخلاف includes/api.php که هر اندپوینت را جداگانه و کامل می‌نویسد،
اینجا یک تابع عمومی داریم و بقیه روی آن سوارند. دلیلش ساده است: این
گروه شامل بیش از ده اندپوینت است و تکرار قالبِ «کلید را بخوان، درخواست
بزن، خطا را ترجمه کن» برای هرکدام، همان کد را ده‌بار می‌نوشت و ده جای
مختلف برای اشتباه‌کردن می‌ساخت.
============================================
*/

/** ریشه‌ی API. همان مقداری که بقیه‌ی افزونه هم استفاده می‌کند. */
function ai_agent_api_base() {
    return 'https://api.dunichat.ir/api/v1';
}

/*
یک درخواست به API، با کلید سایت.

خروجی همیشه یک آرایه است تا فراخوان مجبور نباشد چند نوع مختلف را
تشخیص بدهد:
    array('ok' => true,  'data' => <بدنه‌ی پاسخ>, 'code' => 200)
    array('ok' => false, 'error' => '<پیام فارسی>',  'code' => <کد یا 0>)

پیام خطا همان چیزی است که سرور در کلید detail فرستاده. سرور این پیام‌ها
را برای همین نوشته که مستقیم به کاربر نشان داده شوند (مثلاً «فرمت قدیمی
.xls پشتیبانی نمی‌شود؛ فایل را با فرمت .xlsx ذخیره کنید»)، و بازنویسی‌شان
اینجا فقط راهنمایی واقعی را با یک «خطایی رخ داد» عوض می‌کند.
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
    if ($code === 403) {
        return 'دسترسی مجاز نیست؛ ممکن است موجودی کیف‌پول تمام شده باشد.';
    }
    if ($code === 404) {
        return 'موردی که خواستید پیدا نشد.';
    }

    return 'سرور دانی‌چت خطای ' . $code . ' برگرداند.';
}


/*
--------------------------------------------
ربات‌های پیام‌رسان
--------------------------------------------
*/

function ai_agent_fetch_bots() {
    return ai_agent_api_request('GET', '/bots');
}

/*
ثبت یا جایگزینی ربات یک پیام‌رسان.

نام کاربری ربات را نمی‌فرستیم: سرور خودش با getMe از بله می‌پرسد. همان چیزی که به بازدیدکننده گفته می‌شود باید همانی باشد که
پلتفرم می‌شناسد، نه چیزی که مدیر سایت تایپ کرده.
*/
function ai_agent_save_bot($platform, $token, $enabled = true) {
    return ai_agent_api_request('PUT', '/bots', array(
        'platform'   => $platform,
        'bot_token'  => $token,
        'is_enabled' => (bool) $enabled,
    ), 30);
}

function ai_agent_delete_bot($platform) {
    return ai_agent_api_request('DELETE', '/bots/' . rawurlencode($platform), null, 30);
}


/*
--------------------------------------------
اسناد افزوده‌شده به دست مدیر سایت
--------------------------------------------
*/

function ai_agent_fetch_knowledge_summary() {
    return ai_agent_api_request('GET', '/knowledge/summary');
}

function ai_agent_fetch_knowledge_documents() {
    return ai_agent_api_request('GET', '/knowledge/documents');
}

function ai_agent_create_knowledge_document($title, $content, $index_now = true) {
    return ai_agent_api_request('POST', '/knowledge/documents', array(
        'title'     => $title,
        'content'   => $content,
        'index_now' => (bool) $index_now,
    ), 30);
}

function ai_agent_delete_knowledge_document($document_id) {
    return ai_agent_api_request('DELETE', '/knowledge/documents/' . rawurlencode($document_id));
}

function ai_agent_reindex_knowledge_document($document_id) {
    return ai_agent_api_request('POST', '/knowledge/documents/' . rawurlencode($document_id) . '/reindex', array(), 30);
}

/*
آپلود یک فایل (اکسل، CSV، PDF، متن یا عکس) به‌عنوان دانش.

این یکی نمی‌تواند از ai_agent_api_request استفاده کند: اندپوینت
multipart/form-data می‌خواهد و آن تابع JSON می‌فرستد. بدنه‌ی multipart
دستی ساخته می‌شود چون wp_remote_request پشتیبانی داخلی برای آپلود فایل
ندارد.

استخراج متن سمت سرور انجام می‌شود، نه اینجا: منطق خواندن اکسل و PDF یک
جا می‌ماند و داشبورد دانی‌چت و افزونه دقیقاً یک نتیجه می‌گیرند.
*/
function ai_agent_upload_knowledge_file($file_path, $file_name, $title = '', $index_now = true) {

    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        return array('ok' => false, 'code' => 0, 'error' => 'اول توکن سایت را ذخیره کنید.');
    }

    if (!is_readable($file_path)) {
        return array('ok' => false, 'code' => 0, 'error' => 'فایل آپلودشده قابل خواندن نبود.');
    }

    $contents = file_get_contents($file_path);
    if ($contents === false) {
        return array('ok' => false, 'code' => 0, 'error' => 'خواندن فایل آپلودشده ناموفق بود.');
    }

    $boundary = wp_generate_password(24, false);
    $eol      = "\r\n";
    $payload  = '';

    if ($title !== '') {
        $payload .= '--' . $boundary . $eol;
        $payload .= 'Content-Disposition: form-data; name="title"' . $eol . $eol;
        $payload .= $title . $eol;
    }

    $payload .= '--' . $boundary . $eol;
    $payload .= 'Content-Disposition: form-data; name="index_now"' . $eol . $eol;
    $payload .= ($index_now ? 'true' : 'false') . $eol;

    $payload .= '--' . $boundary . $eol;
    $payload .= 'Content-Disposition: form-data; name="file"; filename="' . $file_name . '"' . $eol;
    $payload .= 'Content-Type: application/octet-stream' . $eol . $eol;
    $payload .= $contents . $eol;
    $payload .= '--' . $boundary . '--' . $eol;

    $response = wp_remote_post(ai_agent_api_base() . '/knowledge/documents/upload', array(
        // آپلود و استخراج متن با هم انجام می‌شوند، پس مهلت از یک درخواست
        // معمولی بیشتر است.
        'timeout' => 60,
        'headers' => array(
            'X-API-Key'    => $api_key,
            'Accept'       => 'application/json',
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ),
        'body' => $payload,
    ));

    if (is_wp_error($response)) {
        return array('ok' => false, 'code' => 0, 'error' => 'ارسال فایل به سرور دانی‌چت ناموفق بود.');
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code >= 200 && $code < 300) {
        return array('ok' => true, 'code' => $code, 'data' => is_array($data) ? $data : array());
    }

    return array('ok' => false, 'code' => $code, 'error' => ai_agent_api_error_text($data, $code));
}


/*
--------------------------------------------
پرسش‌وپاسخ‌های آماده
--------------------------------------------
*/

function ai_agent_fetch_qa_pairs() {
    return ai_agent_api_request('GET', '/knowledge/qa');
}

function ai_agent_create_qa_pair($question, $answer, $is_active = true) {
    return ai_agent_api_request('POST', '/knowledge/qa', array(
        'question'  => $question,
        'answer'    => $answer,
        'is_active' => (bool) $is_active,
        'index_now' => true,
    ), 30);
}

function ai_agent_update_qa_pair($qa_id, $fields) {
    $fields['index_now'] = true;
    return ai_agent_api_request('PATCH', '/knowledge/qa/' . rawurlencode($qa_id), $fields, 30);
}

function ai_agent_delete_qa_pair($qa_id) {
    return ai_agent_api_request('DELETE', '/knowledge/qa/' . rawurlencode($qa_id));
}

function ai_agent_reindex_knowledge_all() {
    // ایندکس دوباره‌ی همه‌چیز. زدن مکررش هزینه‌ای ندارد: سرور موردی را که
    // محتوایش عوض نشده رد می‌کند و دوباره embedding نمی‌گیرد.
    return ai_agent_api_request('POST', '/knowledge/reindex', array(), 60);
}


/*
--------------------------------------------
تبدیل صدا به متن
--------------------------------------------
*/

/*
ارسال فایل صوتی ضبط‌شده به سرور دانی‌چت و گرفتن متن آن.

مثل آپلود فایلِ دانش، بدنه‌ی multipart دستی ساخته می‌شود چون
wp_remote_post پشتیبانی داخلی برای آپلود فایل ندارد.

طول ضبط هم فرستاده می‌شود، ولی فقط به‌عنوان پشتیبان: هزینه بر اساس
طولی حساب می‌شود که خود سرویس تبدیل صوت گزارش می‌کند، نه عددی که
مرورگر می‌گوید.
*/
function ai_agent_transcribe_audio($file_path, $file_name, $duration_seconds = null) {

    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        return array('ok' => false, 'code' => 0, 'error' => 'دستیار هنوز به سرور وصل نشده است.');
    }

    if (!is_readable($file_path)) {
        return array('ok' => false, 'code' => 0, 'error' => 'فایل صوتی قابل خواندن نبود.');
    }

    $contents = file_get_contents($file_path);
    if ($contents === false || $contents === '') {
        return array('ok' => false, 'code' => 0, 'error' => 'فایل صوتی خالی بود.');
    }

    $boundary = wp_generate_password(24, false);
    $eol      = "\r\n";
    $payload  = '';

    if ($duration_seconds !== null) {
        $payload .= '--' . $boundary . $eol;
        $payload .= 'Content-Disposition: form-data; name="duration_seconds"' . $eol . $eol;
        $payload .= $duration_seconds . $eol;
    }

    $payload .= '--' . $boundary . $eol;
    $payload .= 'Content-Disposition: form-data; name="file"; filename="' . $file_name . '"' . $eol;
    $payload .= 'Content-Type: application/octet-stream' . $eol . $eol;
    $payload .= $contents . $eol;
    $payload .= '--' . $boundary . '--' . $eol;

    $response = wp_remote_post(ai_agent_api_base() . '/chat/transcribe', array(
        // تبدیل صوت به متن چند ثانیه طول می‌کشد؛ مهلت پیش‌فرض کوتاه است.
        'timeout' => 60,
        'headers' => array(
            'X-API-Key'    => $api_key,
            'Accept'       => 'application/json',
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ),
        'body' => $payload,
    ));

    if (is_wp_error($response)) {
        error_log('AI_AGENT_DEBUG transcribe WP_Error: ' . $response->get_error_message());
        return array('ok' => false, 'code' => 0, 'error' => 'ارتباط با سرور برای تبدیل صدا برقرار نشد.');
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $raw  = wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    if ($code >= 200 && $code < 300) {
        return array('ok' => true, 'code' => $code, 'data' => is_array($data) ? $data : array());
    }

    // نبود لاگ همین‌جا بود که این باگ را غیرقابل‌بررسی می‌کرد: کاربر همیشه
    // همان پیام عمومی «نمی‌توانم به سرویس پاسخ‌گویی وصل شوم» را می‌دید و
    // هیچ‌جا کد/بدنه‌ی واقعی پاسخ سرور ثبت نمی‌شد.
    error_log('AI_AGENT_DEBUG transcribe failed, HTTP ' . $code . ': ' . $raw);

    return array('ok' => false, 'code' => $code, 'error' => ai_agent_api_error_text($data, $code));
}


/*
--------------------------------------------
انتقال گفت‌وگو به پیام‌رسان
--------------------------------------------
*/

/** پیام‌رسان‌هایی که این سایت می‌تواند گفت‌وگو را به آن‌ها منتقل کند. */
function ai_agent_fetch_transfer_options() {
    return ai_agent_api_request('GET', '/chat/transfer-options', null, 10);
}

/** بستن گفت‌وگو در سایت و گرفتن کدی که کاربر در ربات وارد می‌کند. */
function ai_agent_transfer_session($session_id) {
    return ai_agent_api_request(
        'POST',
        '/chat/sessions/' . rawurlencode($session_id) . '/transfer',
        array(),
        20
    );
}
