<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
============================================
هندلرهای AJAX تازه:
    • ربات‌های تلگرام و بله
    • اسناد و پرسش‌وپاسخ‌های دستی
    • انتقال گفت‌وگو به پیام‌رسان (این یکی برای بازدیدکننده است، نه ادمین)

همه‌ی هندلرهای ادمینی از یک نگهبان مشترک رد می‌شوند. توکن ربات و پایگاه
دانش هر دو دارایی‌های حساس سایت‌اند: دسترسی manage_options و یک nonce
معتبر، همان چیزی است که بقیه‌ی پنل هم می‌طلبد.
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


/*
--------------------------------------------
ربات‌ها
--------------------------------------------
*/

function ai_agent_bots_list_handler() {
    ai_agent_extras_guard('ai_agent_bots_nonce_action');
    ai_agent_extras_respond(ai_agent_fetch_bots());
}
add_action('wp_ajax_ai_agent_bots_list', 'ai_agent_bots_list_handler');


function ai_agent_bots_save_handler() {
    ai_agent_extras_guard('ai_agent_bots_nonce_action');

    $platform = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : '';
    if (!in_array($platform, array('telegram', 'bale'), true)) {
        wp_send_json_error(array('message' => 'پیام‌رسان نامعتبر است.'));
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

    ai_agent_extras_respond(ai_agent_save_bot($platform, $token, $enabled));
}
add_action('wp_ajax_ai_agent_bots_save', 'ai_agent_bots_save_handler');


function ai_agent_bots_delete_handler() {
    ai_agent_extras_guard('ai_agent_bots_nonce_action');

    $platform = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : '';
    if (!in_array($platform, array('telegram', 'bale'), true)) {
        wp_send_json_error(array('message' => 'پیام‌رسان نامعتبر است.'));
    }

    $result = ai_agent_delete_bot($platform);
    // حذف موفق ۲۰۴ برمی‌گرداند، یعنی بدنه‌ای ندارد.
    if (!empty($result['ok'])) {
        wp_send_json_success(array('deleted' => true));
    }
    wp_send_json_error(array('message' => $result['error']));
}
add_action('wp_ajax_ai_agent_bots_delete', 'ai_agent_bots_delete_handler');


/*
--------------------------------------------
پایگاه دانش دستی
--------------------------------------------
*/

function ai_agent_knowledge_list_handler() {
    ai_agent_extras_guard('ai_agent_knowledge_nonce_action');

    $documents = ai_agent_fetch_knowledge_documents();
    if (empty($documents['ok'])) {
        wp_send_json_error(array('message' => $documents['error']));
    }

    $qa = ai_agent_fetch_qa_pairs();
    if (empty($qa['ok'])) {
        wp_send_json_error(array('message' => $qa['error']));
    }

    $summary = ai_agent_fetch_knowledge_summary();

    wp_send_json_success(array(
        'documents' => isset($documents['data']['items']) ? $documents['data']['items'] : array(),
        'qa'        => isset($qa['data']['items']) ? $qa['data']['items'] : array(),
        'summary'   => !empty($summary['ok']) ? $summary['data'] : null,
    ));
}
add_action('wp_ajax_ai_agent_knowledge_list', 'ai_agent_knowledge_list_handler');


function ai_agent_knowledge_add_text_handler() {
    ai_agent_extras_guard('ai_agent_knowledge_nonce_action');

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    // متن سند با wp_kses_post پاک نمی‌شود: این متن هیچ‌وقت به‌عنوان HTML
    // رندر نمی‌شود، فقط چانک و embed می‌شود، و حذف نویسه‌ها فقط دانشی را
    // که مدیر سایت عمداً وارد کرده ناقص می‌کند.
    $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';

    if (trim($title) === '') {
        wp_send_json_error(array('message' => 'عنوان سند را وارد کنید.'));
    }
    if (trim($content) === '') {
        wp_send_json_error(array('message' => 'متن سند خالی است.'));
    }

    ai_agent_extras_respond(ai_agent_create_knowledge_document($title, $content, true));
}
add_action('wp_ajax_ai_agent_knowledge_add_text', 'ai_agent_knowledge_add_text_handler');


function ai_agent_knowledge_upload_handler() {
    ai_agent_extras_guard('ai_agent_knowledge_nonce_action');

    if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
        wp_send_json_error(array('message' => 'فایلی انتخاب نشده است.'));
    }

    $file = $_FILES['file'];
    if (!empty($file['error'])) {
        // خطاهای آپلود PHP معمولاً یعنی فایل از سقف upload_max_filesize
        // خود وردپرس بزرگ‌تر بوده؛ گفتنش خیلی مفیدتر از «آپلود ناموفق» است.
        wp_send_json_error(array(
            'message' => 'آپلود فایل ناموفق بود (کد ' . intval($file['error']) . '). '
                       . 'ممکن است حجم فایل از سقف مجاز سرور بیشتر باشد.',
        ));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        wp_send_json_error(array('message' => 'فایل آپلودشده معتبر نیست.'));
    }

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $name  = sanitize_file_name($file['name']);

    ai_agent_extras_respond(
        ai_agent_upload_knowledge_file($file['tmp_name'], $name, $title, true)
    );
}
add_action('wp_ajax_ai_agent_knowledge_upload', 'ai_agent_knowledge_upload_handler');


