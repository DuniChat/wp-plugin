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

    // ۴.۵) دریافت عکس‌های ارسالی کاربر (آرایه‌ای از data URL های base64)
    // هر آیتم باید با "data:image/" شروع شود تا فقط عکس پذیرفته باشد.
    // حداکثر AI_AGENT_MAX_CHAT_IMAGES عکس پذیرفته می‌شود (پیش‌فرض ۴).
    $max_images = defined('AI_AGENT_MAX_CHAT_IMAGES') ? intval(AI_AGENT_MAX_CHAT_IMAGES) : 4;
    if ($max_images < 1) {
        $max_images = 4;
    }
    $images = array();
    if (isset($_POST['images']) && is_array($_POST['images'])) {
        foreach ($_POST['images'] as $img) {
            if (!is_string($img)) {
                continue;
            }
            // فقط data URL های معتبر عکس پذیرفته می‌شوند
            if (strpos($img, 'data:image/') !== 0) {
                continue;
            }
            // محدودیت طول برای جلوگیری از payload های غیرعادی (۱۵ مگابایت)
            if (strlen($img) > 15 * 1024 * 1024) {
                continue;
            }
            $images[] = $img;
            if (count($images) >= $max_images) {
                break; // سقف تعداد عکس
            }
        }
    }

    // بررسی اینکه آیا این session قبلاً به پشتیبان انسانی منتقل شده
    $escalated_cookie_session = isset($_COOKIE[AI_AGENT_ESCALATED_COOKIE]) ? sanitize_text_field($_COOKIE[AI_AGENT_ESCALATED_COOKIE]) : '';
    $is_already_escalated = (!empty($session_id) && !empty($escalated_cookie_session) && hash_equals($escalated_cookie_session, $session_id));
    // اگر نه متن داریم و نه عکس، پیام خالی محسوب می‌شود
    if (empty($message) && empty($images)) {
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
    // قبل از ارسال به مرورگر، هر رفرنس با عکس نگاره اصلی محصول
    // (در صورت وجود) غنی‌سازی می‌شود تا فرانت‌اند بتواند گالری
    // تصاویر هایپرلینک‌دار را کنار پاسخ نمایش دهد.
    $on_references = function($references) {
        $enriched = ai_agent_enrich_references_with_images($references);
        ai_agent_sse_send(array(
            'type'       => 'references',
            'references' => $enriched,
        ));
        @flush();
    };
    // ۱۰) فراخوانی تابع استریم در api.php
    // آرایه‌ی images (data URL های base64) به تابع استریم پاس داده می‌شود
    // تا در بدنه‌ی JSON درخواست به /api/v1/chat/messages قرار گیرد.
    $result = ai_agent_call_api_stream($message, $session_id, $on_chunk, null, $on_error, $on_escalate, $on_references, $images);
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
هندلر AJAX دریافت موجودی کیف پول کاربر از سرور همگام‌سازی
(فقط ادمین — برای نمایش در صفحه‌ی تنظیمات افزونه)
============================================
*/
function ai_agent_get_wallet_balance_handler() {

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_wallet_balance_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبار‌سنجی درخواست ناموفق بود.'));
    }

    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'API Key تنظیم نشده است. لطفاً در صفحه‌ی تنظیمات کلید معتبر وارد کنید.'));
    }

    $result = ai_agent_fetch_wallet_balance();

    if ($result === false) {
        wp_send_json_error(array('message' => 'خطا در دریافت موجودی کیف پول از سرور.'));
    }

    wp_send_json_success(array('balance_irr' => $result['balance_irr']));
}
add_action('wp_ajax_ai_agent_get_wallet_balance', 'ai_agent_get_wallet_balance_handler');


