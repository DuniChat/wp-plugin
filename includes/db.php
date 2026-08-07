<?php

if (!defined('ABSPATH')) exit;

/*
============================================
ساخت جداول دیتابیس هنگام فعال‌سازی پلاگین
============================================
*/

function ai_agent_install(){

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $table_synced   = $wpdb->prefix.'ai_agent_synced_items';

    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    // جدول ذخیره‌ی آی‌دی‌های سینک‌شده و تاریخ آخرین همگام‌سازی هر آیتم
    // این جدول برای جلوگیری از ارسال مجدد محتوایی که قبلاً به سرور فرستاده شده
    // استفاده می‌شود. در هر بار سینک، فقط محتوای جدید (آی‌دی‌های جدید) ارسال
    // می‌شود و محتوای حذف‌شده از وردپرس، به اندپوینت delete کال زده می‌شود.
    $sql3 = "CREATE TABLE {$table_synced} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(64) NOT NULL,
    content_type VARCHAR(32) NOT NULL,
    job_id VARCHAR(64) DEFAULT NULL,
    synced_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY source_type (source_id, content_type),
    KEY content_type (content_type)
    ) {$charset_collate};";

    dbDelta($sql3);


}

/*
============================================
شبکه ایمنی: اگر پلاگین قبلا فعال بوده و جداول
وجود نداشته باشند، بدون نیاز به غیرفعال/فعال کردن
دوباره پلاگین، جداول ساخته می‌شوند.
============================================
*/

function ai_agent_maybe_install(){

    global $wpdb;

    // بررسی وجود جدول آی‌دی‌های سینک‌شده؛ اگر نبود، آن را می‌سازیم
    $table_synced = $wpdb->prefix.'ai_agent_synced_items';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_synced}'") !== $table_synced) {
        ai_agent_install();
    }
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_synced}'") === $table_synced) {
    $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_synced} LIKE 'job_id'");
    if (empty($column_exists)) {
        ai_agent_install();
    }
}

}
add_action('admin_init', 'ai_agent_maybe_install');

/*
============================================
رمزنگاری / رمزگشایی و ذخیره‌سازی امن API Key

از openssl (روش AES-256-CBC) برای رمزنگاری استفاده می‌شود.
کلید رمزنگاری از AUTH_KEY وردپرس (تعریف‌شده در wp-config.php)
مشتق می‌شود، بنابراین منحصر به هر نصب وردپرس است و نیازی به
ذخیره‌ی کلید رمزنگاری در جای دیگری نیست.

مقدار رمزشده (base64) داخل جدول wp_options با کلید
'ai_agent_api_key' نگهداری می‌شود.
============================================
*/

// نام کلید در جدول wp_options
if (!defined('AI_AGENT_API_KEY_OPTION')) {
    define('AI_AGENT_API_KEY_OPTION', 'ai_agent_api_key');
}

// نام کوکی ذخیره‌ی session_id در مرورگر کاربر
if (!defined('AI_AGENT_SESSION_COOKIE')) {
    define('AI_AGENT_SESSION_COOKIE', 'ai_agent_session_id');
}
// طول عمر کوکی session_id (یک هفته)
if (!defined('AI_AGENT_SESSION_COOKIE_EXPIRE')) {
    define('AI_AGENT_SESSION_COOKIE_EXPIRE', defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 7 * DAY_IN_SECONDS);
}
// نکته: ثابت AI_AGENT_ESCALATED_COOKIE و کوکی ai_agent_escalated_session حذف شدند.
// از این پس از همان AI_AGENT_SESSION_COOKIE (ai_agent_session_id) استفاده می‌شود،
// چون مقدار هر دو یکسان بود. وضعیت پشتیبانی جلسه از طریق استعلام سرور
// (ai_agent_fetch_session_status) بررسی می‌شود، نه از طریق کوکی.

