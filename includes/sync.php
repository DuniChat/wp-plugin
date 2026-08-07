<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
================================================================
سیستم همگام‌سازی محتوا با سرور اختصاصی (https://dunichat.ir)

دو اندپوینت اصلی:
  ۱) POST /api/v1/sync/content
     ارسال محتوای جدید (Posts / Pages / Products / Product Categories)
     با هدر X-API-Key و بدنه‌ی:
       { "items": [ { source_id, content_type, title, content, url, status, images } ] }
     که در آن images یک آرایه‌ی base64 از عکس‌های هر آیتم است (حداکثر ۴ عکس).
     برای جلوگیری از سنگین شدن یک درخواست، آیتم‌ها در صف‌های ۱۰تایی
     (در api.php) به سرور ارسال می‌شوند.

  ۲) POST /api/v1/sync/delete
     اطلاع‌دادن به سرور درباره‌ی محتوای حذف‌شده از وردپرس
     با هدر X-API-Key و بدنه‌ی:
       { "items": [ { source_id, content_type } ] }

دو نوع سینک:
  الف) سینک افزایشی (دکمه‌ی «همگام‌سازی اطلاعات (Sync Now)»):
      فقط محتوای جدید (آی‌دی‌هایی که قبلاً سینک نشده‌اند) به /sync/content
      ارسال می‌شود و محتوای حذف‌شده به /sync/delete کال زده می‌شود.

  ب) سینک کامل (دکمه‌ی «سینک تمامی محتوا»):
      بدون توجه به آی‌دی‌های قدیمی، تمام محتوای تیک‌خورده به /sync/content
      ارسال می‌شود و جدول آی‌دی‌های سینک‌شده از نو پر می‌شود.

جدول wp_ai_agent_synced_items برای پیگیری آی‌دی‌های سینک‌شده استفاده
می‌شود (تعریف شده در db.php). تاریخ آخرین سینک در wp_options با کلید
'ai_agent_last_sync_time' ذخیره و در پنل تنظیمات نمایش داده می‌شود.
================================================================
*/


/*
================================================================
ثبت اکشن‌های AJAX
================================================================
*/

// سینک افزایشی: فقط محتوای جدید ارسال و محتوای حذف‌شده گزارش می‌شود
add_action('wp_ajax_ai_agent_sync_data', 'ai_agent_sync_data_handler');

// سینک کامل: تمام محتوا از ابتدا ارسال می‌شود (بدون توجه به سینک قبلی)
add_action('wp_ajax_ai_agent_sync_all_data', 'ai_agent_sync_all_data_handler');

// سینک تصاویر: تمام محتوا به‌همراه تصاویرشان ارسال می‌شود (مستقل از تیک «سینک تصاویر»)
add_action('wp_ajax_ai_agent_sync_images_data', 'ai_agent_sync_images_data_handler');


// استعلام وضعیت دسته‌ای job_id های ذخیره‌شده در دیتابیس
add_action('wp_ajax_ai_agent_check_sync_status', 'ai_agent_check_sync_status_handler');

/*
================================================================
هندلر AJAX دکمه‌ی «استعلام وضعیت»

تمام job_id های ذخیره‌شده در جدول wp_ai_agent_synced_items را
می‌خواند و به اندپوینت /sync/content/status/batch ارسال می‌کند
تا وضعیت پردازش هر آیتم (queued, processing, completed, failed,
not_found) از سرور دریافت شود. خروجی برای رسم نمودار در پیشخوان
به فرانت‌اند برگردانده می‌شود.
================================================================
*/
function ai_agent_check_sync_status_handler() {

    // ۱. بررسی دسترسی
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => 'شما دسترسی کافی برای انجام این عملیات را ندارید.'
        ));
    }

    // ۲. بررسی nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_sync_status_nonce_action')) {
        wp_send_json_error(array(
            'message' => 'خطای امنیتی! اعتبارسنجی درخواست ناموفق بود.'
        ));
    }

    // ۳. بررسی API Key
    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        wp_send_json_error(array(
            'message' => 'API Key تنظیم نشده است. لطفاً در صفحه‌ی تنظیمات کلید معتبر وارد کنید.'
        ));
    }

    // ۴. خواندن تمام job_id های ذخیره‌شده
    $job_ids = ai_agent_get_all_synced_job_ids();

    if (empty($job_ids)) {
        wp_send_json_error(array(
            'message' => 'هیچ job_id ای در دیتابیس یافت نشد. ابتدا از دکمه‌ی «همگام‌سازی اطلاعات» استفاده کنید.'
        ));
    }

    // ۵. استعلام وضعیت از سرور
    $result = ai_agent_fetch_sync_status_batch($job_ids);

    if ($result['status'] !== 'success') {
        wp_send_json_error(array(
            'message' => $result['message']
        ));
    }

    wp_send_json_success(array(
        'results' => $result['results'],
        'summary' => $result['summary'],
    ));
}

