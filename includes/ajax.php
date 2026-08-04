<?php

if (!defined('ABSPATH')) exit;

/*
============================================
پردازش چت هوش مصنوعی به‌صورت استریم (SSE)

این هندلر به‌جای wp_send_json_success از فرمت Server-Sent Events
استفاده می‌کند تا پاسخ هوش مصنوعی تکه به تکه به مرورگر کاربر
ارسال شود. مرورگر با استفاده از fetch + ReadableStream این رویدادها
را دریافت و به‌صورت زنده نمایش می‌دهد.

فرمت رویدادهای ارسالی به مرورگر:
    data: {"type":"chunk","content":"sa"}\n\n
    data: {"type":"chunk","content":"lam"}\n\n
    ...
    data: {"type":"done","chat_id":123}\n\n

در صورت خطا (timeout اولین پاسخ یا خطای ارتباطی):
    data: {"type":"error","message":"ارتباط با سرور برقرار نشد."}\n\n
============================================
*/
function ai_agent_chat() {
//عدم بافر
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }

    if (!headers_sent()) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        // جلوگیری از بافر کردن توسط nginx
        header('X-Accel-Buffering: no');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    // ۲) غیرفعال کردن تمام سطح‌های output buffering وردپرس
    //    تا هر chunk بلافاصله به مرورگر flush شود.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    // ۳) حذف محدودیت زمان اجرای PHP برای استریم طولانی
    @set_time_limit(0);

