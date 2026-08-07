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
  ۲) درخواست GET به https://dunichat.ir/api/v1/sync/settings زده می‌شود.
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

function ai_agent_add_menu(){
    add_menu_page('تنظیمات دانیچَت', 'دانیچَت', 'manage_options', 'ai-agent-settings', 'ai_agent_settings_page', AI_AGENT_URL . 'assets/images/favicon20x20.png', 80);
}
add_action('admin_menu', 'ai_agent_add_menu');

function ai_agent_settings_page(){
    if (!current_user_can('manage_options')) return;

    $settings = ai_agent_get_settings();
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

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
            $sync_notice  = '<div class="notice notice-success inline ai-agent-notice-mb"><p><strong>آخرین مقادیر با موفقیت از سرور همگام‌سازی دریافت شد.</strong> مقادیر مدل، پرامت سیستم، منابع داده و سایر فیلدها از سرور بارگذاری شده‌اند.</p></div>';
        } elseif ($save_result['status'] === 'skipped') {
            $sync_notice = '<div class="notice notice-warning inline ai-agent-notice-mb"><p><strong>' . esc_html($save_result['message']) . '</strong></p></div>';
        } else { // error
            $sync_notice = '<div class="notice notice-error inline ai-agent-notice-mb"><p><strong>خطا در همگام‌سازی:</strong> ' . esc_html($save_result['message']) . '</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>تنظیمات و مدیریت دستیار هوش مصنوعی</h1>

        <h2 class="nav-tab-wrapper ai-agent-tabs-wrap">
            <a href="?page=ai-agent-settings&tab=general" class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">تنظیمات افزونه</a>
            <a href="?page=ai-agent-settings&tab=history" class="nav-tab <?php echo $current_tab === 'history' ? 'nav-tab-active' : ''; ?>">تاریخچه چت‌ها</a>
        </h2>

        <?php if ($current_tab === 'general') :
            echo $sync_notice;
        ?>
            <form method="post" action="options.php">
                <?php settings_fields('ai_agent_settings_group'); ?>
                <table class="form-table" role="presentation">

                    <!-- ====== API Key (احراز هویت با سرور همگام‌سازی) ====== -->
                    <tr>
                        <th scope="row"><label for="ai_agent_api_key">کلید API (API Key)</label></th>
                        <td>
                            <input type="password" name="ai_agent_settings[api_key]" id="ai_agent_api_key" value="<?php echo esc_attr($settings['api_key']); ?>" class="regular-text" autocomplete="off" />
                            <button type="button" class="button button-secondary button-small" id="ai-agent-toggle-api-key" class="ai-agent-toggle-key-btn">نمایش</button>
                             <p class="description">کلید دریافت شده از سایت (<code>dunichat.ir</code>) را وارد کنید.</p>
                        </td>
                    </tr>

                    <!-- ====== موجودی کیف پول ====== -->
                    <tr>
                        <th scope="row">موجودی کیف پول</th>
                        <td>
                            <span id="ai-agent-wallet-balance-value" class="ai-agent-wallet-balance-value">—</span>
                            <button type="button" id="ai-agent-wallet-balance-refresh-btn" class="button button-secondary button-small ai-agent-action-btn is-green">به‌روزرسانی موجودی</button>
                            <span id="ai-agent-wallet-balance-status" class="ai-agent-status-text"></span>
                            <?php wp_nonce_field('ai_agent_wallet_balance_nonce_action', 'ai_agent_wallet_balance_nonce_field'); ?>
                            <p class="description">موجودی فعلی کیف پول شما در سرور دانیچَت (بر حسب ریال). این مقدار هر بار که این صفحه باز شود، به‌صورت خودکار به‌روزرسانی می‌شود.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ai_agent_model_search">مدل هوش مصنوعی</label></th>
                        <td>
                            <div class="ai-agent-model-search-wrap">
                                <input type="text" id="ai_agent_model_search" autocomplete="off" placeholder="جستجوی مدل..." value="<?php echo esc_attr($settings['model']); ?>" class="regular-text" />
                                <input type="hidden" name="ai_agent_settings[model]" id="ai_agent_model" value="<?php echo esc_attr($settings['model']); ?>" />
                                <?php wp_nonce_field('ai_agent_models_nonce_action', 'ai_agent_models_nonce_field'); ?>

                                <div id="ai-agent-models-list" class="ai-agent-models-list" role="listbox"></div>
                                <p class="description">مدل موردنظر را تایپ/جستجو کنید یا از لیست انتخاب کنید. قیمت ورودی و خروجی هر مدل (به ازای هر ۱ میلیون توکن) کنار آن نمایش داده می‌شود. مقدار فعلی: <code id="ai-agent-model-current"><?php echo esc_html($settings['model']); ?></code></p>
                            </div>
                        </td>
                    </tr>

                    <!-- ====== پرامت سیستم (System Prompt) ====== -->
                    <tr>
                        <th scope="row"><label for="ai_agent_system_prompt">پرامت سیستم (System Prompt)</label></th>
                        <td>
                            <textarea name="ai_agent_settings[system_prompt]" id="ai_agent_system_prompt" rows="5" class="large-text" placeholder="مثال: You are a helpful customer support assistant."><?php echo esc_textarea($settings['system_prompt']); ?></textarea>
                            <p class="description">این پرامت به‌عنوان دستورالعمل پایه‌ی سیستم به مدل هوش مصنوعی ارسال می‌شود. مقدار این فیلد پس از ذخیره‌ی تنظیمات، در صورت موفقیت، از سرور همگام‌سازی دریافت و جایگزین می‌شود.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ai_agent_color">رنگ دستیار</label></th>
                        <td>
                            <input type="text" name="ai_agent_settings[color]" id="ai_agent_color" value="<?php echo esc_attr($settings['color']); ?>" class="ai-agent-color-field" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ai_agent_timeout">Timeout (ثانیه)</label></th>
                        <td>
                            <input type="number" min="1" step="1" name="ai_agent_settings[timeout]" id="ai_agent_timeout" value="<?php echo esc_attr($settings['timeout']); ?>" />
                        </td>
                    </tr>

                    <tr class="ai-agent-section-divider">
                        <th scope="row">انتخاب منابع داده جهت همگام‌سازی (Sync)</th>
                        <td>
                            <fieldset>
                                <label class="ai-agent-checkbox-label">
                                    <input type="checkbox" name="ai_agent_settings[sync_types][]" value="posts" <?php checked(in_array('posts', $settings['sync_types'])); ?>> نوشته‌ها (Posts)
                                </label>
                                <label class="ai-agent-checkbox-label">
                                    <input type="checkbox" name="ai_agent_settings[sync_types][]" value="pages" <?php checked(in_array('pages', $settings['sync_types'])); ?>> برگه‌ها (Pages)
                                </label>
                                <label class="ai-agent-checkbox-label">
                                    <input type="checkbox" name="ai_agent_settings[sync_types][]" value="products" <?php checked(in_array('products', $settings['sync_types'])); ?>> محصولات فروشگاه (WooCommerce Products)
                                </label>
                                <label class="ai-agent-checkbox-label">
                                    <input type="checkbox" name="ai_agent_settings[sync_types][]" value="product_cats" <?php checked(in_array('product_cats', $settings['sync_types'])); ?>> دسته‌بندی محصولات (Product Categories)
                                </label>
                            </fieldset>
                            <p class="description">تیک‌خوردن هر کدام از این منابع، بر اساس مقدار <code>allowed_content_types</code> دریافتی از سرور همگام‌سازی به‌صورت خودکار انجام می‌شود. مواردی که می‌خواهید مدل هوش مصنوعی برای پاسخ به مشتریان به آن‌ها دسترسی داشته باشد در اینجا مشخص می‌شوند.</p>
                        </td>
                    </tr>

                    <!-- ====== سینک تصاویر (sync_images) ====== -->
                    <tr>
                        <th scope="row">سینک تصاویر (Image Sync)</th>
                        <td>
                            <label class="ai-agent-checkbox-label">
                                <input type="checkbox" name="ai_agent_settings[sync_images]" value="1" id="ai_agent_sync_images" <?php checked(!empty($settings['sync_images'])); ?>>
                                ارسال تصاویر محتوا هنگام همگام‌سازی (Send Images with Content)
                            </label>
                            <p class="description">اگر این گزینه تیک بخورد، هنگام کلیک روی دکمه‌های «همگام‌سازی اطلاعات» و «سینک تمامی محتوا»، تصاویر هر پست/محصول/دسته (حداکثر ۴ عکس به‌صورت base64) نیز به سرور ارسال می‌شوند. اگر تیک برداشته شود، فقط محتوای متنی ارسال می‌گردد. این مقدار در سرور به‌صورت <code>allow-image</code> (تیک خورده) یا <code>deny-image</code> (تیک نخورده) در فیلد <code>allowed_statuses</code> ذخیره می‌شود.</p>
                        </td>
                    </tr>

                    <!-- ====== حداکثر پیام روزانه (daily_message_limit) ====== -->
                    <tr>
                        <th scope="row"><label for="ai_agent_daily_message_limit">حداکثر پیام روزانه (Daily Message Limit)</label></th>
                        <td>
                            <input type="number" min="0" step="1" name="ai_agent_settings[daily_message_limit]" id="ai_agent_daily_message_limit" value="<?php echo esc_attr(intval($settings['daily_message_limit'])); ?>" class="small-text" />
                            <p class="description">این مقدار سقف تعداد پیام روزانه‌ی کاربران را مشخص می‌کند و پس از ذخیره‌ی تنظیمات به‌صورت <code>daily_message_limit</code> در اندپوینت <code>/api/v1/sync/settings</code> ارسال می‌شود. مقدار ۰ یعنی بدون محدودیت.</p>
                        </td>
                    </tr>

                    <?php if (!empty($settings['allowed_statuses'])) : ?>
                    <tr>
                        <th scope="row">اطلاعات دریافتی از سرور همگام‌سازی</th>
                        <td>
                            <?php if (!empty($settings['allowed_statuses'])) : ?>
                                <p class="description"><strong>وضعیت‌های مجاز:</strong></p>
                                <ul class="ai-agent-status-list">
                                    <?php foreach ($settings['allowed_statuses'] as $type => $statuses) :
                                        if (!is_array($statuses)) continue;
                                    ?>
                                        <li class="description"><code><?php echo esc_html($type); ?></code>: <?php echo esc_html(implode(', ', $statuses)); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <p class="description ai-agent-readonly-note">این مقادیر فقط از سرور همگام‌سازی دریافت می‌شوند و قابل ویرایش نیستند. (مقدار <code>image</code> توسط تیک «سینک تصاویر» کنترل می‌شود.)</p>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <tr>
                        <th scope="row">بازخوانی تنظیمات از سرور</th>
                        <td>
                            <button type="button" id="ai-agent-reload-settings-btn" class="button button-secondary ai-agent-action-btn is-green">بارگذاری اطلاعات از سرور</button>
                            <span id="ai-agent-reload-settings-status" class="ai-agent-status-text"></span>
                            <?php wp_nonce_field('ai_agent_reload_settings_nonce_action', 'ai_agent_reload_settings_nonce_field'); ?>
                            <p class="description">با کلیک روی این دکمه، بدون نیاز به ذخیره‌ی فرم، آخرین مقادیر مدل، پرامت سیستم، منابع مجاز، وضعیت‌های مجاز و سقف پیام روزانه از سرور خوانده و روی فیلدهای پایین اعمال می‌شود.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">آخرین همگام‌سازی انجام‌شده</th>
                        <td>
                            <?php
                                $last_sync_time = ai_agent_get_last_sync_time();
                                $last_sync_all_time = ai_agent_get_last_sync_all_time();
                            ?>
                            <p class="description ai-agent-sync-time-note">
                                <strong>آخرین سینک افزایشی (Sync Now):</strong>
                                <?php echo !empty($last_sync_time) ? esc_html($last_sync_time) : '<span class="ai-agent-muted-placeholder">هنوز سینکی انجام نشده است.</span>'; ?>
                            </p>
                            <p class="description ai-agent-sync-time-note-sm">
                                <strong>آخرین سینک کامل (Sync All):</strong>
                                <?php echo !empty($last_sync_all_time) ? esc_html($last_sync_all_time) : '<span class="ai-agent-muted-placeholder">هنوز سینک کاملی انجام نشده است.</span>'; ?>
                            </p>
                            <p class="description ai-agent-mt4">این تاریخ‌ها پس از هر همگام‌سازی موفق، به‌صورت خودکار به‌روزرسانی می‌شوند.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">استعلام وضعیت ارسال‌ها (Job Status)</th>
                        <td>
                            <button type="button" id="ai-agent-check-status-btn" class="button button-secondary ai-agent-action-btn is-purple">استعلام وضعیت</button>
                            <span id="ai-agent-check-status-status" class="ai-agent-status-text"></span>
                            <?php wp_nonce_field('ai_agent_sync_status_nonce_action', 'ai_agent_sync_status_nonce_field'); ?>
                            <p class="description ai-agent-mt6">وضعیت پردازش تمام آیتم‌های سینک‌شده در سرور (در صف، در حال پردازش، تکمیل‌شده، ناموفق، یافت‌نشده) را بررسی می‌کند. این استعلام هربار که این صفحه باز شود، به‌صورت خودکار هم انجام می‌شود.</p>
                            <div class="ai-agent-chart-wrap">
                                <canvas id="ai-agent-status-chart" height="220"></canvas>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">عملیات همگام‌سازی نهایی داده‌ها</th>
                        <td>
                            <div class="ai-agent-mb10">
                                <button type="button" id="ai-agent-sync-btn" class="button button-secondary ai-agent-action-btn is-blue">همگام‌سازی اطلاعات (Sync Now)</button>
                                <span id="ai-agent-sync-status" class="ai-agent-status-text"></span>
                                <?php wp_nonce_field('ai_agent_sync_nonce_action', 'ai_agent_sync_nonce_field'); ?>
                                <p class="description ai-agent-mt6">این دکمه فقط محتوای جدید (آی‌دی‌هایی که قبلاً سینک نشده‌اند) را به سرور می‌فرستد و محتوای حذف‌شده را به‌عنوان حذف به سرور اطلاع می‌دهد. ارسال تصاویر بستگی به تیک «سینک تصاویر» دارد.</p>
                            </div>

                            <div class="ai-agent-sync-all-wrap">
                                <button type="button" id="ai-agent-sync-all-btn" class="button button-secondary ai-agent-action-btn is-red">سینک تمامی محتوا</button>
                                <span id="ai-agent-sync-all-status" class="ai-agent-status-text"></span>
                                <?php wp_nonce_field('ai_agent_sync_all_nonce_action', 'ai_agent_sync_all_nonce_field'); ?>
                                <p class="description ai-agent-mt6">این دکمه بدون توجه به سینک قبلی، تمام محتوای تیک‌خورده را از ابتدا به سرور ارسال می‌کند. از این گزینه در صورت بروز مشکل یا نیاز به ارسال مجدد تمام داده‌ها استفاده کنید. ارسال تصاویر بستگی به تیک «سینک تصاویر» دارد.</p>
                            </div>

                        </td>
                    </tr>
                </table>
                <?php submit_button('ذخیره تنظیمات افزونه'); ?>
            </form>

        <?php elseif ($current_tab === 'history') : ?>
            <?php wp_nonce_field('ai_agent_chat_sessions_nonce_action', 'ai_agent_chat_sessions_nonce_field'); ?>
            <h3>تاریخچه جلسات چت</h3>
            <p class="description" style="margin-bottom:12px;">لیست تمام جلسات چت ثبت‌شده در سرور. روی هر شناسه کلیک کنید تا پیام‌های آن جلسه نمایش داده شود.</p>

            <!-- دکمه‌های شناور فیلتر وضعیت جلسات -->
            <div class="ai-agent-status-filters" id="ai-agent-status-filters">
                <button type="button" class="ai-agent-filter-btn is-active" data-status="">همه</button>
                <button type="button" class="ai-agent-filter-btn" data-status="closed">مکالمات بسته‌شده</button>
                <button type="button" class="ai-agent-filter-btn" data-status="human">پشتیبان</button>
                <button type="button" class="ai-agent-filter-btn" data-status="pending_human">در انتظار پشتیبان</button>
                <button type="button" class="ai-agent-filter-btn" data-status="bot">ربات</button>
            </div>

            <!-- نوار ابزار: صفحه‌بندی و تعداد نمایش -->
            <div class="ai-agent-sessions-toolbar" style="margin-bottom:14px;">
                <div class="ai-agent-sessions-page-size" style="display:inline-block;vertical-align:middle;">
                    <label for="ai-agent-sessions-per-page" style="margin-left:6px;">تعداد در هر صفحه:</label>
                    <select id="ai-agent-sessions-per-page" style="width:auto;">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="ai-agent-sessions-page-nav" style="display:inline-block;vertical-align:middle;margin-right:16px;">
                    <span id="ai-agent-sessions-page-info" class="ai-agent-muted-small"></span>
                    <button type="button" id="ai-agent-sessions-prev-btn" class="button button-secondary button-small" disabled>صفحه قبلی</button>
                    <button type="button" id="ai-agent-sessions-next-btn" class="button button-secondary button-small" disabled>صفحه بعدی</button>
                </div>
                <div style="display:inline-block;vertical-align:middle;margin-right:16px;">
                    <span id="ai-agent-sessions-total-info" class="ai-agent-muted-small"></span>
                </div>
            </div>

            <!-- وضعیت بارگذاری -->
            <div id="ai-agent-sessions-loading" class="ai-agent-sessions-loading" style="display:none;">در حال بارگذاری...</div>
            <div id="ai-agent-sessions-error" class="ai-agent-sessions-error" style="display:none;"></div>

            <!-- لیست آکاردئونی جلسات -->
            <div id="ai-agent-sessions-list" class="ai-agent-sessions-list"></div>

            <!-- نوار صفحه‌بندی پایین -->
            <div class="ai-agent-sessions-toolbar" style="margin-top:14px;">
                <div class="ai-agent-sessions-page-size" style="display:inline-block;vertical-align:middle;">
                    <label for="ai-agent-sessions-per-page-bottom" style="margin-left:6px;">تعداد در هر صفحه:</label>
                    <select id="ai-agent-sessions-per-page-bottom" style="width:auto;">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="ai-agent-sessions-page-nav" style="display:inline-block;vertical-align:middle;margin-right:16px;">
                    <button type="button" id="ai-agent-sessions-prev-btn-bottom" class="button button-secondary button-small" disabled>صفحه قبلی</button>
                    <button type="button" id="ai-agent-sessions-next-btn-bottom" class="button button-secondary button-small" disabled>صفحه بعدی</button>
                </div>
            </div>

        <?php endif; ?>
    </div>
    <?php
}

function ai_agent_admin_enqueue($hook){
    if ($hook !== 'toplevel_page_ai-agent-settings') return;

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