/*
================================================================
هندلر اصلی: سینک افزایشی (Sync Now)

مراحل:
  ۱) بررسی دسترسی کاربر و nonce
  ۲) بررسی تنظیمات (sync_types) و API Key
  ۳) جمع‌آوری تمام محتوای فعلی وردپرس (طبق تیک‌های کاربر)
  ۴) دریافت نقشه‌ی آی‌دی‌های سینک‌شده از جدول synced_items
  ۵) مقایسه‌ی محتوای فعلی با سینک‌شده‌ها:
       * جدیدها → به /sync/content ارسال می‌شوند
       * حذف‌شده‌ها → به /sync/delete ارسال می‌شوند
       * موجودها → فقط تاریخ synced_at به‌روزرسانی می‌شود
  ۶) به‌روزرسانی جدول synced_items و تاریخ آخرین سینک
  ۷) بازگرداندن نتیجه‌ی دقیق به فرانت‌اند (تعداد جدید/حذف‌شده)
================================================================
*/
function ai_agent_sync_data_handler() {

    // ۱. بررسی دسترسی
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => 'شما دسترسی کافی برای انجام این عملیات را ندارید.'
        ));
    }

    // ۲. بررسی nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_sync_nonce_action')) {
        wp_send_json_error(array(
            'message' => 'خطای امنیتی! اعتبارسنجی درخواست ناموفق بود.'
        ));
    }

    // ۳. بررسی API Key
    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        wp_send_json_error(array(
            'message' => 'API Key تنظیم نشده است. لطفاً در صفحه‌ی تنظیمات کلید معتبر وارد کنید.'
        ));
    }

    // ۴. بررسی انتخاب نوع‌های محتوا توسط کاربر
    $settings = ai_agent_get_settings();
    $sync_types = isset($settings['sync_types']) ? $settings['sync_types'] : array();

    if (empty($sync_types)) {
        wp_send_json_error(array(
            'message' => 'لطفاً ابتدا حداقل یک منبع داده را تیک زده و ذخیره کنید.'
        ));
    }

    // خواندن پرچم سینک تصاویر از تنظیمات (controlled by allowed_statuses['image'] = allow-image|deny-image)
    $sync_images_enabled = !empty($settings['sync_images']);

    // ۵. جمع‌آوری تمام محتوای فعلی مطابق با تیک‌های کاربر
    $current_items = ai_agent_collect_sync_items($sync_types);

    if (empty($current_items)) {
        wp_send_json_error(array(
            'message' => 'هیچ داده‌ای متناسب با فیلترهای انتخابی شما یافت نشد.'
        ));
    }

    /*
    ============================================
    اگر تیک «سینک تصاویر» نخورده باشد، عکس‌ها را از آیتم‌ها حذف می‌کنیم
    تا فقط محتوای متنی به سرور ارسال شود. این رفتار با مقدار
    allow-image / deny-image در allowed_statuses مطابقت دارد.
    ============================================
    */
    if (!$sync_images_enabled) {
        foreach ($current_items as &$item_ref) {
            $item_ref['images'] = array();
        }
        unset($item_ref);
    }

    // ۶. دریافت نقشه‌ی آی‌دی‌های سینک‌شده از دیتابیس
    $synced_map = ai_agent_get_synced_items_map();

    // ۷. تفکیک محتوا به سه گروه: جدید، موجود، حذف‌شده
    $new_items      = array();  // در وردپرس هست، اما در جدول سینک نیست
    $existing_items = array();  // در هر دو هست (فقط تاریخ به‌روزرسانی می‌شود)
    $deleted_items  = array();  // در جدول سینک هست، اما در وردپرس حذف شده

    foreach ($current_items as $item) {
        $sid = (string) $item['source_id'];
        $ct  = (string) $item['content_type'];

        if (isset($synced_map[$ct]) && in_array($sid, $synced_map[$ct], true)) {
            $existing_items[] = $item;
        } else {
            $new_items[] = $item;
        }
    }

    // یافتن آیتم‌های حذف‌شده: در synced_map هستند اما در current_items نیستند
    $current_lookup = array();
    foreach ($current_items as $item) {
        $key = $item['content_type'] . '::' . $item['source_id'];
        $current_lookup[$key] = true;
    }

    foreach ($synced_map as $ct => $sids) {
        foreach ($sids as $sid) {
            $key = $ct . '::' . $sid;
            if (!isset($current_lookup[$key])) {
                $deleted_items[] = array(
                    'source_id'    => $sid,
                    'content_type' => $ct,
                );
            }
        }
    }

    // ۸. ارسال محتوای جدید به /sync/content
    $new_sent_count = 0;
    $content_error = '';