/*
حداکثر تعداد عکس‌های مجاز در هر پیام چت (قابلیت سنجاق).
این مقدار از سمت کلاینت (enqueue.php → ai_agent.max_images) و سرور
(ajax.php هنگام دریافت $_POST['images']) به‌صورت یکسان اعمال می‌شود
تا نهایتاً ۴ عکس در هر پیام قابل ارسال باشد. در صورت نیاز می‌توان
این مقدار را در wp-config.php با define('AI_AGENT_MAX_CHAT_IMAGES', n)
تغییر داد.
*/
if (!defined('AI_AGENT_MAX_CHAT_IMAGES')) {
    define('AI_AGENT_MAX_CHAT_IMAGES', 4);
}
/*
تولید یک کلید رمزنگاری ۳۲ بایتی (256 بیت) ثابت و مخصوص همین سایت
با استفاده از AUTH_KEY / AUTH_SALT وردپرس. اگر این ثابت‌ها به هر
دلیلی تعریف نشده باشند (حالت غیرمعمول)، یک fallback امن بر پایه
پسوند سایت استفاده می‌شود تا کد خطا ندهد.
*/
function ai_agent_get_encryption_key(){

    if (defined('AUTH_KEY') && AUTH_KEY !== '') {
        $secret = AUTH_KEY;
    } elseif (defined('AUTH_SALT') && AUTH_SALT !== '') {
        $secret = AUTH_SALT;
    } else {
        // fallback؛ در عمل تقریبا همیشه AUTH_KEY تعریف شده است
        $secret = 'ai-agent-fallback-secret-' . (defined('DB_NAME') ? DB_NAME : 'default');
    }

    // خروجی همیشه دقیقا ۳۲ بایت برای AES-256
    return hash('sha256', $secret, true);

}

/*
رمزنگاری یک رشته و بازگشت مقدار base64 شامل IV + متن رمزشده
*/
function ai_agent_encrypt($plain_text){

    if ($plain_text === '' || $plain_text === null) {
        return '';
    }

    $key = ai_agent_get_encryption_key();
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($iv_length);

    $encrypted = openssl_encrypt($plain_text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    if ($encrypted === false) {
        return '';
    }

    // IV به همراه متن رمزشده ذخیره می‌شود تا هنگام رمزگشایی در دسترس باشد
    return base64_encode($iv . $encrypted);

}

/*
رمزگشایی مقدار ذخیره‌شده (خروجی تابع ai_agent_encrypt)
*/
function ai_agent_decrypt($encrypted_text){

    if (empty($encrypted_text)) {
        return '';
    }

    $key = ai_agent_get_encryption_key();
    $data = base64_decode($encrypted_text, true);

    if ($data === false) {
        return '';
    }

    $iv_length = openssl_cipher_iv_length('aes-256-cbc');

    if (strlen($data) <= $iv_length) {
        return '';
    }

    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);

    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    return $decrypted !== false ? $decrypted : '';

}

/*
============================================
ذخیره‌ی امن API Key در wp_options (به‌صورت رمزشده)
این تابع باید هنگام کلیک روی دکمه‌ی «ذخیره تنظیمات» فراخوانی شود.

ورودی: کلید API خام (متن ساده که کاربر وارد کرده)
خروجی: true در صورت موفقیت، false در صورت خطا
============================================
*/
function ai_agent_save_api_key($api_key){

    $api_key = is_string($api_key) ? trim($api_key) : '';

    // اگر فیلد خالی فرستاده شده، کلید قبلی دست‌نخورده باقی می‌ماند
    // (برای جلوگیری از پاک شدن ناخواسته‌ی کلید هنگام ذخیره‌ی بقیه‌ی تنظیمات)
    if ($api_key === '') {
        return true;
    }

    $encrypted = ai_agent_encrypt($api_key);

    if ($encrypted === '') {
        return false;
    }

    return update_option(AI_AGENT_API_KEY_OPTION, $encrypted, false);

}

/*
============================================
دریافت API Key به‌صورت رمزگشایی‌شده (متن خام) جهت استفاده
در فراخوانی‌های API (مثلا در api.php)
============================================
*/
function ai_agent_get_api_key(){

    $encrypted = get_option(AI_AGENT_API_KEY_OPTION, '');

    if (empty($encrypted)) {
        return '';
    }

    return ai_agent_decrypt($encrypted);

}

