<?php

if (!defined('ABSPATH')) exit;

function ai_agent_get_settings(){
    $defaults = array(
        'model'               => 'tencent/hy3:free',
        'color'               => '#F4865B',
        'timeout'             => 15,
        'sync_types'          => array(), // فیلد آرایه‌ای برای چک‌باکس‌ها
        'system_prompt'       => '',      // پرامت سیستم (از API همگام‌سازی خوانده می‌شود)
        'api_key'             => '',      // کلید API کاربر برای احراز هویت با سرور همگام‌سازی
        'daily_message_limit' => 0,       // حداکثر پیام روزانه (قابل ویرایش کاربر و ارسال به سرور)
        'allowed_statuses'    => array(), // وضعیت‌های مجاز برای هر نوع محتوا (از سرور همگام‌سازی)
        'sync_images'         => false,   // آیا تصاویر محتوا هنگام سینک ارسال شوند؟ (allow-image / deny-image)
    );
    $saved = get_option('ai_agent_settings', array());
    return wp_parse_args($saved, $defaults);
}

function ai_agent_register_settings(){
    register_setting('ai_agent_settings_group', 'ai_agent_settings', 'ai_agent_sanitize_settings');
}
add_action('admin_init', 'ai_agent_register_settings');

function ai_agent_sanitize_settings($input){
    $old    = get_option('ai_agent_settings', array());
    $output = array();

    // چون لیست مدل‌ها به‌صورت پویا از API خارجی خوانده می‌شود، آرایه ثابتی برای اعتبارسنجی وجود ندارد
    $output['model'] = (isset($input['model']) && trim($input['model']) !== '') ? sanitize_text_field($input['model']) : 'tencent/hy3:free';
    $color = isset($input['color']) ? sanitize_hex_color($input['color']) : '';
    $output['color'] = $color ? $color : '#2563eb';

    $timeout = isset($input['timeout']) ? intval($input['timeout']) : 15;
    $output['timeout'] = $timeout > 0 ? $timeout : 15;

    // پاکسازی آرایه چک‌باکس‌های سینک
    $output['sync_types'] = (isset($input['sync_types']) && is_array($input['sync_types'])) ? array_map('sanitize_text_field', $input['sync_types']) : array();

    // پاکسازی پرامت سیستم (متن چندخطی)
    $output['system_prompt'] = isset($input['system_prompt']) ? sanitize_textarea_field($input['system_prompt']) : '';

    // پاکسازی API Key (کلید احراز هویت کاربر با سرور همگام‌سازی)
    $output['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';

    // daily_message_limit: اکنون به‌صورت numeric updown قابل ویرایش توسط کاربر است
    // مقدار واردشده توسط کاربر اعمال می‌شود؛ در صورت نبود، مقدار قبلی حفظ می‌گردد
    $daily_limit = isset($input['daily_message_limit']) ? intval($input['daily_message_limit']) : null;
    if ($daily_limit === null) {
        $output['daily_message_limit'] = isset($old['daily_message_limit']) ? intval($old['daily_message_limit']) : 0;
    } else {
        $output['daily_message_limit'] = max(0, $daily_limit);
    }

    // sync_images: چک‌باکس «سینک کردن تصاویر» — اگر تیک خورده باشد true
    $output['sync_images'] = !empty($input['sync_images']);

    // allowed_statuses همچنان فقط از سرور همگام‌سازی پر می‌شود (این‌جا فقط حفظ مقدار قبلی)
    $output['allowed_statuses']    = isset($old['allowed_statuses']) && is_array($old['allowed_statuses']) ? $old['allowed_statuses'] : array();

    return $output;
}

/*
============================================
ذخیره‌ی امن API Key هنگام ذخیره‌ی تنظیمات افزونه

این فیلتر قبل از نوشتن گزینه‌ی ai_agent_settings در wp_options
اجرا می‌شود. اگر کاربر API Key جدیدی وارد کرده باشد، نسخه‌ی
رمزنگاری‌شده‌ی آن نیز در گزینه‌ی مجزای ai_agent_api_key ذخیره
می‌شود تا در هدر X-API-Key هنگام کال به اندپوینت همگام‌سازی
استفاده شود.

نکته: اگر فیلد API Key خالی فرستاده شود، کلید رمزنگاری‌شده‌ی
قبلی دست‌نخورده باقی می‌ماند (طبق رفتار تابع ai_agent_save_api_key).
============================================
*/
function ai_agent_persist_api_key_on_save($value, $old_value, $option){

    $raw_api_key = isset($value['api_key']) ? trim((string) $value['api_key']) : '';

    if ($raw_api_key !== '') {
        // کاربر کلید جدید وارد کرده → نسخه‌ی رمزنگاری‌شده را ذخیره کن
        ai_agent_save_api_key($raw_api_key);
    }
    // اگر خالی بود، هیچ کاری نمی‌کنیم تا کلید قبلی حفظ شود

    return $value;
}
add_filter('pre_update_option_ai_agent_settings', 'ai_agent_persist_api_key_on_save', 10, 3);

/*
============================================
تابع واحد بازخوانی (GET) تنظیمات از سرور همگام‌سازی و اعمال آن
روی تنظیمات محلی افزونه (wp_options)

این تابع «تک نسخه‌ای» است و در هر سه حالت زیر فراخوانی می‌شود:
  ۱) اولین بار که کاربر صفحه‌ی تنظیمات افزونه را باز می‌کند
  ۲) کلیک روی دکمه‌ی «بارگذاری اطلاعات از سرور» (AJAX)
  ۳) بلافاصله پس از ذخیره‌ی تنظیمات توسط کاربر (بعد از PATCH)

روند کار:
  ۱) API Key رمزگشایی‌شده از wp_options خوانده می‌شود؛ اگر خالی بود
     کالی به سرور زده نمی‌شود.
  ۲) درخواست GET به https://api.dunichat.ir/api/v1/sync/settings زده می‌شود.
  ۳) ساختار پاسخ بررسی می‌شود:
       - خطای ارتباطی / کد HTTP غیر 200
       - پاسخ شامل کلید detail (خطای سرور، مثل کلید API نامعتبر)
       - پاسخ فاقد هیچ‌کدام از کلیدهای مورد انتظار
  ۴) در صورت موفقیت، مقادیر دریافتی (selected_model, system_prompt,
     allowed_content_types, allowed_statuses, daily_message_limit)
     روی گزینه‌ی ai_agent_settings اعمال و ذخیره می‌شوند.

خروجی: همیشه یک آرایه با کلیدهای:
    status  => success | skipped | error
    message => پیام قابل‌نمایش به کاربر
    data    => (فقط در حالت success) تنظیمات نهایی به‌روزشده
============================================
*/
function ai_agent_sync_settings_from_server(){

    // جلوگیری از اجرای هم‌زمان/تودرتو (مثلا وقتی خود این تابع باعث
    // فراخوانی مجدد اکشن update_option_ai_agent_settings می‌شود)
    static $in_progress = false;
    if ($in_progress) {
        return array(
            'status'  => 'skipped',
            'message' => 'یک درخواست همگام‌سازی دیگر در حال اجراست.',
        );
    }
    $in_progress = true;

    // ۱. خواندن API Key رمزگشایی‌شده از wp_options
    $api_key = ai_agent_get_api_key();

    if (empty($api_key)) {
        $in_progress = false;
        return array(
            'status'  => 'skipped',
            'message' => 'API Key خالی است؛ اندپوینت همگام‌سازی فراخوانی نشد. لطفاً کلید معتبر وارد کرده و مجدد ذخیره کنید.',
        );
    }

    // ۲. کال به اندپوینت همگام‌سازی (GET)
    $remote = ai_agent_fetch_sync_settings();

    // ۳. خطای ارتباطی یا کد HTTP غیر 200
    if ($remote === false) {
        $in_progress = false;
        return array(
            'status'  => 'error',
            'message' => 'ارتباط با سرور همگام‌سازی برقرار نشد. لطفاً اتصال اینترنت یا اعتبار API Key را بررسی کنید.',
        );
    }

    // ۴. پاسخ شامل کلید detail است → خطای سرور
    //    مثال: {"detail": "کلید API نامعتبر است"}
    if (isset($remote['detail'])) {
        $in_progress = false;
        $err_msg = is_string($remote['detail']) ? $remote['detail'] : 'خطای ناشناخته از سرور همگام‌سازی.';
        return array(
            'status'  => 'error',
            'message' => 'خطا از سمت سرور: ' . $err_msg,
        );
    }

    // ۵. بررسی وجود حداقل یکی از کلیدهای مورد انتظار در پاسخ
    $has_expected = (
        isset($remote['selected_model']) ||
        isset($remote['system_prompt']) ||
        isset($remote['allowed_content_types']) ||
        isset($remote['allowed_statuses']) ||
        isset($remote['daily_message_limit'])
    );

    if (!$has_expected) {
        $in_progress = false;
        return array(
            'status'  => 'error',
            'message' => 'پاسخ سرور همگام‌سازی ساختار مورد انتظار را نداشت. لطفاً با پشتیبانی تماس بگیرید.',
        );
    }

    // ۶. اعمال مقادیر دریافتی روی تنظیمات محلی
    $settings = ai_agent_get_settings();

    if (isset($remote['selected_model']) && is_string($remote['selected_model']) && $remote['selected_model'] !== '') {
        $settings['model'] = $remote['selected_model'];
    }
    if (isset($remote['system_prompt']) && is_string($remote['system_prompt'])) {
        $settings['system_prompt'] = $remote['system_prompt'];
    }
    if (isset($remote['allowed_content_types']) && is_array($remote['allowed_content_types'])) {
        $settings['sync_types'] = ai_agent_map_content_types($remote['allowed_content_types']);
    }
    if (isset($remote['daily_message_limit'])) {
        $settings['daily_message_limit'] = intval($remote['daily_message_limit']);
    }
    if (isset($remote['allowed_statuses']) && is_array($remote['allowed_statuses'])) {
        $settings['allowed_statuses'] = $remote['allowed_statuses'];
    }

    /*
    ============================================
    Parse کردن مقدار sync_images از allowed_statuses دریافتی از سرور:
    اگر در هر کلیدِ allowed_statuses مقدار 'allow-image' وجود داشته باشد،
    یعنی کاربر قبلاً تیک سینک تصاویر را زده → true؛ در غیر این صورت false.
    این مقدار در بارگذاری اولیه‌ی صفحه‌ی تنظیمات، state چک‌باکس را تعیین می‌کند.
    ============================================
    */
    $settings['sync_images'] = false;
    if (isset($remote['allowed_statuses']) && is_array($remote['allowed_statuses'])) {
        foreach ($remote['allowed_statuses'] as $key => $statuses) {
            if (is_array($statuses) && in_array('allow-image', $statuses, true)) {
                $settings['sync_images'] = true;
                break;
            }
            // پشتیبانی از حالتی که مقدار به‌جای آرایه، مستقیم string باشد
            if (is_string($statuses) && $statuses === 'allow-image') {
                $settings['sync_images'] = true;
                break;
            }
        }
    }

    // ۷. ذخیره در دیتابیس؛ چون این مقدار از سرور می‌آید (نه فرم کاربر):
    //    الف) اکشن update_option_ai_agent_settings موقتاً قطع می‌شود تا این
    //        ذخیره‌سازی داخلی باعث اجرای دوباره‌ی چرخه‌ی PATCH+GET نشود.
    //    ب) فیلتر sanitize_option مربوط به فرم (ai_agent_sanitize_settings)
    //        نیز موقتاً قطع می‌شود؛ چون آن تابع مخصوص ورودی فرم است و
    //        مقادیر daily_message_limit / allowed_statuses را همیشه از
    //        مقدار قبلی (old) بازمی‌گرداند و مقادیر تازه‌ی سرور را نادیده
    //        می‌گیرد.
    remove_action('update_option_ai_agent_settings', 'ai_agent_after_settings_saved', 10);
    remove_filter('sanitize_option_ai_agent_settings', 'ai_agent_sanitize_settings');

    update_option('ai_agent_settings', $settings);

    add_filter('sanitize_option_ai_agent_settings', 'ai_agent_sanitize_settings');
    add_action('update_option_ai_agent_settings', 'ai_agent_after_settings_saved', 10, 2);

    $in_progress = false;

    return array(
        'status'  => 'success',
        'message' => 'تنظیمات با موفقیت از سرور بازخوانی شد.',
        'data'    => $settings,
    );
}

/*
============================================
اجرا بلافاصله پس از ذخیره‌ی تنظیمات توسط کاربر (کلیک روی
دکمه‌ی «ذخیره تنظیمات افزونه»)

روند کار:
  ۱) مقادیری که کاربر همین الان ذخیره کرده (مدل، پرامت سیستم،
     منابع محتوای مجاز و ...) با متد PATCH به سرور ارسال می‌شوند.
  ۲) صرف‌نظر از نتیجه‌ی PATCH، بلافاصله تابع واحد
     ai_agent_sync_settings_from_server() برای بازخوانی (GET)
     مقادیر نهایی از سرور فراخوانی می‌شود (سرور ممکن است مقادیر
     ارسالی را اصلاح/نرمال‌سازی کرده باشد).
  ۳) نتیجه‌ی نهایی در transient ذخیره می‌شود تا در بارگذاری بعدی
     صفحه‌ی تنظیمات یک‌بار نمایش داده شود.
============================================
*/
function ai_agent_after_settings_saved($old_value, $value){

    $api_key = ai_agent_get_api_key();

    // اگر API Key خالی بود، نه PATCH و نه GET زده نمی‌شود
    if (empty($api_key)) {
        set_transient('ai_agent_sync_result', array(
            'status'  => 'skipped',
            'message' => 'API Key خالی است؛ اندپوینت همگام‌سازی فراخوانی نشد. لطفاً کلید معتبر وارد کرده و مجدد ذخیره کنید.',
        ), 60);
        return;
    }

    /*
    ============================================
    ساخت بدنه‌ی PATCH برای /api/v1/sync/settings.

    نکته‌ی مهم: مقدار sync_images (تیک سینک تصاویر) در قالب کلید 'image'
    داخل allowed_statuses به سرور ارسال می‌شود:
        - تیک خورده  → allowed_statuses['image'] = ['allow-image']
        - تیک نخورده → allowed_statuses['image'] = ['deny-image']
    این کلید در بارگذاری بعدی (GET) توسط ai_agent_sync_settings_from_server
    خوانده شده و state چک‌باکس را تعیین می‌کند.

    سایر کلیدهای allowed_statuses که از سرور دریافت شده‌اند حفظ می‌شوند.
    ============================================
    */
    $existing_allowed_statuses = (!empty($value['allowed_statuses']) && is_array($value['allowed_statuses']))
        ? $value['allowed_statuses']
        : array();

    // حذف کلید قدیمی 'image' اگر وجود داشت تا با مقدار تازه جایگزین شود
    unset($existing_allowed_statuses['image']);
    $image_value = !empty($value['sync_images']) ? 'allow-image' : 'deny-image';
    $existing_allowed_statuses['image'] = array($image_value);

    // ۱. ارسال (PATCH) مقادیر تازه ذخیره‌شده‌ی کاربر به سرور
    $push_payload = array(
        'selected_model'        => isset($value['model']) ? (string) $value['model'] : '',
        'system_prompt'         => isset($value['system_prompt']) ? (string) $value['system_prompt'] : '',
        'allowed_content_types' => ai_agent_unmap_content_types(isset($value['sync_types']) ? $value['sync_types'] : array()),
        'allowed_statuses'      => $existing_allowed_statuses,
        'daily_message_limit'   => isset($value['daily_message_limit']) ? intval($value['daily_message_limit']) : 0,
    );

    ai_agent_push_sync_settings($push_payload);
    // نتیجه‌ی خام PATCH عمداً بررسی نمی‌شود؛ در قدم بعد با GET،
    // مقادیر واقعی و نهایی سرور خوانده و روی افزونه اعمال می‌شود.

    // ۲. بازخوانی مقادیر نهایی از سرور با همان تابع واحد GET
    $result = ai_agent_sync_settings_from_server();

    set_transient('ai_agent_sync_result', $result, 60);
}
add_action('update_option_ai_agent_settings', 'ai_agent_after_settings_saved', 10, 2);

/*
============================================
هندلر AJAX دکمه‌ی «بارگذاری اطلاعات از سرور»
کاربر هر زمان که بخواهد (بدون نیاز به ذخیره‌ی تنظیمات) می‌تواند
با کلیک روی این دکمه، آخرین مقادیر را از سرور بخواند. این هندلر
هم از همان تابع واحد ai_agent_sync_settings_from_server() استفاده
می‌کند.
============================================
*/
function ai_agent_reload_settings_handler(){

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_reload_settings_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبار‌سنجی درخواست ناموفق بود.'));
    }

    $result = ai_agent_sync_settings_from_server();

    if ($result['status'] === 'success') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}