if (!empty($new_items)) {
    $content_result = ai_agent_push_sync_content($new_items);

    if ($content_result['status'] === 'success' || $content_result['status'] === 'partial') {
        $new_sent_count = intval($content_result['sent_count']);

        // ساخت نقشه‌ی source_id::content_type => job_id از پاسخ سرور
        $results_map = array();
        foreach ($content_result['results'] as $r) {
            $key = $r['content_type'] . '::' . $r['source_id'];
            $results_map[$key] = $r['job_id'];
        }

        // فقط آیتم‌هایی که واقعاً در پاسخ سرور بودند را synced علامت بزن
        $synced_batch = array();
        foreach ($new_items as $item) {
            $key = $item['content_type'] . '::' . $item['source_id'];
            if (isset($results_map[$key])) {
                $synced_batch[] = array(
                    'source_id'    => $item['source_id'],
                    'content_type' => $item['content_type'],
                    'job_id'       => $results_map[$key],
                );
            }
        }
        ai_agent_mark_items_synced_batch($synced_batch);

        if ($content_result['status'] === 'partial') {
            $content_error = $content_result['message'];
        }
    } else {
        $content_error = $content_result['message'];
    }
}

    // ۹. ارسال درخواست حذف برای محتوای حذف‌شده به /sync/delete
    $deleted_sent_count = 0;
    $delete_error = '';

    if (!empty($deleted_items)) {
        $delete_result = ai_agent_push_sync_delete($deleted_items);

        if ($delete_result['status'] === 'success') {
            // تمام درخواست‌های حذف موفق بودند → همه را از جدول پاک می‌کنیم
            $deleted_sent_count = intval($delete_result['deleted_count']);
            ai_agent_unmark_items_synced_batch($deleted_items);
        } elseif ($delete_result['status'] === 'partial') {
            // برخی دسته‌های حذف موفق بودند، برخی خطا داشتند
            // برای اطمینان از ارسال مجدد درخواست‌های ناموفق در سینک بعدی،
            // در این حالت هیچ آیتمی را از جدول پاک نمی‌کنیم (محتاطانه).
            $deleted_sent_count = intval($delete_result['deleted_count']);
            $delete_error = $delete_result['message'];
        } else {
            $delete_error = $delete_result['message'];
        }
    }

    // ۱۰. به‌روزرسانی تاریخ synced_at برای آیتم‌های موجود (تغییری در سرور ندهیم)
    foreach ($existing_items as $item) {
        ai_agent_mark_item_synced($item['source_id'], $item['content_type']);
    }

    // ۱۱. به‌روزرسانی تاریخ آخرین سینک
    $sync_time = ai_agent_update_last_sync_time();

    // ۱۲. ساخت پیام خلاصه برای نمایش به کاربر
    $summary_parts = array();

    if ($new_sent_count > 0) {
        $summary_parts[] = $new_sent_count . ' مورد جدید اضافه شد';
    }

    if ($deleted_sent_count > 0) {
        $summary_parts[] = $deleted_sent_count . ' مورد حذف شد';
    }

    $total_current = count($current_items);

    if (empty($summary_parts)) {
        if ($content_error !== '' || $delete_error !== '') {
            $errors = array_filter(array($content_error, $delete_error));
            wp_send_json_error(array(
                'message'         => implode(' | ', $errors),
                'new_count'       => 0,
                'deleted_count'   => 0,
                'total_count'     => $total_current,
                'last_sync_time'  => $sync_time,
            ));
        }

        $message = 'هیچ محتوای جدیدی برای ارسال یافت نشد. تمام ' . $total_current . ' مورد فعلی قبلاً سینک شده‌اند و تاریخ آن‌ها به‌روزرسانی شد.';
    } else {
        $message = implode(' و ', $summary_parts) . '. (مجموع محتوای فعلی: ' . $total_current . ' مورد)';

        if ($content_error !== '' || $delete_error !== '') {
            $errors = array_filter(array($content_error, $delete_error));
            $message .= ' — توجه: ' . implode(' | ', $errors);
        }
    }

    wp_send_json_success(array(
        'message'        => $message,
        'new_count'      => $new_sent_count,
        'deleted_count'  => $deleted_sent_count,
        'total_count'    => $total_current,
        'last_sync_time' => $sync_time,
        'sync_type'      => 'incremental',
    ));
}