/*
============================================
حذف کامل API Key ذخیره‌شده (در صورت نیاز، مثلا دکمه‌ی
"پاک کردن کلید" در پنل تنظیمات)
============================================
*/
function ai_agent_delete_api_key(){
    return delete_option(AI_AGENT_API_KEY_OPTION);
}



/*
============================================
دریافت یا تولید visitor_id و ذخیره‌ی آن در کوکی مرورگر

این تابع در هر بار بارگذاری صفحه فراخوانی می‌شود (از طریق enqueue.php).
اگر کوکی از قبل وجود داشته باشد، مقدار آن برمی‌گردد؛ در غیر این صورت
یک UUID جدید تولید شده و در کوکی مرورگر کاربر با طول عمر یک سال
ذخیره می‌شود.

نکته: کوکی به‌صورت HttpOnly=false تنظیم می‌شود تا JavaScript بتواند
مقدار آن را بخواند و در بدنه‌ی درخواست AJAX به سرور بفرستد.

خروجی: UUID معتبر visitor_id
============================================
*/
/*
============================================
خواندن session_id از کوکی مرورگر

این تابع فقط مقدار کوکی را می‌خواند و برمی‌گرداند.
session_id از پاسخ API دریافت و توسط ajax.php در کوکی ذخیره می‌شود.
============================================
*/
function ai_agent_get_or_set_session_id() {

    /*
    این تابع اکنون فقط مقدار کوکی را می‌خواند.
    session_id از پاسخ API دریافت و توسط ajax.php در کوکی ذخیره می‌شود.
    */

    if (isset($_COOKIE[AI_AGENT_SESSION_COOKIE])) {
        $session_id = sanitize_text_field($_COOKIE[AI_AGENT_SESSION_COOKIE]);
        if (!empty($session_id) && ai_agent_is_valid_uuid($session_id)) {
            return $session_id;
        }
    }

    return '';
}

/*
============================================
اعتبارسنجی ساده‌ی قالب UUID v4
============================================
*/
function ai_agent_is_valid_uuid($uuid) {

    if (!is_string($uuid) || empty($uuid)) {
        return false;
    }
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $uuid
    );
}


/* ============================================================
   ============================================================
   توابع مدیریت آی‌دی‌های سینک‌شده (Synced Items)
   ============================================================
   ============================================================

   این توابع برای پیگیری‌ی محتواهایی که قبلاً به سرور همگام‌سازی
   (https://dunichat.ir/api/v1/sync/content) ارسال شده‌اند استفاده می‌شوند.

   - جدول wp_ai_agent_synced_items شامل سه ستون اصلی است:
       source_id     : آی‌دی یکتای محتوا در وردپرس (post_id یا term_id)
       content_type  : نوع محتوا (post, page, product, product_category)
       synced_at     : تاریخ و ساعت آخرین باری که این آیتم سینک شده

   - تاریخ آخرین سینک کلی (last sync time) در wp_options با کلید
     'ai_agent_last_sync_time' ذخیره می‌شود.

   - در هر بار سینک، آی‌دی‌های فعلی محتوا با آی‌دی‌های ذخیره‌شده در جدول
     مقایسه می‌شوند:
       * آی‌دی‌های جدید → به /sync/content ارسال می‌شوند
       * آی‌دی‌های حذف‌شده (در جدول هستند اما در وردپرس نیستند) → به
         /sync/delete ارسال می‌شوند
       * آی‌دی‌های موجود → فقط تاریخ synced_at آن‌ها به‌روزرسانی می‌شود
============================================================ */