add_action('wp_ajax_ai_agent_reload_settings', 'ai_agent_reload_settings_handler');

/*
==========================================================================
منوی پیشخوان: یک منوی اصلی «دانیچَت» + دو زیرمنو (تنظیمات افزونه و
تاریخچه چت‌ها) تا کاربر هم از طریق زیرمنوها و هم از طریق تب‌های درون
صفحه به هر دو بخش دسترسی داشته باشد. هیچ منطق اصلی تغییری نکرده است —
فقط ساختار منو توسعه یافته تا هر دو تب به‌صورت زیرگزینه در دسترس باشند.
==========================================================================
*/
function ai_agent_add_menu(){
    // منوی اصلی (پدر)
    add_menu_page(
        'دانیچَت',
        'دانیچَت',
        'manage_options',
        'ai-agent-settings',
        'ai_agent_settings_page',
        AI_AGENT_URL . 'assets/images/favicon20x20.png',
        80
    );
    // زیرمنوی اول: تنظیمات افزونه (همان صفحه اصلی، تب general)
    add_submenu_page(
        'ai-agent-settings',
        'تنظیمات افزونه',
        'تنظیمات افزونه',
        'manage_options',
        'ai-agent-settings',
        'ai_agent_settings_page'
    );
    // زیرمنوی دوم: تاریخچه چت‌ها (همان callback، اما با slug مجزا تا
    // در منوی پیشخوان به‌صورت یک آیتم جداگانه نمایش داده شود)
    add_submenu_page(
        'ai-agent-settings',
        'تاریخچه چت‌ها',
        'تاریخچه چت‌ها',
        'manage_options',
        'ai-agent-settings-history',
        'ai_agent_settings_page'
    );
}
add_action('admin_menu', 'ai_agent_add_menu');