/*
================================================================
هندلر سینک کامل (Sync All)

این هندلر بدون توجه به سینک قبلی، تمام محتوای تیک‌خورده را از ابتدا
به /sync/content ارسال می‌کند. جدول synced_items پاک شده و دوباره با
تمام آی‌دی‌های فعلی پر می‌شود.

نکته: این حالت برای زمانی است که کاربر می‌خواهد مطمئن شود تمام محتوا
دوباره در سرور همگام‌سازی ثبت شده است (مثلاً بعد از تغییر ساختار
محتوا یا مشکلات قبلی).
================================================================
*/
function ai_agent_sync_all_data_handler() {

    // ۱. بررسی دسترسی
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => 'شما دسترسی کافی برای انجام این عملیات را ندارید.'
        ));
    }

    // ۲. بررسی nonce (از یک اکشن مجزا استفاده می‌کنیم تا جدا از سینک معمولی باشد)
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_sync_all_nonce_action')) {
        wp_send_json_error(array(
            'message' => 'خطای امنیتی! اعتبارسنجی درخواست ناموفق بود.'
        ));
    }

    // ۳. بررسی API Key
    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        wp_send_json_error(array(
            'message' => 'API Key تنظیم نشده است. لطفاً در صفحه‌ی تنظیمات کلید معتبر وارد کنید.'
        ));
    }

    // ۴. بررسی انتخاب نوع‌های محتوا
    $settings = ai_agent_get_settings();
    $sync_types = isset($settings['sync_types']) ? $settings['sync_types'] : array();

    if (empty($sync_types)) {
        wp_send_json_error(array(
            'message' => 'لطفاً ابتدا حداقل یک منبع داده را تیک زده و ذخیره کنید.'
        ));
    }

    // خواندن پرچم سینک تصاویر از تنظیمات (controlled by allowed_statuses['image'] = allow-image|deny-image)
    $sync_images_enabled = !empty($settings['sync_images']);

    // ۵. جمع‌آوری تمام محتوای فعلی
    $current_items = ai_agent_collect_sync_items($sync_types);

    if (empty($current_items)) {
        wp_send_json_error(array(
            'message' => 'هیچ داده‌ای متناسب با فیلترهای انتخابی شما یافت نشد.'
        ));
    }

    /*
    ============================================
    اگر تیک «سینک تصاویر» نخورده باشد، عکس‌ها را از آیتم‌ها حذف می‌کنیم
    تا فقط محتوای متنی به سرور ارسال شود. این رفتار با مقدار
    allow-image / deny-image در allowed_statuses مطابقت دارد.
    ============================================
    */
    if (!$sync_images_enabled) {
        foreach ($current_items as &$item_ref) {
            $item_ref['images'] = array();
        }
        unset($item_ref);
    }

    // ۶. ارسال تمام محتوا به /sync/content (بدون مقایسه با سینک قبلی)
    $content_result = ai_agent_push_sync_content($current_items);

    if ($content_result['status'] === 'error') {
        wp_send_json_error(array(
            'message'        => $content_result['message'],
            'new_count'      => 0,
            'deleted_count'  => 0,
            'total_count'    => count($current_items),
            'last_sync_time' => ai_agent_get_last_sync_all_time(),
        ));
    }

    $sent_count = intval($content_result['sent_count']);

    if ($content_result['status'] === 'success' || $content_result['status'] === 'partial') {
        $results_map = array();
        foreach ($content_result['results'] as $r) {
            $key = $r['content_type'] . '::' . $r['source_id'];
            $results_map[$key] = $r['job_id'];
        }

        if ($content_result['status'] === 'success') {
            // فقط در موفقیت کامل، جدول را پاک و از نو با job_id ها پر می‌کنیم
            ai_agent_clear_all_synced_items();
            $synced_batch = array();
            foreach ($current_items as $item) {
                $key = $item['content_type'] . '::' . $item['source_id'];
                if (isset($results_map[$key])) {
                    $synced_batch[] = array(
                        'source_id'    => $item['source_id'],
                        'content_type' => $item['content_type'],
                        'job_id'       => $results_map[$key],
                    );
                }
            }
            ai_agent_mark_items_synced_batch($synced_batch);
        }
    // در حالت partial، جدول را پاک نمی‌کنیم تا آیتم‌های قبلی حفظ شوند
    // و در سینک بعدی افزایشی، فقط آیتم‌های جدید ارسال شوند.

    // ۸. به‌روزرسانی تاریخ آخرین سینک کامل
    $sync_time = ai_agent_update_last_sync_all_time();
    ai_agent_update_last_sync_time($sync_time); // سینک کلی را هم به‌روز می‌کنیم

    // ۹. ساخت پیام خلاصه
    $total = count($current_items);

    if ($content_result['status'] === 'partial') {
        $message = $sent_count . ' از ' . $total . ' مورد با موفقیت ارسال شد. (' . $content_result['message'] . ')';
    } else {
        $message = 'سینک کامل با موفقیت انجام شد. مجموع ' . $sent_count . ' مورد به سرور ارسال شد.';
    }

    wp_send_json_success(array(
        'message'        => $message,
        'new_count'      => $sent_count,
        'deleted_count'  => 0,
        'total_count'    => $total,
        'last_sync_time' => $sync_time,
        'sync_type'      => 'full',
    ));
}
}