// ۴) سanitize ورودی‌ها
    $message    = isset($_POST['message'])    ? sanitize_text_field($_POST['message'])    : '';
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

    // بررسی اینکه آیا این session قبلاً به پشتیبان انسانی منتقل شده
    $escalated_cookie_session = isset($_COOKIE[AI_AGENT_ESCALATED_COOKIE]) ? sanitize_text_field($_COOKIE[AI_AGENT_ESCALATED_COOKIE]) : '';
    $is_already_escalated = (!empty($session_id) && !empty($escalated_cookie_session) && hash_equals($escalated_cookie_session, $session_id));
    if (empty($message)) {
        ai_agent_sse_send(array(
            'type'    => 'error',
            'message' => 'پیام خالی است.',
        ));
        @flush();
        die();
    }

    // اگر session_id از سمت کلاینت ارسال نشد یا معتبر نبود، بدون session_id ادامه می‌دهیم
    // API با عدم وجود session-id header، یک session جدید مۋ‌سازد و session_id را در پاسخ برمی‌گرداند.

    // ۸) Callback برای هر چانک محتوای دریافتی از API بالادستی
    $on_chunk = function($content, $raw_chunk) {
        ai_agent_sse_send(array(
            'type'    => 'chunk',
            'content' => $content,
        ));
        @flush();
    };

    // ۹) Callback در صورت خطا (timeout اولین پاسخ، خطای شبکه، ...)
    $on_error = function($message) {
        ai_agent_sse_send(array(
            'type'    => 'error',
            'message' => $message,
        ));
        @flush();
    };

    // ۹.۵) Callback برای حالت انتقال به پشتیبان انسانی (رویداد escalate)
    // در این حالت مدل هیچ متنی تولید نمی‌کند؛ فقط دلیل انتقال اعلام می‌شود
    // و بلافاصله (بدون منتظر ماندن برای done) به مرورگر اطلاع‌رسانی می‌کنیم.
    $on_escalate = function($reason, $conversation_id, $api_session_id = null) use (&$session_id) {
        // به‌محض دریافت رویداد escalate، کوکی را (در صورت امکان) ست می‌کنیم
        $sid_to_store = !empty($api_session_id) ? $api_session_id : $session_id;
        if (!empty($sid_to_store) && !headers_sent()) {
            setcookie(
                AI_AGENT_ESCALATED_COOKIE,
                $sid_to_store,
                array(
                    'expires'  => time() + AI_AGENT_SESSION_COOKIE_EXPIRE,
                    'path'     => '/',
                    'httponly' => false,
                    'samesite' => 'Lax',
                )
            );
        }

        ai_agent_sse_send(array(
            'type'            => 'escalate',
            'reason'          => $reason,
            'conversation_id' => $conversation_id,
        ));
        @flush();
    };

    // ۹.۶) Callback برای رویداد references (لیست محصولات مرتبط)
    $on_references = function($references) {
        ai_agent_sse_send(array(
            'type'       => 'references',
            'references' => $references,
        ));
        @flush();
    };
    // ۱۰) فراخوانی تابع استریم در api.php
    $result = ai_agent_call_api_stream($message, $session_id, $on_chunk, null, $on_error, $on_escalate, $on_references);
    //DEBUG
    error_log('AI_AGENT_DEBUG result: ' . print_r($result, true));
    // ۱۱) در صورت موفقیت، ذخیره‌ی کامل پاسخ در دیتابیس و ارسال رویداد done
    if (isset($result['status']) && $result['status'] === 'success') {
        // اگر API یک session_id جدید برگرداند، آن را در کوکی ذخیره و به JS ارسال مۋ‌کنیم
        if (!empty($result['session_id'])) {
            $session_id = $result['session_id'];
            // ذخیره در کوکی مرورگر (عمر یک هفته)
            if (!headers_sent()) {
                setcookie(
                    AI_AGENT_SESSION_COOKIE,
                    $session_id,
                    array(
                        'expires'  => time() + AI_AGENT_SESSION_COOKIE_EXPIRE,
                        'path'     => '/',
                        'httponly' => false,
                        'samesite' => 'Lax',
                    )
                );
            }
            // ارسال session_id به JS عبر SSE تا در کوکی ذخیره شود
            ai_agent_sse_send(array(
                'type'       => 'session_init',
                'session_id' => $session_id,
            ));
            @flush();
        }

        $full_content = isset($result['full_content']) ? $result['full_content'] : '';
        $is_escalate  = !empty($result['escalate']);

        // اگر escalate همین الان اتفاق افتاد یا این session از قبل escalate شده بود
        // (کاربر منتظر پاسخ پشتیبان انسانی است)، خالی بودن پاسخ طبیعی است و خطا نیست
        if (!$is_escalate && !$is_already_escalated && trim($full_content) === '') {
            ai_agent_sse_send(array(
                'type'    => 'error',
                'message' => 'پاسخی از سرور دریافت نشد. لطفاً مجدداً تلاش کنید.',
            ));
            @flush();
            die();
        }

        // تلاش مجدد برای ست‌کردن کوکی escalate اگر هنوز ست نشده باشد
        if (($is_escalate || $is_already_escalated) && !empty($session_id) && !headers_sent()) {
            setcookie(
                AI_AGENT_ESCALATED_COOKIE,
                $session_id,
                array(
                    'expires'  => time() + AI_AGENT_SESSION_COOKIE_EXPIRE,
                    'path'     => '/',
                    'httponly' => false,
                    'samesite' => 'Lax',
                )
            );
        }

        ai_agent_sse_send(array(
            'type' => 'done',
        ));
        @flush();
    }

    // پایان پاسخ
    die();
}
add_action('wp_ajax_ai_agent_chat', 'ai_agent_chat');
add_action('wp_ajax_nopriv_ai_agent_chat', 'ai_agent_chat');

/*
============================================
ارسال یک رویداد SSE به مرورگر

فرمت استاندارد SSE:
    data: <payload>\n\n

پارامتر: آرایه‌ای که به JSON تبدیل و در فیلد data قرار می‌گیرد.
============================================
*/
function ai_agent_sse_send($data) {
    echo 'data: ' . wp_json_encode($data) . "\n\n";
}

/*
============================================
سرچ / لیست مدل‌های هوش مصنوعی برای پنل تنظیمات (فقط ادمین)
============================================
*/
function ai_agent_search_models_handler() {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'ai_agent_models_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبار‌سنجی درخواست ناموفق بود.'));
    }

    $q     = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $limit = $limit > 0 ? $limit : 10;

    $models = ai_agent_fetch_models($q, $limit);

    if ($models === false) {
        wp_send_json_error(array('message' => 'خطا در دریافت لیست مدل‌ها از سرور.'));
    }

    wp_send_json_success(array('models' => $models));
}
add_action('wp_ajax_ai_agent_search_models', 'ai_agent_search_models_handler');