/*
============================================
دریافت تاریخچه‌ی پیام‌های یک session از سرور
برای نمایش تاریخچه‌ی چت هنگام باز شدن مجدد ویجت

خروجی: { success: true, data: { messages: [...] } }
هر پیام شامل فیلدهای id, role, content, references, image_keys, created_at است.
image_keys آرایه‌ای از کلیدهای عکس است که کلاینت باید آن‌ها را به‌صورت lazy
و یکی‌یکی از اندپوینت ai_agent_get_media بگیرد.
============================================
*/
function ai_agent_get_history_handler() {

    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

    if (empty($session_id) || !ai_agent_is_valid_uuid($session_id)) {
        wp_send_json_success(array('messages' => array()));
    }

    $messages = ai_agent_fetch_chat_history($session_id);

    if ($messages === false) {
        wp_send_json_success(array('messages' => array()));
    }

    // غنی‌سازی رفرنس‌های هر پیام با تصویر نگاره اصلی محصول
    // (دقیقاً همان کاری که هنگام استریم زنده روی رویداد references انجام می‌شود،
    // چون سرور تاریخچه فقط title/url برمی‌گرداند و image ندارد)
    //
    // فیلد image_keys عمداً دست‌نخورده باقی می‌ماند تا کلاینت بتواند عکس‌های
    // مربوط به هر پیام را به‌صورت lazy و یکی‌یکی بارگذاری کند.
    if (is_array($messages)) {
        foreach ($messages as &$msg) {
            if (is_array($msg) && !empty($msg['references']) && is_array($msg['references'])) {
                $msg['references'] = ai_agent_enrich_references_with_images($msg['references']);
            }
            // تضمین اینکه image_keys همیشه یک آرایه‌ی معتبر است (حتی اگر API فرستاده نباشد)
            if (!is_array($msg) || !isset($msg['image_keys']) || !is_array($msg['image_keys'])) {
                if (is_array($msg)) {
                    $msg['image_keys'] = array();
                }
            }
        }
        unset($msg);
    }

    wp_send_json_success(array('messages' => $messages));
}
add_action('wp_ajax_ai_agent_get_history', 'ai_agent_get_history_handler');
add_action('wp_ajax_nopriv_ai_agent_get_history', 'ai_agent_get_history_handler');

/*
============================================
هندلر AJAX: دریافت یک عکس از سرور با استفاده از image_key

این هندلر به‌عنوان یک پروکسی سمت سرور عمل می‌کند تا کلاینت بتواند
عکس‌های پیام‌های چت را بدون افشای API Key مستقیماً در مرورگر بارگذاری
کند. کلاینت برای هر کلید در image_keys یک درخواست جداگانه (یکی‌یکی)
به این اندپوینت می‌فرستد.

اندپوینت هدف: GET https://dunichat.ir/api/v1/media/site/{key}
هدر: X-API-Key (همان کلید API کاربر که در دیتابیس ذخیره شده)

پارامترهای ورودی (POST یا GET):
    key : کلید عکس از image_keys

خروجی (JSON):
    success => true,  data => { data_url: "data:image/...;base64,...", content_type: "image/..." }
    success => false, data => { message: "..." }

توضیح امنیتی:
    این اندپوینت مانند ai_agent_get_history بدون وریفای nonce کار می‌کند،
    چون در سمت nopriv (ویجت سایت برای کاربران لاگین‌نشده) استفاده می‌شود.
    خودِ image_key به‌عنوان یک توکن یکبار‌مصرف/تصادفی عمل می‌کند و
    بدون داشتن آن، دسترسی به عکس ممکن نیست. کلید API واقعی هرگز در
    مرورگر افشا نمی‌شود.
============================================
*/
function ai_agent_get_media_handler() {

    // توجه: از wp_unslash برای پاکسازی magic quotes احتمالی استفاده می‌کنیم
    // تا اگر WordPress به‌طور خودکار backslash به ورودی اضافه کرده بود، حذف شود.
    // سپس sanitize_text_field تگ‌ها و کاراکترهای کنترلی را پاک می‌کند.
    // کاراکتر «/» در کلید عکس‌ها (مثل site_xxx/chat/yyy.jpg) معتبر است و
    // sanitize_text_field آن را دست‌نخورده باقی می‌گذارد.
    $raw_key = isset($_REQUEST['key']) ? wp_unslash($_REQUEST['key']) : '';
    $key = sanitize_text_field($raw_key);
    if ($key === '') {
        wp_send_json_error(array('message' => 'کلید عکس ارسال نشده است.'));
    }

    $result = ai_agent_fetch_media($key);

    if (is_array($result) && isset($result['status']) && $result['status'] === 'success') {
        wp_send_json_success(array(
            'data_url'     => $result['data_url'],
            'content_type' => isset($result['content_type']) ? $result['content_type'] : '',
        ));
    }

    $msg = (is_array($result) && isset($result['message'])) ? $result['message'] : 'خطا در دریافت عکس.';
    wp_send_json_error(array('message' => $msg));
}
add_action('wp_ajax_ai_agent_get_media', 'ai_agent_get_media_handler');
add_action('wp_ajax_nopriv_ai_agent_get_media', 'ai_agent_get_media_handler');

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

هر پیام شامل فیلد image_keys (آرایه‌ای از کلیدهای عکس) است که کلاینت
باید برای هر کدام یک درخواست جداگانه به ai_agent_get_media بفرستد تا
عکس‌ها به‌صورت lazy و یکی‌یکی در گالری همان پیام نمایش داده شوند.
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
        // تضمین اینکه هر پیام فیلد image_keys را به‌صورت آرایه دارد
        if (isset($result['items']) && is_array($result['items'])) {
            foreach ($result['items'] as &$msg) {
                if (is_array($msg) && (!isset($msg['image_keys']) || !is_array($msg['image_keys']))) {
                    $msg['image_keys'] = array();
                }
            }
            unset($msg);
        }
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