/*
================================================================
هندلر اختصاصی «همگام‌سازی تصاویر» (Sync Images)

این هندلر مستقل از وضعیت تیک «سینک تصاویر»، تمام محتوای تیک‌خورده
را به‌همراه تصاویرشان (حداکثر ۴ عکس base64 برای هر آیتم) به‌صورت کامل
به /sync/content ارسال می‌کند. کاربرد اصلی این دکمه:

  - کاربر قبلاً بدون تیک «سینک تصاویر» سینک کرده و حالا می‌خواهد
    تصاویر محتوا را هم به سرور بفرستد.
  - یا کاربر می‌خواهد صرفاً تصاویر را به‌همراه متن مجدداً ارسال کند
    (مثلاً بعد از تغییر عکس‌های محصولات).

تفاوت با «سینک تمامی محتوا»:
  - در «سینک تمامی محتوا»، ارسال تصاویر بستگی به تیک «سینک تصاویر»
    دارد (اگر تیک نخورده باشد، عکس‌ها حذف می‌شوند).
  - در «همگام‌سازی تصاویر»، عکس‌ها همیشه ارسال می‌شوند.

مراحل:
  ۱) بررسی دسترسی کاربر و nonce (اکشن مجزا)
  ۲) بررسی تنظیمات (sync_types) و API Key
  ۳) جمع‌آوری تمام محتوای فعلی وردپرس (طبق تیک‌های کاربر) با تصاویر
  ۴) ارسال به /sync/content (با تصاویر)
  ۵) به‌روزرسانی جدول synced_items و تاریخ آخرین سینک
  ۶) بازگرداندن نتیجه‌ی دقیق به فرانت‌اند
================================================================
*/
function ai_agent_sync_images_data_handler() {

    // ۱. بررسی دسترسی
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => 'شما دسترسی کافی برای انجام این عملیات را ندارید.'
        ));
    }

    // ۲. بررسی nonce (اکشن مجزا برای دکمه‌ی «همگام‌سازی تصاویر»)
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_sync_images_nonce_action')) {
        wp_send_json_error(array(
            'message' => 'خطای امنیتی! اعتبارسنجی درخواست ناموفق بود.'
        ));
    }

    // ۳. بررسی API Key
    $api_key = ai_agent_get_api_key();
    if (empty($api_key)) {
        wp_send_json_error(array(
            'message' => 'API Key تنظیم نشده است. لطفاً در صفحه‌ی تنظیمات کلید معتبر وارد کنید.'
        ));
    }

    // ۴. بررسی انتخاب نوع‌های محتوا
    $settings = ai_agent_get_settings();
    $sync_types = isset($settings['sync_types']) ? $settings['sync_types'] : array();

    if (empty($sync_types)) {
        wp_send_json_error(array(
            'message' => 'لطفاً ابتدا حداقل یک منبع داده را تیک زده و ذخیره کنید.'
        ));
    }

    // ۵. جمع‌آوری تمام محتوای فعلی (این دکمه همیشه تصاویر را شامل می‌شود)
    $current_items = ai_agent_collect_sync_items($sync_types);

    if (empty($current_items)) {
        wp_send_json_error(array(
            'message' => 'هیچ داده‌ای متناسب با فیلترهای انتخابی شما یافت نشد.'
        ));
    }

    /*
    ============================================
    نکته: برخلاف Sync Now و Sync All، در اینجا عمداً بررسی
    sync_images را انجام نمی‌دهیم؛ چون این دکمه مخصوص ارسال
    تصاویر است و باید همیشه تصاویر را شامل شود. تصاویر از قبل
    توسط ai_agent_collect_sync_items به‌صورت base64 پر شده‌اند.
    ============================================
    */

    // ۶. ارسال تمام محتوا به‌همراه تصاویر به /sync/content
    $content_result = ai_agent_push_sync_content($current_items);

    if ($content_result['status'] === 'error') {
        wp_send_json_error(array(
            'message'        => $content_result['message'],
            'new_count'      => 0,
            'total_count'    => count($current_items),
            'last_sync_time' => ai_agent_get_last_sync_time(),
        ));
    }

    $sent_count = intval($content_result['sent_count']);

    if ($content_result['status'] === 'success' || $content_result['status'] === 'partial') {
        $results_map = array();
        foreach ($content_result['results'] as $r) {
            $key = $r['content_type'] . '::' . $r['source_id'];
            $results_map[$key] = $r['job_id'];
        }

        if ($content_result['status'] === 'success') {
            // در موفقیت کامل، جدول را پاک و از نو با job_id ها پر می‌کنیم
            ai_agent_clear_all_synced_items();
            $synced_batch = array();
            foreach ($current_items as $item) {
                $key = $item['content_type'] . '::' . $item['source_id'];
                if (isset($results_map[$key])) {
                    $synced_batch[] = array(
                        'source_id'    => $item['source_id'],
                        'content_type' => $item['content_type'],
                        'job_id'       => $results_map[$key],
                    );
                }
            }
            ai_agent_mark_items_synced_batch($synced_batch);
        }
        // در حالت partial، جدول را پاک نمی‌کنیم تا آیتم‌های قبلی حفظ شوند

        // ۷. به‌روزرسانی تاریخ آخرین سینک (هر دو: افزایشی و کامل)
        $sync_time = ai_agent_update_last_sync_all_time();
        ai_agent_update_last_sync_time($sync_time);

        // ۸. ساخت پیام خلاصه
        $total = count($current_items);

        if ($content_result['status'] === 'partial') {
            $message = $sent_count . ' از ' . $total . ' مورد به‌همراه تصاویر با موفقیت ارسال شد. (' . $content_result['message'] . ')';
        } else {
            $message = 'سینک تصاویر با موفقیت انجام شد. مجموع ' . $sent_count . ' مورد به‌همراه تصاویر به سرور ارسال شد.';
        }

        wp_send_json_success(array(
            'message'        => $message,
            'new_count'      => $sent_count,
            'deleted_count'  => 0,
            'total_count'    => $total,
            'last_sync_time' => $sync_time,
            'sync_type'      => 'images',
        ));
    }
}

