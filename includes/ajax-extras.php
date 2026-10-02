<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
============================================
هندلرهای AJAX تازه (مطابق مستندات openapi دانی‌چت):
    • ربات بله (ادمین)
    • تبدیل صدا به متن (بازدیدکننده)
    • انتقال گفت‌وگو به بله (بازدیدکننده)

هندلرهای ادمینی از یک نگهبان مشترک رد می‌شوند: توکن ربات دارایی حساس
سایت است، پس دسترسی manage_options و یک nonce معتبر — همان چیزی که
بقیه‌ی پنل هم می‌طلبد.

هندلرهای بازدیدکننده nonce ویجت چت را می‌گیرند و برای کاربر مهمان هم
بازند (nopriv)، چون بازدیدکننده‌ی یک فروشگاه لاگین نکرده. هیچ داده‌ای
هم بیرون نمی‌دهند که بازدیدکننده از قبل نداشته باشد: وضعیت اتصال ربات
سایت، و کدی برای گفت‌وگویی که نشستش را در همین مرورگر دارد.
============================================
*/

/**
 * دسترسی و nonce را با هم بررسی می‌کند و در صورت رد، پاسخ خطا می‌فرستد.
 * برگشتی ندارد چون wp_send_json_error خودش اجرا را تمام می‌کند.
 */
function ai_agent_extras_guard($action) {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, $action)) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبارسنجی درخواست ناموفق بود.'));
    }
}

/** پاسخ API را به پاسخ AJAX تبدیل می‌کند. */
function ai_agent_extras_respond($result) {
    if (!empty($result['ok'])) {
        wp_send_json_success($result['data']);
    }
    wp_send_json_error(array('message' => $result['error']));
}

/**
 * بررسی nonce ویجت چت برای هندلرهای بازدیدکننده.
 * برگشتی ندارد؛ در صورت رد، پاسخ خطا فرستاده و اجرا تمام می‌شود.
 */
function ai_agent_visitor_guard() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'ai_agent_chat_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبارسنجی درخواست ناموفق بود.'));
    }
}


/*
--------------------------------------------
ربات‌ها (ادمین)
--------------------------------------------
*/

/*
فهرست ربات‌های پیام‌رسان سایت.

مستندات: GET /bots → {'bot': BotResponse | null} یعنی فقط همین یک
ربات بله. اما JS پنل با آرایه‌ای از آیتم‌ها کار می‌کند (هر آیتم
platform دارد)؛ پس پاسخ سرور این‌جا به همان شکل نرمال می‌شود تا
سمت کلاینت برای اضافه‌شدن پیام‌رسان‌های بعدی هم آماده بماند.
*/
function ai_agent_bots_list_handler() {
    ai_agent_extras_guard('ai_agent_bots_nonce_action');

    $result = ai_agent_fetch_bot();
    if (empty($result['ok'])) {
        wp_send_json_error(array('message' => $result['error']));
    }

    $bot = isset($result['data']['bot']) && is_array($result['data']['bot'])
        ? $result['data']['bot']
        : null;

    $items = array();
    if ($bot !== null) {
        $bot['platform'] = isset($bot['platform']) && $bot['platform'] !== '' ? $bot['platform'] : 'bale';
        $items[] = $bot;
    }

    wp_send_json_success(array('items' => $items));
}
add_action('wp_ajax_ai_agent_bots_list', 'ai_agent_bots_list_handler');


function ai_agent_bots_save_handler() {
    ai_agent_extras_guard('ai_agent_bots_nonce_action');

    // فقط بله. سرورهای دانی‌چت داخل ایران‌اند و به api.telegram.org
    // دسترسی ندارند؛ ضمن اینکه مستندات هم فقط ربات بله را تعریف می‌کند.
    $platform = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : 'bale';
    if ($platform !== 'bale') {
        wp_send_json_error(array('message' => 'پیام‌رسان نامعتبر است.'));
    }

    /*
    اگر از قبل رباتی وصل است، ثبتِ توکنِ جدید رد می‌شود. کاربر باید
    اول «حذف ربات» را بزند تا رباتِ قبلی قطع شود، بعد توکنِ جدید
    را وارد کند. این قانون از دو مشکل جلوگیری می‌کند:
      ۱) تداخلِ دو توکن روی یک ربات (وبهوکِ قبلی هدر می‌رود یا
         دو مسیرِ هم‌زمان باز می‌ماند)
      ۲) جایگزینیِ ناخواسته‌ی رباتی که از قبل کار می‌کرده
    */
    $existing = ai_agent_fetch_bot();
    if (!empty($existing['ok'])) {
        $bot = isset($existing['data']['bot']) && is_array($existing['data']['bot']) ? $existing['data']['bot'] : null;
        if ($bot !== null) {
            wp_send_json_error(array(
                'message' => 'یک ربات بله از قبل وصل است. اول «حذف ربات» را بزنید تا رباتِ قبلی قطع شود، بعد توکنِ جدید را وارد و وصل کنید.',
            ));
        }
    }

    // توکن BotFather شامل «:» و کاراکترهای دیگری است که
    // sanitize_text_field دست‌نخورده رهایشان می‌کند، ولی فضاهای اضافی
    // ناشی از کپی‌وپیست را باید خودمان بگیریم — یک فاصله‌ی آخر توکن،
    // شایع‌ترین دلیل رد شدن آن است.
    $token = isset($_POST['token']) ? trim(sanitize_text_field(wp_unslash($_POST['token']))) : '';
    if ($token === '') {
        wp_send_json_error(array('message' => 'توکن ربات را وارد کنید.'));
    }

    $enabled = !isset($_POST['enabled']) || $_POST['enabled'] !== '0';

    ai_agent_extras_respond(ai_agent_save_bot($token, $enabled));
}
add_action('wp_ajax_ai_agent_bots_save', 'ai_agent_bots_save_handler');