function ai_agent_settings_page(){
    if (!current_user_can('manage_options')) return;

    $settings = ai_agent_get_settings();
    $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'ai-agent-settings';
    // اگر کاربر از زیرمنوی «تاریخچه چت‌ها» وارد شده باشد، تب پیش‌فرض history است
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : (($current_page === 'ai-agent-settings-history') ? 'history' : 'general');

    /*
    ============================================
    بازخوانی تنظیمات از سرور همگام‌سازی — با همان تابع واحد
    ai_agent_sync_settings_from_server()، در دو حالت:

    الف) بعد از کلیک روی «ذخیره تنظیمات افزونه»:
       وردپرس گزینه‌ی ai_agent_settings را ذخیره می‌کند، اکشن
       update_option_ai_agent_settings اجرا می‌شود (تابع
       ai_agent_after_settings_saved) که داخل خودش یک‌بار
       PATCH و سپس یک‌بار همین تابع واحد GET را صدا می‌زند و
       نتیجه را در transient می‌گذارد. اینجا فقط transient
       خوانده و برای نمایش استفاده می‌شود (بدون کال مجدد).

    ب) بار اول که کاربر صفحه‌ی تنظیمات را باز می‌کند (بدون اینکه
       از فرم ذخیره ریدایرکت شده باشد): همین‌جا مستقیماً همان
       تابع واحد فراخوانی می‌شود تا آخرین مقادیر از سرور خوانده
       شود.

    در هر دو حالت، $settings از خروجی همان یک تابع واحد پر می‌شود.
    ============================================
    */
    $sync_notice = '';

    if ($current_tab === 'general') {

        $save_result = get_transient('ai_agent_sync_result');

        if ($save_result !== false) {
            // حالت «الف»: نتیجه‌ی حاصل از ذخیره‌ی تنظیمات؛ فقط یک‌بار نمایش داده می‌شود
            delete_transient('ai_agent_sync_result');
        } else {
            // حالت «ب»: باز شدن عادی صفحه؛ همان تابع واحد مستقیماً فراخوانی می‌شود
            $save_result = ai_agent_sync_settings_from_server();
        }

        if ($save_result['status'] === 'success') {
            $settings     = $save_result['data'];
            $sync_notice  = '<div class="ai-agent-notice ai-agent-notice-success"><p>آخرین مقادیر با موفقیت از سرور همگام‌سازی دریافت شد.</p></div>';
        } elseif ($save_result['status'] === 'skipped') {
            $sync_notice = '<div class="ai-agent-notice ai-agent-notice-warning"><p>' . esc_html($save_result['message']) . '</p></div>';
        } else { // error
            $sync_notice = '<div class="ai-agent-notice ai-agent-notice-error"><p>خطا در همگام‌سازی: ' . esc_html($save_result['message']) . '</p></div>';
        }
    }
    ?>
    <div class="ai-agent-app" dir="rtl">

        <!-- ====== Top App Bar ====== -->
        <header class="ai-agent-topbar">
            <div class="ai-agent-topbar-brand">
                <div class="ai-agent-brand-mark" aria-hidden="true">
                    <img src="<?php echo esc_url(AI_AGENT_URL . 'assets/images/favicon46x46.png'); ?>" alt="" />
                </div>
                <div class="ai-agent-brand-text">
                    <h1>دانیچَت</h1>
                    <span>پنل مدیریت دستیار هوش مصنوعی</span>
                </div>
            </div>
            <div class="ai-agent-topbar-tools">
                <!-- موجودی کیف پول (سمت چپ بالا) -->
                <div class="ai-agent-wallet-card">
                    <div class="ai-agent-wallet-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h16a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 1-1 1v0a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/>
                        </svg>
                    </div>
                    <div class="ai-agent-wallet-info">
                        <span class="ai-agent-wallet-label">موجودی کیف پول</span>
                        <span id="ai-agent-wallet-balance-value" class="ai-agent-wallet-amount">—</span>
                    </div>
                    <button type="button" id="ai-agent-wallet-balance-refresh-btn" class="ai-agent-sync-icon-btn" aria-label="به‌روزرسانی موجودی" title="به‌روزرسانی موجودی">
                        <svg class="ai-agent-sync-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                            <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                            <path d="M16 16h5v5"/>
                        </svg>
                    </button>
                    <span id="ai-agent-wallet-balance-status" class="ai-agent-wallet-status"></span>
                    <?php wp_nonce_field('ai_agent_wallet_balance_nonce_action', 'ai_agent_wallet_balance_nonce_field'); ?>
                </div>
            </div>
        </header>

        <!-- ====== Tabs (تب تاریخچه چت‌ها اول آمده است) ====== -->
        <nav class="ai-agent-tabs">
            <a href="?page=ai-agent-settings&tab=history" class="ai-agent-tab <?php echo $current_tab === 'history' ? 'is-active' : ''; ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                تاریخچه چت‌ها
            </a>
            <a href="?page=ai-agent-settings&tab=general" class="ai-agent-tab <?php echo $current_tab === 'general' ? 'is-active' : ''; ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                تنظیمات افزونه
            </a>
        </nav>

        <!-- ====== Tab Content ====== -->
        <main class="ai-agent-content">
            <?php if ($current_tab === 'general') :
                echo $sync_notice;
            ?>
                <form method="post" action="options.php">
                    <?php settings_fields('ai_agent_settings_group'); ?>

                    <!-- ====== API Key ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                                کلید API
                            </h2>
                        </header>
                        <div class="ai-agent-card-body">
                            <div class="ai-agent-field-row">
                                <label for="ai_agent_api_key" class="ai-agent-field-label">API Key</label>
                                <div class="ai-agent-input-group">
                                    <input type="password" name="ai_agent_settings[api_key]" id="ai_agent_api_key" value="<?php echo esc_attr($settings['api_key']); ?>" class="ai-agent-input" autocomplete="off" />
                                    <button type="button" id="ai-agent-toggle-api-key">نمایش</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ====== AI Model (Combobox) ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                مدل هوش مصنوعی
                            </h2>
                        </header>
                        <div class="ai-agent-card-body">
                            <div class="ai-agent-field-row">
                                <label for="ai_agent_model_search" class="ai-agent-field-label">انتخاب مدل</label>
                                <div class="ai-agent-combobox" id="ai-agent-combobox">
                                    <div class="ai-agent-combobox-control">
                                        <span class="ai-agent-combobox-icon" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                                        </span>
                                        <input type="text" id="ai_agent_model_search" autocomplete="off" placeholder="جستجو یا انتخاب مدل..." value="<?php echo esc_attr($settings['model']); ?>" class="ai-agent-combobox-input" />
                                        <button type="button" class="ai-agent-combobox-toggle" id="ai-agent-combobox-toggle" aria-label="نمایش لیست مدل‌ها">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                        </button>
                                    </div>
                                    <input type="hidden" name="ai_agent_settings[model]" id="ai_agent_model" value="<?php echo esc_attr($settings['model']); ?>" />
                                    <?php wp_nonce_field('ai_agent_models_nonce_action', 'ai_agent_models_nonce_field'); ?>
                                    <div id="ai-agent-models-list" class="ai-agent-combobox-list" role="listbox"></div>
                                    <div class="ai-agent-combobox-foot">مدل فعلی: <code id="ai-agent-model-current"><?php echo esc_html($settings['model']); ?></code></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ====== System Prompt ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect x="4" y="8" width="16" height="12" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                                پرامت سیستم
                            </h2>
                        </header>
                        <div class="ai-agent-card-body">
                            <div class="ai-agent-field-row">
                                <label for="ai_agent_system_prompt" class="ai-agent-field-label">System Prompt</label>
                                <textarea name="ai_agent_settings[system_prompt]" id="ai_agent_system_prompt" rows="5" class="ai-agent-textarea" placeholder="مثال: You are a helpful customer support assistant."><?php echo esc_textarea($settings['system_prompt']); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <!-- ====== Appearance (Color + Timeout) ====== -->
                    <section class="ai-agent-card ai-agent-grid-2">
                        <div class="ai-agent-card-cell">
                            <header class="ai-agent-card-header">
                                <h2>
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
                                    رنگ دستیار
                                </h2>
                            </header>
                            <div class="ai-agent-card-body">
                                <input type="text" name="ai_agent_settings[color]" id="ai_agent_color" value="<?php echo esc_attr($settings['color']); ?>" class="ai-agent-color-field" />
                            </div>
                        </div>
                        <div class="ai-agent-card-cell">
                            <header class="ai-agent-card-header">
                                <h2>
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    مدت پاسخ‌گویی
                                </h2>
                            </header>
                            <div class="ai-agent-card-body">
                                <div class="ai-agent-number-input">
                                    <input type="number" min="1" step="1" name="ai_agent_settings[timeout]" id="ai_agent_timeout" value="<?php echo esc_attr($settings['timeout']); ?>" />
                                    <span class="ai-agent-number-suffix">ثانیه</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ====== Data Sources ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                                منابع داده جهت همگام‌سازی
                            </h2>
                        </header>
                        <div class="ai-agent-card-body">
                            <div class="ai-agent-checkbox-grid">
                                <label class="ai-agent-check-card">
                                    <input type="checkbox" name="ai_agent_settings[sync_types][]" value="posts" <?php checked(in_array('posts', $settings['sync_types'])); ?>>
                                    <span class="ai-agent-check-card-body">
                                        <span class="ai-agent-check-card-title">نوشته‌ها</span>
                                        <span class="ai-agent-check-card-sub">Posts</span>
                                    </span>
                                </label>
                                <label class="ai-agent-check-card">
                                    <input type="checkbox" name="ai_agent_settings[sync_types][]" value="pages" <?php checked(in_array('pages', $settings['sync_types'])); ?>>
                                    <span class="ai-agent-check-card-body">
                                        <span class="ai-agent-check-card-title">برگه‌ها</span>
                                        <span class="ai-agent-check-card-sub">Pages</span>
                                    </span>
                                </label>
                                <label class="ai-agent-check-card">
                                    <input type="checkbox" name="ai_agent_settings[sync_types][]" value="products" <?php checked(in_array('products', $settings['sync_types'])); ?>>
                                    <span class="ai-agent-check-card-body">
                                        <span class="ai-agent-check-card-title">محصولات فروشگاه</span>
                                        <span class="ai-agent-check-card-sub">WooCommerce Products</span>
                                    </span>
                                </label>
                                <label class="ai-agent-check-card">
                                    <input type="checkbox" name="ai_agent_settings[sync_types][]" value="product_cats" <?php checked(in_array('product_cats', $settings['sync_types'])); ?>>
                                    <span class="ai-agent-check-card-body">
                                        <span class="ai-agent-check-card-title">دسته‌بندی محصولات</span>
                                        <span class="ai-agent-check-card-sub">Product Categories</span>
                                    </span>
                                </label>
                                <label class="ai-agent-check-card ai-agent-check-card-wide">
                                    <input type="checkbox" name="ai_agent_settings[sync_images]" value="1" id="ai_agent_sync_images" <?php checked(!empty($settings['sync_images'])); ?>>
                                    <span class="ai-agent-check-card-body">
                                        <span class="ai-agent-check-card-title">سینک تصاویر</span>
                                        <span class="ai-agent-check-card-sub">ارسال تصاویر محتوا هنگام همگام‌سازی</span>
                                    </span>
                                </label>
                            </div>
                            <div class="ai-agent-field-row ai-agent-mt">
                                <label for="ai_agent_daily_message_limit" class="ai-agent-field-label">حداکثر پیام روزانه</label>
                                <div class="ai-agent-number-input">
                                    <input type="number" min="0" step="1" name="ai_agent_settings[daily_message_limit]" id="ai_agent_daily_message_limit" value="<?php echo esc_attr(intval($settings['daily_message_limit'])); ?>" class="small-text" />
                                    <span class="ai-agent-number-suffix">پیام / روز </span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <?php if (!empty($settings['allowed_statuses'])) : ?>
                    <!-- ====== Server Info (read-only) ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="8" rx="2" ry="2"/><rect x="2" y="13" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="7" x2="6.01" y2="7"/><line x1="6" y1="17" x2="6.01" y2="17"/></svg>
                                اطلاعات دریافتی از سرور
                            </h2>
                            <span class="ai-agent-badge ai-agent-badge-muted">فقط‌خواندنی</span>
                        </header>
                        <div class="ai-agent-card-body">
                            <ul class="ai-agent-status-list">
                                <?php foreach ($settings['allowed_statuses'] as $type => $statuses) :
                                    if (!is_array($statuses)) continue;
                                ?>
                                    <li><code><?php echo esc_html($type); ?></code><span><?php echo esc_html(implode('، ', $statuses)); ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- ====== Job Status + Sync Operations (chart on LEFT) ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                استعلام وضعیت و عملیات همگام‌سازی
                            </h2>
                        </header>
                        <div class="ai-agent-card-body ai-agent-sync-layout">

                            <!-- RIGHT column: controls & status -->
                            <div class="ai-agent-sync-actions">

                                <div class="ai-agent-sync-block">
                                    <div class="ai-agent-sync-block-title">بازخوانی تنظیمات</div>
                                    <div class="ai-agent-sync-block-actions">
                                        <button type="button" id="ai-agent-reload-settings-btn" class="ai-agent-btn ai-agent-btn-primary">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                            بارگذاری از سرور
                                        </button>
                                        <span id="ai-agent-reload-settings-status" class="ai-agent-status-text"></span>
                                        <?php wp_nonce_field('ai_agent_reload_settings_nonce_action', 'ai_agent_reload_settings_nonce_field'); ?>
                                    </div>
                                </div>

                                <div class="ai-agent-sync-block">
                                    <div class="ai-agent-sync-block-title">آخرین همگام‌سازی</div>
                                    <?php
                                        $last_sync_time = ai_agent_get_last_sync_time();
                                        $last_sync_all_time = ai_agent_get_last_sync_all_time();
                                    ?>
                                    <div class="ai-agent-last-sync-grid">
                                        <div class="ai-agent-last-sync-item">
                                            <span class="ai-agent-last-sync-label">سینک افزایشی</span>
                                            <span class="ai-agent-last-sync-value"><?php echo !empty($last_sync_time) ? esc_html($last_sync_time) : '<span class="ai-agent-muted-placeholder">ثبت نشده</span>'; ?></span>
                                        </div>
                                        <div class="ai-agent-last-sync-item">
                                            <span class="ai-agent-last-sync-label">سینک کامل</span>
                                            <span class="ai-agent-last-sync-value"><?php echo !empty($last_sync_all_time) ? esc_html($last_sync_all_time) : '<span class="ai-agent-muted-placeholder">ثبت نشده</span>'; ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="ai-agent-sync-block">
                                    <div class="ai-agent-sync-block-title">عملیات نهایی داده‌ها</div>
                                    <div class="ai-agent-sync-block-actions">
                                        <button type="button" id="ai-agent-sync-btn" class="ai-agent-btn ai-agent-btn-outline ai-agent-btn-blue">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                            همگام‌سازی اطلاعات
                                        </button>
                                        <span id="ai-agent-sync-status" class="ai-agent-status-text"></span>
                                        <?php wp_nonce_field('ai_agent_sync_nonce_action', 'ai_agent_sync_nonce_field'); ?>
                                    </div>
                                    <div class="ai-agent-sync-block-actions ai-agent-mt">
                                        <button type="button" id="ai-agent-sync-all-btn" class="ai-agent-btn ai-agent-btn-outline ai-agent-btn-red">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                                            سینک تمامی محتوا
                                        </button>
                                        <span id="ai-agent-sync-all-status" class="ai-agent-status-text"></span>
                                        <?php wp_nonce_field('ai_agent_sync_all_nonce_action', 'ai_agent_sync_all_nonce_field'); ?>
                                    </div>
                                </div>

                            </div>

                            <!-- LEFT column: chart + status query button -->
                            <div class="ai-agent-sync-chart">
                                <div class="ai-agent-chart-wrap">
                                    <canvas id="ai-agent-status-chart" height="220"></canvas>
                                </div>
                                <button type="button" id="ai-agent-check-status-btn" class="ai-agent-btn ai-agent-btn-outline ai-agent-btn-purple">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                    استعلام وضعیت
                                </button>
                                <span id="ai-agent-check-status-status" class="ai-agent-status-text"></span>
                                <?php wp_nonce_field('ai_agent_sync_status_nonce_action', 'ai_agent_sync_status_nonce_field'); ?>
                            </div>

                        </div>
                    </section>

                    <div class="ai-agent-form-actions">
                        <?php submit_button('ذخیره تنظیمات افزونه'); ?>
                    </div>
                </form>

            <?php elseif ($current_tab === 'history') : ?>
                <?php wp_nonce_field('ai_agent_chat_sessions_nonce_action', 'ai_agent_chat_sessions_nonce_field'); ?>

                <section class="ai-agent-card">
                    <header class="ai-agent-card-header">
                        <h2>
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            تاریخچه جلسات چت
                        </h2>
                    </header>
                    <div class="ai-agent-card-body">

                        <!-- فیلترهای وضعیت — دکمه «همه» اول می‌آید تا در چیدمان RTL
                             سمت راست‌ترین مورد باشد. هر دکمه شامل یک badge قرمز کوچک
                             بالای خود است که تعداد جلسات در آن وضعیت را نشان می‌دهد. -->
                        <div class="ai-agent-status-filters" id="ai-agent-status-filters">
                            <button type="button" class="ai-agent-filter-btn is-active" data-status="">
                                <span class="ai-agent-filter-count" data-count-status="">0</span>
                                همه
                            </button>
                            <button type="button" class="ai-agent-filter-btn" data-status="closed">
                                <span class="ai-agent-filter-count" data-count-status="closed">0</span>
                                بسته‌شده
                            </button>
                            <button type="button" class="ai-agent-filter-btn" data-status="human">
                                <span class="ai-agent-filter-count" data-count-status="human">0</span>
                                پشتیبان
                            </button>
                            <button type="button" class="ai-agent-filter-btn" data-status="pending_human">
                                <span class="ai-agent-filter-count" data-count-status="pending_human">0</span>
                                در انتظار پشتیبان
                            </button>
                            <button type="button" class="ai-agent-filter-btn" data-status="bot">
                                <span class="ai-agent-filter-count" data-count-status="bot">0</span>
                                ربات
                            </button>
                        </div>

                        <!-- نوار ابزار بالا -->
                        <div class="ai-agent-sessions-toolbar">
                            <div class="ai-agent-sessions-page-size">
                                <label for="ai-agent-sessions-per-page">نمایش در صفحه:</label>
                                <select id="ai-agent-sessions-per-page">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                            <div class="ai-agent-sessions-page-nav">
                                <span id="ai-agent-sessions-page-info" class="ai-agent-muted-small"></span>
                                <button type="button" id="ai-agent-sessions-prev-btn" class="ai-agent-btn ai-agent-btn-ghost ai-agent-btn-icon" disabled aria-label="صفحه قبلی">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <button type="button" id="ai-agent-sessions-next-btn" class="ai-agent-btn ai-agent-btn-ghost ai-agent-btn-icon" disabled aria-label="صفحه بعدی">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                                </button>
                            </div>
                            <div class="ai-agent-sessions-total">
                                <span id="ai-agent-sessions-total-info" class="ai-agent-muted-small"></span>
                            </div>
                        </div>

                        <!-- وضعیت بارگذاری -->
                        <div id="ai-agent-sessions-loading" class="ai-agent-sessions-loading" style="display:none;">در حال بارگذاری...</div>
                        <div id="ai-agent-sessions-error" class="ai-agent-sessions-error" style="display:none;"></div>

                        <!-- لیست آکاردئونی جلسات -->
                        <div id="ai-agent-sessions-list" class="ai-agent-sessions-list"></div>

                        <!-- نوار ابزار پایین -->
                        <div class="ai-agent-sessions-toolbar ai-agent-sessions-toolbar-bottom">
                            <div class="ai-agent-sessions-page-size">
                                <label for="ai-agent-sessions-per-page-bottom">نمایش در صفحه:</label>
                                <select id="ai-agent-sessions-per-page-bottom">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                            <div class="ai-agent-sessions-page-nav">
                                <button type="button" id="ai-agent-sessions-prev-btn-bottom" class="ai-agent-btn ai-agent-btn-ghost ai-agent-btn-icon" disabled aria-label="صفحه قبلی">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <button type="button" id="ai-agent-sessions-next-btn-bottom" class="ai-agent-btn ai-agent-btn-ghost ai-agent-btn-icon" disabled aria-label="صفحه بعدی">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                                </button>
                            </div>
                        </div>

                    </div>
                </section>

            <?php endif; ?>
        </main>
    </div>
    <?php
}