/*
================================================================
جمع‌آوری تمام محتوای فعلی وردپرس مطابق با تیک‌های کاربر

این تابع مرکزی است که هم در سینک افزایشی و هم در سینک کامل استفاده
می‌شود. خروجی آن آرایه‌ای از آیتم‌ها با ساختار دقیقاً مطابق بدنه‌ی
درخواست /sync/content است:
    array(
        array(
            'source_id'    => '123',
            'content_type' => 'post',            // post | page | product | product_category
            'title'        => 'عنوان محتوا',
            'content'      => 'متن کامل محتوا',
            'url'          => 'https://example.com/...',
            'status'       => 'publish',         // وضعیت انتشار
            'images'       => array('base64...', 'base64...'),  // حداکثر ۴ عکس base64
        ),
        ...
    )

ورودی $sync_types: آرایه‌ای از کلیدهای داخلی افزونه
    (posts, pages, products, product_cats)
================================================================
*/
function ai_agent_collect_sync_items($sync_types) {

    if (!is_array($sync_types) || empty($sync_types)) {
        return array();
    }

    $items = array();
    $post_types_to_fetch = array();

    // نگاشت post_type وردپرس به content_type مورد انتظار API
    $post_type_to_content_type = array(
        'post'    => 'post',
        'page'    => 'page',
        'product' => 'product',
    );

    // ۱. تعیین post_type هایی که باید کوئری زده شوند
    if (in_array('posts', $sync_types, true)) {
        $post_types_to_fetch[] = 'post';
    }
    if (in_array('pages', $sync_types, true)) {
        $post_types_to_fetch[] = 'page';
    }
    if (in_array('products', $sync_types, true) && class_exists('WooCommerce')) {
        $post_types_to_fetch[] = 'product';
    }

    // ۲. کوئری و استخراج پست‌ها
    if (!empty($post_types_to_fetch)) {

        $args = array(
            'post_type'      => $post_types_to_fetch,
            'post_status'    => 'publish', // فقط محتوای منتشرشده (سرور فقط این وضعیت را می‌پذیرد)
            'posts_per_page' => -1,    // استخراج تمام آیتم‌ها
            'fields'         => 'ids', // فقط آی‌دی می‌خواهیم تا حافظه کمتر مصرف شود
        );

        $post_ids = get_posts($args);

        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {

                $post = get_post($post_id);
                if (!$post) {
                    continue;
                }

                $post_type = $post->post_type;
                $content_type = isset($post_type_to_content_type[$post_type])
                    ? $post_type_to_content_type[$post_type]
                    : $post_type;

                // محتوا را بدون تگ‌های HTML می‌گیریم تا تمیزتر برای سرور باشد
                $content = wp_strip_all_tags($post->post_content);
                $content = trim($content);

                $title = get_the_title($post);
                $title = $title !== '' ? trim($title) : '';
                $permalink = get_permalink($post);
                $permalink = is_string($permalink) ? trim($permalink) : '';

                // رد کردن آیتم‌هایی که source_id یا title یا content خالی دارند
                // تا کل بچ خطای 422 نخورد
                if (empty($post->ID) || $title === '' || $content === '') {
                    continue;
                }

                // استخراج عکس‌های پست/محصول به‌صورت base64 (حداکثر ۴ عکس)
                // شامل عکس شاخص، گالری محصول و تصاویر پیوست‌شده
                $images = ai_agent_collect_post_images_base64($post->ID);

                $items[] = array(
                    'source_id'    => (string) $post->ID,
                    'content_type' => (string) $content_type,
                    'title'        => $title,
                    'content'      => $content,
                    'url'          => $permalink !== '' ? $permalink : home_url('/'),
                    'status'       => (string) $post->post_status,
                    'images'       => $images,
                );
            }
        }
    }

    // ۳. استخراج دسته‌بندی محصولات (Product Categories)
    if (in_array('product_cats', $sync_types, true) && class_exists('WooCommerce')) {

        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ));

        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {

                $description = $term->description ? $term->description : 'بدون توضیح';
                $term_link   = get_term_link($term);
                $term_link_str = (is_string($term_link) && !is_wp_error($term_link)) ? trim($term_link) : '';

                $term_name = $term->name ? trim($term->name) : '';
                $desc_trim = trim($description);

                // رد کردن دسته‌هایی بدون نام یا آی‌دی (تا 422 نخوریم)
                if (empty($term->term_id) || $term_name === '') {
                    continue;
                }

                // استخراج عکس شاخص دسته‌بندی محصول (WooCommerce) به base64
                $images = ai_agent_collect_term_images_base64($term->term_id);

                $items[] = array(
                    'source_id'    => (string) $term->term_id,
                    'content_type' => 'product_category',
                    'title'        => $term_name,
                    'content'      => $desc_trim !== '' ? $desc_trim : 'بدون محتوا',
                    'url'          => $term_link_str !== '' ? $term_link_str : home_url('/'),
                    'status'       => 'publish',
                    'images'       => $images,
                );
            }
        }
    }

    return $items;
}