function ai_agent_bots_delete_handler() {
    ai_agent_extras_guard('ai_agent_bots_nonce_action');

    $platform = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : 'bale';
    if ($platform !== 'bale') {
        wp_send_json_error(array('message' => 'پیام‌رسان نامعتبر است.'));
    }

    $result = ai_agent_delete_bot();
    // حذف موفق ۲۰۴ برمی‌گرداند، یعنی بدنه‌ای ندارد.
    if (!empty($result['ok'])) {
        wp_send_json_success(array('deleted' => true));
    }
    wp_send_json_error(array('message' => $result['error']));
}
add_action('wp_ajax_ai_agent_bots_delete', 'ai_agent_bots_delete_handler');


/*
--------------------------------------------
تبدیل صدا به متن — برای بازدیدکننده
--------------------------------------------

مرورگر، صدای ضبط‌شده را مثل قبل با multipart می‌فرستد (منطق فعلی
ویجت دست‌نخورده می‌ماند)؛ این‌جا فایل خوانده و base64 شده و طبق
مستندات به‌صورت JSON به اندپوینت POST /speech/transcribe می‌رود:
TranscribeRequest {audio_base64, format}.
*/

function ai_agent_transcribe_handler() {

    ai_agent_visitor_guard();

    if (empty($_FILES['audio']) || !isset($_FILES['audio']['tmp_name'])) {
        wp_send_json_error(array('message' => 'فایل صوتی دریافت نشد.'));
    }

    $file = $_FILES['audio'];
    if (!empty($file['error'])) {
        wp_send_json_error(array(
            'message' => 'آپلود فایل صوتی ناموفق بود (کد ' . intval($file['error']) . ').',
        ));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        wp_send_json_error(array('message' => 'فایل صوتی معتبر نیست.'));
    }

    /*
    فرمت فایل از پسوند نامی می‌آید که مرورگر موقع ساخت Blob گذاشته
    (voice.webm / voice.m4a / voice.ogg). فقط یکی از فرمت‌های
    مستندات پذیرفته می‌شود: wav، webm، ogg، mp3، m4a. سافاری
    audio/mp4 ضبط می‌کند که در مستندات m4a نام گرفته — هر دو به
    m4a نگاشت می‌شوند. هر چیز دیگری به webm می‌رسد، چون رایج‌ترین
    خروجی MediaRecorder همان است و سرور خودش خطای شفاف می‌دهد اگر
    فرمت واقعی چیز دیگری باشد.
    */
    $name = sanitize_file_name($file['name']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map  = array(
        'wav'  => 'wav',
        'webm' => 'webm',
        'ogg'  => 'ogg',
        'mp3'  => 'mp3',
        'mp4'  => 'm4a',
        'm4a'  => 'm4a',
    );
    $format = isset($map[$ext]) ? $map[$ext] : 'webm';

    $contents = file_get_contents($file['tmp_name']);
    if ($contents === false || $contents === '') {
        wp_send_json_error(array('message' => 'فایل صوتی خالی بود.'));
    }

    $audio_base64 = base64_encode($contents);
    if ($audio_base64 === '') {
        wp_send_json_error(array('message' => 'تبدیل فایل صوتی برای ارسال ناموفق بود.'));
    }

    // طول ضبطی که مرورگر می‌گوید، فقط برای لاگ: هزینه بر اساس طولی
    // حساب می‌شود که خود سرویس تبدیل صوت در پاسخ (duration_seconds)
    // گزارش می‌کند.
    $duration = isset($_POST['duration_seconds']) ? floatval($_POST['duration_seconds']) : 0;
    if ($duration > 0) {
        error_log('AI_AGENT_DEBUG transcribe browser_duration=' . round($duration, 1) . 's format=' . $format);
    }

    /*
    سقف طول صدای مستندات API: ۵ دقیقه. مرورگر هم همین‌قدر ضبط
    می‌کند (MAX_RECORDING_MS = 300000 در ai-agent.js) ولی اگر مقداری
    بلندتر از سقف از سمت کلاینت رسید (مثلاً نسخه‌ی قدیمیِ کش
    شده)، صدایش را قبل از فرستادن به سرور رد می‌کنیم تا سرور
    نگیردش و خطای شفاف بدهد.
    */
    if ($duration > 300) {
        wp_send_json_error(array('message' => 'صدای ضبط‌شده نباید بیشتر از ۵ دقیقه باشد.'));
    }

    /*
    سقف حجم فایل صوتی: ۵۰ مگابایت برای کل درخواست (طبق مستندات API).
    base64 حدود یک‌سوم به حجم اضافه می‌کند، پس اگر فایل خامِ آپلود
    بیشتر از ۳۷ مگابایت بود، نسخه‌ی base64 شده از سقف ۵۰ مگابایت
    رد می‌شود و سرور با 413 ردش می‌کند.
    */
    $audio_size = isset($file['size']) ? intval($file['size']) : 0;
    if ($audio_size > 37 * 1024 * 1024) {
        wp_send_json_error(array('message' => 'فایل صوتی خیلی بزرگ است. حداکثر ۵ دقیقه می‌توانید ضبط کنید.'));
    }

    $result = ai_agent_transcribe_audio($audio_base64, $format);

    if (empty($result['ok'])) {
        // نبود لاگ همین‌جا بود که خطاهای سرویس صوتی غیرقابل‌بررسی
        // می‌شد؛ کد/بدنه‌ی واقعی پاسخ سرور در لاگ PHP با پیشوند
        // AI_AGENT_DEBUG ثبت می‌شود و کاربر پیام شفاف سرور را می‌بیند.
        error_log('AI_AGENT_DEBUG transcribe failed: ' . $result['error']);
        wp_send_json_error(array('message' => $result['error']));
    }

    // فقط متن به مرورگر برمی‌گردد. هزینه‌ی تبدیل (cost_irr) به صاحب
    // سایت مربوط است و بازدیدکننده کاری با آن ندارد.
    wp_send_json_success(array(
        'text' => isset($result['data']['text']) ? (string) $result['data']['text'] : '',
    ));
}
add_action('wp_ajax_ai_agent_transcribe', 'ai_agent_transcribe_handler');
add_action('wp_ajax_nopriv_ai_agent_transcribe', 'ai_agent_transcribe_handler');


/*
--------------------------------------------
انتقال گفت‌وگو به بله — برای بازدیدکننده
--------------------------------------------
*/

/*
گزینه‌های انتقال: آیا ربات بله‌ای متصل و فعال است؟

مستندات: GET /chat/transfer-options → {available, bot_username,
bot_link}. برای اینکه سمت JS مثل قبل ساده بماند، پاسخ به فهرستی از
گزینه‌ها نرمال می‌شود: available=false یا هر خطای API یعنی
«گزینه‌ای نیست» و دکمه‌ی «ادامه در بله» در ویجت نشان داده نمی‌شود.
خطا به بازدیدکننده نشان داده نمی‌شود چون کاری از دستش برنمی‌آید.
*/
function ai_agent_transfer_options_handler() {

    ai_agent_visitor_guard();

    $result = ai_agent_fetch_transfer_options();
    if (empty($result['ok'])) {
        wp_send_json_success(array('options' => array()));
    }

    $data     = is_array($result['data']) ? $result['data'] : array();
    $options  = array();

    if (!empty($data['available'])) {
        $options[] = array(
            'platform'     => 'bale',
            'bot_username' => isset($data['bot_username']) ? (string) $data['bot_username'] : '',
            'bot_link'     => isset($data['bot_link']) ? (string) $data['bot_link'] : '',
        );
    }

    wp_send_json_success(array('options' => $options));
}
add_action('wp_ajax_ai_agent_transfer_options', 'ai_agent_transfer_options_handler');
add_action('wp_ajax_nopriv_ai_agent_transfer_options', 'ai_agent_transfer_options_handler');


/*
انتقال جلسه‌ی فعلی به بله و گرفتن کد ۶ رقمی یک‌بارمصرف.

مستندات: POST /chat/sessions/{session_id}/transfer → TransferResponse
{session_id, code, expires_at, bot_username, bot_link, message}.
پیامِ message متن آماده‌ی نمایش به بازدیدکننده است و مستقیم نشان
داده می‌شود؛ کد و نشانی ربات هم جداگانه در ویجت برجسته می‌شوند.

بعد از موفقیت، جلسه در متادیتای خودش (PATCH /metadata) به‌عنوان
«منتقل‌شده» علامت می‌خورد تا بعد از رفرش صفحه هم ویجت بسته بماند —
همان رفتاری که ref برای data.transferred داشت. این فراخوانی
best-effort است؛ خطایش مانع انتقال نمی‌شود.
*/
function ai_agent_transfer_session_handler() {

    ai_agent_visitor_guard();

    $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
    if ($session_id === '' || !ai_agent_is_valid_uuid($session_id)) {
        wp_send_json_error(array('message' => 'هنوز گفت‌وگویی شروع نشده است.'));
    }

    $result = ai_agent_transfer_session($session_id);
    if (empty($result['ok'])) {
        wp_send_json_error(array('message' => $result['error']));
    }

    ai_agent_mark_session_transferred($session_id);

    ai_agent_extras_respond($result);
}
add_action('wp_ajax_ai_agent_transfer_session', 'ai_agent_transfer_session_handler');
add_action('wp_ajax_nopriv_ai_agent_transfer_session', 'ai_agent_transfer_session_handler');