function ai_agent_knowledge_delete_handler() {
    ai_agent_extras_guard('ai_agent_knowledge_nonce_action');

    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    if ($id === '') {
        wp_send_json_error(array('message' => 'شناسه‌ی سند ارسال نشده است.'));
    }

    $result = ai_agent_delete_knowledge_document($id);
    if (!empty($result['ok'])) {
        wp_send_json_success(array('deleted' => true));
    }
    wp_send_json_error(array('message' => $result['error']));
}
add_action('wp_ajax_ai_agent_knowledge_delete', 'ai_agent_knowledge_delete_handler');


function ai_agent_knowledge_reindex_handler() {
    ai_agent_extras_guard('ai_agent_knowledge_nonce_action');

    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    if ($id !== '') {
        ai_agent_extras_respond(ai_agent_reindex_knowledge_document($id));
    }
    ai_agent_extras_respond(ai_agent_reindex_knowledge_all());
}
add_action('wp_ajax_ai_agent_knowledge_reindex', 'ai_agent_knowledge_reindex_handler');


/*
--------------------------------------------
پرسش‌وپاسخ
--------------------------------------------
*/

function ai_agent_qa_save_handler() {
    ai_agent_extras_guard('ai_agent_knowledge_nonce_action');

    $question = isset($_POST['question']) ? sanitize_text_field(wp_unslash($_POST['question'])) : '';
    $answer   = isset($_POST['answer']) ? wp_unslash($_POST['answer']) : '';
    $id       = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';

    if (trim($question) === '' || trim($answer) === '') {
        wp_send_json_error(array('message' => 'هم پرسش و هم پاسخ باید پر باشند.'));
    }

    if ($id !== '') {
        ai_agent_extras_respond(ai_agent_update_qa_pair($id, array(
            'question' => $question,
            'answer'   => $answer,
        )));
    }

    ai_agent_extras_respond(ai_agent_create_qa_pair($question, $answer, true));
}
add_action('wp_ajax_ai_agent_qa_save', 'ai_agent_qa_save_handler');


function ai_agent_qa_toggle_handler() {
    ai_agent_extras_guard('ai_agent_knowledge_nonce_action');

    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    if ($id === '') {
        wp_send_json_error(array('message' => 'شناسه‌ی پرسش‌وپاسخ ارسال نشده است.'));
    }

    $active = isset($_POST['is_active']) && $_POST['is_active'] === '1';
    ai_agent_extras_respond(ai_agent_update_qa_pair($id, array('is_active' => $active)));
}
add_action('wp_ajax_ai_agent_qa_toggle', 'ai_agent_qa_toggle_handler');


function ai_agent_qa_delete_handler() {
    ai_agent_extras_guard('ai_agent_knowledge_nonce_action');

    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    if ($id === '') {
        wp_send_json_error(array('message' => 'شناسه‌ی پرسش‌وپاسخ ارسال نشده است.'));
    }

    $result = ai_agent_delete_qa_pair($id);
    if (!empty($result['ok'])) {
        wp_send_json_success(array('deleted' => true));
    }
    wp_send_json_error(array('message' => $result['error']));
}
add_action('wp_ajax_ai_agent_qa_delete', 'ai_agent_qa_delete_handler');


/*
--------------------------------------------
انتقال گفت‌وگو به پیام‌رسان — از سمت بازدیدکننده
--------------------------------------------

این دو، برخلاف بقیه‌ی این فایل، برای بازدیدکننده‌ی سایت‌اند و نه ادمین،
پس nonce آن‌ها همان nonce ویجت چت است. هیچ داده‌ای هم بیرون نمی‌دهند که
بازدیدکننده از قبل نداشته باشد: فهرست ربات‌های عمومی سایت، و کدی برای
گفت‌وگویی که نشستش را در همین مرورگر دارد.
*/

function ai_agent_transfer_options_handler() {

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'ai_agent_chat_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبارسنجی درخواست ناموفق بود.'));
    }

    $result = ai_agent_fetch_transfer_options();
    if (empty($result['ok'])) {
        // ویجت در این حالت فقط دکمه را نشان نمی‌دهد؛ خطایی به بازدیدکننده
        // نشان داده نمی‌شود چون کاری از دستش برنمی‌آید.
        wp_send_json_success(array('options' => array()));
    }

    wp_send_json_success(array(
        'options' => isset($result['data']['options']) ? $result['data']['options'] : array(),
    ));
}
add_action('wp_ajax_ai_agent_transfer_options', 'ai_agent_transfer_options_handler');
add_action('wp_ajax_nopriv_ai_agent_transfer_options', 'ai_agent_transfer_options_handler');


function ai_agent_transfer_session_handler() {

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'ai_agent_chat_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبارسنجی درخواست ناموفق بود.'));
    }

    $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
    if ($session_id === '') {
        wp_send_json_error(array('message' => 'هنوز گفت‌وگویی شروع نشده است.'));
    }

    ai_agent_extras_respond(ai_agent_transfer_session($session_id));
}
add_action('wp_ajax_ai_agent_transfer_session', 'ai_agent_transfer_session_handler');
add_action('wp_ajax_nopriv_ai_agent_transfer_session', 'ai_agent_transfer_session_handler');