/*
============================================
دریافت مسیر فیزیکی فایل یک attachment با اندازه‌ی مشخص

این تابع برای استخراج عکس‌ها به‌صورت base64 و ارسال به API
همگام‌سازی استفاده می‌شود. مسیر فیزیکی فایل روی دیسک لازم است
تا بتوانیم با file_get_contents محتوای باینری آن را بخوانیم.

ورودی:
    $attachment_id : آی‌دی رسانه‌ی وردپرس
    $size          : نام اندازه‌ی وردپرس (thumbnail, medium, large, full)

خروجی: مسیر فیزیکی فایل روی دیسک یا رشته‌ی خالی در صورت نبود فایل

نکته: برای $size = 'full' از خود فایل اصلی استفاده می‌شود؛
       برای سایر اندازه‌ها نسخه‌ی تغییر اندازه‌یافته، و در صورت
       نبودِ آن، fallback به فایل اصلی.
============================================
*/
function ai_agent_get_attachment_image_path($attachment_id, $size = 'large') {

    $attachment_id = absint($attachment_id);
    if (empty($attachment_id)) {
        return '';
    }

    // حالت full: مستقیم از فایل اصلی
    if ($size === 'full') {
        $path = get_attached_file($attachment_id);
        if ($path && file_exists($path) && is_readable($path)) {
            return $path;
        }
        return '';
    }

    // حالت‌های thumbnail / medium / large: از نسخه‌ی resize شده
    $info = image_get_intermediate_size($attachment_id, $size);
    if (!empty($info['path'])) {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['basedir'])) {
            $full_path = $uploads['basedir'] . '/' . $info['path'];
            if (file_exists($full_path) && is_readable($full_path)) {
                return $full_path;
            }
        }
        // ممکن است path خودش absolute باشد
        if (file_exists($info['path']) && is_readable($info['path'])) {
            return $info['path'];
        }
    }

    // fallback به فایل اصلی
    $path = get_attached_file($attachment_id);
    if ($path && file_exists($path) && is_readable($path)) {
        return $path;
    }

    return '';
}

/*
============================================
خواندن محتوای یک عکس از روی attachment_id و تبدیل به base64

ورودی:
    $attachment_id : آی‌دی رسانه‌ی وردپرس
    $size          : اندازه‌ی دلخواه وردپرس (پیش‌فرض: large)

خروجی: رشته‌ی base64 (بدون data: prefix) یا رشته‌ی خالی اگر فایل
       خوانده نشود. از raw base64 استفاده می‌کنیم چون API انتظار
       دارد هر عنصر images یک رشته‌ی base64 خالص باشد.
============================================
*/
function ai_agent_attachment_to_base64($attachment_id, $size = 'large') {

    $attachment_id = absint($attachment_id);
    if (empty($attachment_id)) {
        return '';
    }

    // فقط فایل‌های تصویری مجازند
    $mime = get_post_mime_type($attachment_id);
    if ($mime && strpos($mime, 'image/') !== 0) {
        return '';
    }

    $path = ai_agent_get_attachment_image_path($attachment_id, $size);
    if ($path === '') {
        return '';
    }

    $contents = @file_get_contents($path);
    if ($contents === false || $contents === '') {
        return '';
    }

    return base64_encode($contents);
}

/*
============================================
جمع‌آوری عکس‌های یک پست/محصول و تبدیل به base64 برای ارسال به API

این تابع در زمان سینک محتوا فراخوانی می‌شود تا برای هر پست،
برگه یا محصول، تا سقف ۴ عکس را به‌صورت base64 آماده‌ی ارسال کند.

اولویت انتخاب عکس‌ها:
    ۱) عکس شاخص (Featured Image)
    ۲) گالری محصول (برای محصولات ووکامرس: متای _product_image_gallery)
    ۳) سایر تصاویر پیوست‌شده به پست (attachments با post_parent = پست)

نکته‌ی مهم: حداکثر ۴ عکس بازگردانده می‌شود (طبق محدودیت API).
       اگر بیش از ۴ عکس وجود داشت، فقط ۴ تای اول ارسال می‌شوند
       و بقیه نادیده گرفته می‌شوند.

نکته‌ی دوم: برای کنترل حجم payload، از سایز 'large' وردپرس
       (پیش‌فرض 1024×1024) استفاده می‌کنیم. برای کاهش بیشتر حجم
       می‌توانید این پارامتر را به 'medium' تغییر دهید.

خروجی: آرایه‌ای از رشته‌های base64 (در صورت نبود عکس، آرایه‌ی خالی)
============================================
*/
function ai_agent_collect_post_images_base64($post_id, $size = 'large') {

    $post_id = absint($post_id);
    if (empty($post_id)) {
        return array();
    }

    $images   = array();
    $seen_ids = array();

    // ۱. عکس شاخص (Featured Image)
    $thumb_id = get_post_thumbnail_id($post_id);
    if (!empty($thumb_id)) {
        $thumb_id = absint($thumb_id);
        $b64 = ai_agent_attachment_to_base64($thumb_id, $size);
        if ($b64 !== '') {
            $images[]   = $b64;
            $seen_ids[] = $thumb_id;
        }
    }

    // ۲. گالری محصول (فقط برای محصولات ووکامرس)
    if (class_exists('WooCommerce') && function_exists('get_post_type') && get_post_type($post_id) === 'product') {
        $gallery_ids_str = get_post_meta($post_id, '_product_image_gallery', true);
        if (!empty($gallery_ids_str)) {
            $gallery_ids = array_filter(array_map('absint', explode(',', $gallery_ids_str)));
            foreach ($gallery_ids as $gid) {
                if (count($images) >= 4) {
                    break;
                }
                if (in_array($gid, $seen_ids, true)) {
                    continue;
                }
                $b64 = ai_agent_attachment_to_base64($gid, $size);
                if ($b64 !== '') {
                    $images[]   = $b64;
                    $seen_ids[] = $gid;
                }
            }
        }
    }

    // ۳. fallback: سایر تصاویر پیوست‌شده به پست
    if (count($images) < 4) {
        $attachments = get_posts(array(
            'post_parent'    => $post_id,
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => 8,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'exclude'        => $seen_ids,
            'post_status'    => 'inherit',
        ));
        if (!empty($attachments)) {
            foreach ($attachments as $aid) {
                if (count($images) >= 4) {
                    break;
                }
                $aid = absint($aid);
                if (in_array($aid, $seen_ids, true)) {
                    continue;
                }
                $b64 = ai_agent_attachment_to_base64($aid, $size);
                if ($b64 !== '') {
                    $images[]   = $b64;
                    $seen_ids[] = $aid;
                }
            }
        }
    }

    // اطمینان نهایی از سقف ۴ عکس (محتاطانه، چون قبلاً هم کنترل شده)
    if (count($images) > 4) {
        $images = array_slice($images, 0, 4);
    }

    return $images;
}