/*
============================================
دریافت نقشه‌ی آی‌دی‌های سینک‌شده به تفکیک نوع محتوا

خروجی: آرایه‌ای با کلیدهای نوع محتوا و مقدار آرایه‌ای از source_id ها
    مثال:
    array(
        'post'            => array('1', '5', '12'),
        'page'            => array('2', '7'),
        'product'         => array('45', '46'),
        'product_category'=> array('10', '11'),
    )

ورودی $content_types (اختیاری): اگر ارسال شود، فقط این نوع‌ها برگردانده می‌شوند
============================================
*/
function ai_agent_get_synced_items_map($content_types = array()) {

    global $wpdb;
    $table = $wpdb->prefix.'ai_agent_synced_items';

    // اگر جدول وجود نداشت (مثلا قبل از نصب کامل پلاگین)، خالی برمی‌گردد
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return array();
    }

    $sql = "SELECT source_id, content_type FROM {$table}";
    $args = array();

    if (!empty($content_types) && is_array($content_types)) {
        $placeholders = implode(',', array_fill(0, count($content_types), '%s'));
        $sql .= " WHERE content_type IN ({$placeholders})";
        $args = array_values($content_types);
    }

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

    $map = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $ct = $row->content_type;
            if (!isset($map[$ct])) {
                $map[$ct] = array();
            }
            $map[$ct][] = (string) $row->source_id;
        }
    }

    return $map;
}

/*
============================================
دریافت لیست تمام آی‌دی‌های سینک‌شده (بدون تفکیک نوع)

خروجی: آرایه‌ای از source_id ها به‌صورت رشته
============================================
*/
function ai_agent_get_all_synced_source_ids() {

    global $wpdb;
    $table = $wpdb->prefix.'ai_agent_synced_items';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return array();
    }

    $rows = $wpdb->get_col("SELECT source_id FROM {$table}");

    return is_array($rows) ? array_map('strval', $rows) : array();
}

/*
============================================
دریافت لیست تمام job_id های ذخیره‌شده در جدول synced_items
(برای استعلام وضعیت دسته‌ای از سرور)

خروجی: آرایه‌ای از job_id ها (فقط مقادیر غیرخالی)
============================================
*/
function ai_agent_get_all_synced_job_ids() {

    global $wpdb;
    $table = $wpdb->prefix.'ai_agent_synced_items';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return array();
    }

    $rows = $wpdb->get_col("SELECT job_id FROM {$table} WHERE job_id IS NOT NULL AND job_id != ''");

    return is_array($rows) ? array_map('strval', $rows) : array();
}

/*
============================================
ثبت یا به‌روزرسانی یک آیتم سینک‌شده در جدول

اگر آیتم از قبل وجود داشته باشد، فقط تاریخ synced_at به‌روزرسانی می‌شود
(REPLACE INTO با UNIQUE KEY باعث این رفتار می‌شود).

ورودی:
    $source_id    : آی‌دی محتوا در وردپرس (post_id یا term_id)
    $content_type : نوع محتوا (post, page, product, product_category)
============================================
*/
function ai_agent_mark_item_synced($source_id, $content_type, $job_id = null) {

    global $wpdb;
    $table = $wpdb->prefix.'ai_agent_synced_items';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return false;
    }

    // اگر job_id ارسال نشده (مثلا فقط داریم synced_at را رفرش می‌کنیم)،
    // مقدار قبلی را از دیتابیس می‌خوانیم تا با REPLACE INTO پاک نشود
    if ($job_id === null) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT job_id FROM {$table} WHERE source_id=%s AND content_type=%s",
            (string) $source_id,
            (string) $content_type
        ));
        $job_id = $existing !== null ? $existing : '';
    }

    return $wpdb->replace(
        $table,
        array(
            'source_id'    => (string) $source_id,
            'content_type' => (string) $content_type,
            'job_id'       => (string) $job_id,
            'synced_at'    => current_time('mysql'),
        ),
        array('%s', '%s', '%s', '%s')
    );
}

/*
============================================
ثبت دسته‌جمعی چند آیتم به‌عنوان سینک‌شده

ورودی: آرایه‌ای از آرایه‌های داخلی با کلیدهای source_id و content_type
    مثال:
    array(
        array('source_id' => '1', 'content_type' => 'post'),
        array('source_id' => '2', 'content_type' => 'page'),
    )
============================================
*/
function ai_agent_mark_items_synced_batch($items) {

    if (!is_array($items) || empty($items)) {
        return 0;
    }

    $count = 0;
    foreach ($items as $item) {
        if (!isset($item['source_id'], $item['content_type'])) {
            continue;
        }
        $job_id = isset($item['job_id']) ? $item['job_id'] : null;
        if (ai_agent_mark_item_synced($item['source_id'], $item['content_type'], $job_id)) {
            $count++;
        }
    }

    return $count;
}