/*
============================================
دریافت تاریخچه‌ی پیام‌های یک session از سرور
برای نمایش تاریخچه‌ی چت هنگام باز شدن مجدد ویجت
============================================
*/
function ai_agent_get_history_handler() {

    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

    if (empty($session_id) || !ai_agent_is_valid_uuid($session_id)) {
        wp_send_json_success(array('messages' => array()));
    }

    $messages = ai_agent_fetch_chat_history($session_id);

    // در صورت خطا (مثلاً session جدید و بدون تاریخچه) به‌جای خطا، لیست خالی می‌فرستیم
    // تا چت‌باکس همچنان پیام خوش‌آمدگویی پیش‌فرض را نشان دهد
    if ($messages === false) {
        wp_send_json_success(array('messages' => array()));
    }

    wp_send_json_success(array('messages' => $messages));
}
add_action('wp_ajax_ai_agent_get_history', 'ai_agent_get_history_handler');
add_action('wp_ajax_nopriv_ai_agent_get_history', 'ai_agent_get_history_handler');

/*
============================================
هندلر AJAX: دریافت لیست جلسات چت از سرور
برای نمایش در تب تاریخچه تنظیمات (فقط ادمین)
============================================
*/
function ai_agent_get_chat_sessions_handler() {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'ai_agent_chat_sessions_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبار‌سنجی درخواست ناموفق بود.'));
    }

    $page          = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $page_size     = isset($_GET['page_size']) ? intval($_GET['page_size']) : 10;
    $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';

    $result = ai_agent_fetch_chat_sessions($page, $page_size, $status_filter);

    if ($result['status'] === 'success') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}
add_action('wp_ajax_ai_agent_get_chat_sessions', 'ai_agent_get_chat_sessions_handler');

/*
============================================
هندلر AJAX: دریافت پیام‌های یک جلسه چت از سرور
برای نمایش در آکاردئون تب تاریخچه تنظیمات (فقط ادمین)
============================================
*/
function ai_agent_get_session_messages_handler() {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'ai_agent_chat_sessions_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبار‌سنجی درخواست ناموفق بود.'));
    }

    $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
    $page       = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $page_size  = isset($_GET['page_size']) ? intval($_GET['page_size']) : 10;

    if (empty($session_id)) {
        wp_send_json_error(array('message' => 'شناسه‌ی جلسه ارسال نشده است.'));
    }

    $result = ai_agent_fetch_session_messages($session_id, true, $page, $page_size);

    if ($result['status'] === 'success') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}
add_action('wp_ajax_ai_agent_get_session_messages', 'ai_agent_get_session_messages_handler');

/*
============================================
هندلر AJAX: ارسال پاسخ دستی پشتیبان انسانی به یک جلسه‌ی چت
(فقط ادمین — برای جلسات «در انتظار پشتیبان» یا «پشتیبان»)
============================================
*/
function ai_agent_session_reply_handler() {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_chat_sessions_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبار‌سنجی درخواست ناموفق بود.'));
    }

    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    $message    = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

    if (empty($session_id)) {
        wp_send_json_error(array('message' => 'شناسه‌ی جلسه ارسال نشده است.'));
    }
    if (trim($message) === '') {
        wp_send_json_error(array('message' => 'متن پیام نمی‌تواند خالی باشد.'));
    }

    $result = ai_agent_send_session_reply($session_id, $message);

    if ($result['status'] === 'success') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}
add_action('wp_ajax_ai_agent_session_reply', 'ai_agent_session_reply_handler');

/*
============================================
هندلر AJAX: پایان دادن به یک جلسه‌ی چت توسط پشتیبان انسانی
(فقط ادمین — برای جلسات «در انتظار پشتیبان» یا «پشتیبان»)
============================================
*/
function ai_agent_session_close_handler() {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_chat_sessions_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبار‌سنجی درخواست ناموفق بود.'));
    }

    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

    if (empty($session_id)) {
        wp_send_json_error(array('message' => 'شناسه‌ی جلسه ارسال نشده است.'));
    }

    $result = ai_agent_close_session($session_id);

    if ($result['status'] === 'success') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}
add_action('wp_ajax_ai_agent_session_close', 'ai_agent_session_close_handler');