/*
==========================================================================
بارگذاری استایل/اسکریپت صفحه‌ی تنظیمات — اکنون هم برای صفحه‌ی اصلی
(ai-agent-settings) و هم برای زیرمنوی تاریخچه چت‌ها
(ai-agent-settings-history) اجرا می‌شود تا استایل‌های جدید روی هر دو
صفحه اعمال گردند.
==========================================================================
*/
function ai_agent_admin_enqueue($hook){
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    if (!in_array($page, array('ai-agent-settings', 'ai-agent-settings-history'), true)) return;

    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_script('ai-agent-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true);

    // استایل اختصاصی صفحه‌ی تنظیمات (قبلاً inline بود، اکنون فایل مجزا)
    wp_enqueue_style(
        'ai-agent-settings-css',
        AI_AGENT_URL . 'assets/css/SettingsStyles.css',
        array('wp-color-picker'),
        null
    );

    // اسکریپت اختصاصی صفحه‌ی تنظیمات (قبلاً inline بود، اکنون فایل مجزا)
    // وابسته به jquery, wp-color-picker و Chart.js تا قبل از اجرا بارگذاری شده باشند
    wp_enqueue_script(
        'ai-agent-settings-js',
        AI_AGENT_URL . 'assets/js/settings.js',
        array('jquery', 'wp-color-picker', 'ai-agent-chartjs'),
        null,
        true
    );
}
add_action('admin_enqueue_scripts', 'ai_agent_admin_enqueue');