/*
============================================
حذف یک آیتم سینک‌شده از جدول

این تابع زمانی استفاده می‌شود که محتوایی در وردپرس حذف شده و دیگر
وجود ندارد؛ آی‌دی آن از جدول سینک‌ها پاک می‌شود تا در سینک بعدی
دوباره به‌عنوان «حذف‌شده» شناسایی نشود.

ورودی:
    $source_id    : آی‌دی محتوای حذف‌شده
    $content_type : نوع محتوا
============================================
*/
function ai_agent_unmark_item_synced($source_id, $content_type) {

    global $wpdb;
    $table = $wpdb->prefix.'ai_agent_synced_items';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return false;
    }

    return $wpdb->delete(
        $table,
        array(
            'source_id'    => (string) $source_id,
            'content_type' => (string) $content_type,
        ),
        array('%s', '%s')
    );
}

/*
============================================
حذف دسته‌جمعی چند آیتم از جدول سینک‌ها

ورودی: آرایه‌ای از آرایه‌های داخلی با کلیدهای source_id و content_type
============================================
*/
function ai_agent_unmark_items_synced_batch($items) {

    if (!is_array($items) || empty($items)) {
        return 0;
    }

    $count = 0;
    foreach ($items as $item) {
        if (!isset($item['source_id'], $item['content_type'])) {
            continue;
        }
        if (ai_agent_unmark_item_synced($item['source_id'], $item['content_type'])) {
            $count++;
        }
    }

    return $count;
}

/*
============================================
پاک کردن کامل جدول آی‌دی‌های سینک‌شده

این تابع برای دکمه‌ی «سینک تمامی محتوا» استفاده می‌شود؛ قبل از
درج مجدد تمام آی‌دی‌ها، جدول خالی می‌شود تا حالت افزایشی به حالت
کامل تبدیل شود.
============================================
*/
function ai_agent_clear_all_synced_items() {

    global $wpdb;
    $table = $wpdb->prefix.'ai_agent_synced_items';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return false;
    }

    return $wpdb->query("TRUNCATE TABLE {$table}");
}

/*
============================================
دریافت تاریخ و زمان آخرین سینک موفق

این مقدار در wp_options با کلید 'ai_agent_last_sync_time' ذخیره می‌شود
و در صفحه‌ی تنظیمات به کاربر نمایش داده می‌شود.

خروجی: رشته‌ی تاریخ/زمان (فرمت mysql) یا رشته‌ی خالی اگر هنوز
سینکی انجام نشده باشد.
============================================
*/
function ai_agent_get_last_sync_time() {

    $value = get_option('ai_agent_last_sync_time', '');

    return $value ? (string) $value : '';
}

/*
============================================
به‌روزرسانی تاریخ و زمان آخرین سینک موفق

این تابع در پایان هر سینک موفق (افزایشی یا کامل) فراخوانی می‌شود.

ورودی: رشته‌ی تاریخ/زمان (اختیاری؛ اگر خالی باشد، زمان فعلی استفاده می‌شود)
============================================
*/
function ai_agent_update_last_sync_time($time = '') {

    if ($time === '') {
        $time = current_time('mysql');
    }

    update_option('ai_agent_last_sync_time', (string) $time, false);

    return $time;
}

/*
============================================
دریافت تاریخ و زمان آخرین سینک کامل (Sync All)

این مقدار جداگانه از سینک افزایشی در wp_options با کلید
'ai_agent_last_sync_all_time' ذخیره می‌شود تا کاربر ببیند کی آخرین بار
«سینک تمامی محتوا» را زده است.
============================================
*/
function ai_agent_get_last_sync_all_time() {

    $value = get_option('ai_agent_last_sync_all_time', '');

    return $value ? (string) $value : '';
}

/*
============================================
به‌روزرسانی تاریخ و زمان آخرین سینک کامل
============================================
*/
function ai_agent_update_last_sync_all_time($time = '') {

    if ($time === '') {
        $time = current_time('mysql');
    }

    update_option('ai_agent_last_sync_all_time', (string) $time, false);

    return $time;
}