/*
============================================
جمع‌آوری عکس‌های یک دسته‌بندی محصول (WooCommerce) و تبدیل به base64

ووکامرس برای هر دسته‌بندی محصول، یک عکس شاخص در متای term با کلید
'thumbnail_id' ذخیره می‌کند. این تابع آن عکس را استخراج و به base64
تبدیل می‌کند.

خروجی: آرایه‌ای از رشته‌های base64 (حداکثر ۱ مورد برای دسته‌ها،
       ولی برای امنیت کد همچنان زیر ۴ نگه داشته می‌شود)
============================================
*/
function ai_agent_collect_term_images_base64($term_id, $size = 'large') {

    $term_id = absint($term_id);
    if (empty($term_id)) {
        return array();
    }

    $images = array();

    // ووکامرس آی‌دی عکس شاخص دسته را در متای thumbnail_id نگه می‌دارد
    $thumb_id = get_term_meta($term_id, 'thumbnail_id', true);
    if (!empty($thumb_id)) {
        $thumb_id = absint($thumb_id);
        $b64 = ai_agent_attachment_to_base64($thumb_id, $size);
        if ($b64 !== '') {
            $images[] = $b64;
        }
    }

    return $images;
}

/*
============================================
دریافت URL عکس شاخص (نگاره اصلی) یک محصول/پست از روی لینک آن

این تابع برای غنی‌سازی رفرنس‌های دریافتی از مدل هوش مصنوعی استفاده
می‌شود. چون رفرنس‌ها فقط شامل url و title هستند، ابتدا با
url_to_postid() آی‌دی پست را از روی لینک پیدا می‌کنیم و سپس عکس
شاخص (Featured Image) آن را برمی‌گردانیم.

نکته‌ی مهم: get_the_post_thumbnail_url فقط عکس نگاره اصلی محصول
را برمی‌گرداند (نه تصاویر گالری محصول)، پس همیشه دقیقاً یک عکس
برای هر محصول خواهیم داشت.

خروجی: URL عکس (string) یا رشته‌ی خالی اگر:
    - لینک به هیچ پستی نگاشت نشود
    - پست عکس شاخص نداشته باشد
============================================
*/
function ai_agent_get_reference_image_url($url) {

    if (empty($url) || !is_string($url)) {
        return '';
    }

    $post_id = url_to_postid($url);

    if (empty($post_id)) {
        return '';
    }

    if (!has_post_thumbnail($post_id)) {
        return '';
    }

    $image_url = get_the_post_thumbnail_url($post_id, 'medium');

    return !empty($image_url) ? (string) $image_url : '';
}

/*
============================================
غنی‌سازی آرایه‌ی رفرنس‌های دریافتی از مدل با افزودن کلید image

ورودی: آرایه‌ای از رفرنس‌ها با ساختار { title, url }
خروجی: همان آرایه با کلید اضافه‌ی image برای هر آیتم
    (image خالی یعنی آن منبع عکس شاخص ندارد؛ فرانت‌اند آن را
    نادیده می‌گیرد و از گالری حذف می‌کند)
============================================
*/
function ai_agent_enrich_references_with_images($references) {

    if (!is_array($references)) {
        return array();
    }

    $enriched = array();

    foreach ($references as $ref) {
        if (!is_array($ref) || empty($ref['url'])) {
            continue;
        }

        $enriched[] = array(
            'title' => isset($ref['title']) ? (string) $ref['title'] : '',
            'url'   => (string) $ref['url'],
            'image' => ai_agent_get_reference_image_url($ref['url']),
        );
    }

    return $enriched;
}