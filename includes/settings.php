<?php

if (!defined('ABSPATH')) exit;

function ai_agent_get_settings(){
    $defaults = array(
        'model'               => 'tencent/hy3:free',
        'color'               => '#F4865B',
        'max_tokens'          => 500,
        'timeout'             => 15,
        'sync_types'          => array(), // فیلد آرایه‌ای برای چک‌باکس‌ها
        'system_prompt'       => '',      // پرامت سیستم (از API همگام‌سازی خوانده می‌شود)
        'api_key'             => '',      // کلید API کاربر برای احراز هویت با سرور همگام‌سازی
        'daily_message_limit' => 0,       // حداکثر پیام روزانه (از سرور همگام‌سازی دریافت می‌شود)
        'allowed_statuses'    => array(), // وضعیت‌های مجاز برای هر نوع محتوا (از سرور همگام‌سازی)
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

    $max_tokens = isset($input['max_tokens']) ? intval($input['max_tokens']) : 500;
    $output['max_tokens'] = $max_tokens > 0 ? $max_tokens : 500;

    $timeout = isset($input['timeout']) ? intval($input['timeout']) : 15;
    $output['timeout'] = $timeout > 0 ? $timeout : 15;

    // پاکسازی آرایه چک‌باکس‌های سینک
    $output['sync_types'] = (isset($input['sync_types']) && is_array($input['sync_types'])) ? array_map('sanitize_text_field', $input['sync_types']) : array();

    // پاکسازی پرامت سیستم (متن چندخطی)
    $output['system_prompt'] = isset($input['system_prompt']) ? sanitize_textarea_field($input['system_prompt']) : '';

    // پاکسازی API Key (کلید احراز هویت کاربر با سرور همگام‌سازی)
    $output['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';

    // حفظ فیلدهایی که فرم ندارند و فقط از سرور همگام‌سازی پر می‌شوند
    $output['daily_message_limit'] = isset($old['daily_message_limit']) ? intval($old['daily_message_limit']) : 0;
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
  ۲) درخواست GET به https://mhtrxz.ir/api/v1/sync/settings زده می‌شود.
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

    // ۱. ارسال (PATCH) مقادیر تازه ذخیره‌شده‌ی کاربر به سرور
    $push_payload = array(
        'selected_model'        => isset($value['model']) ? (string) $value['model'] : '',
        'system_prompt'         => isset($value['system_prompt']) ? (string) $value['system_prompt'] : '',
        'allowed_content_types' => ai_agent_unmap_content_types(isset($value['sync_types']) ? $value['sync_types'] : array()),
        'allowed_statuses'      => (!empty($value['allowed_statuses']) && is_array($value['allowed_statuses'])) ? $value['allowed_statuses'] : new stdClass(),
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
    add_menu_page('تنظیمات هم گفتار', 'هم گفتار', 'manage_options', 'ai-agent-settings', 'ai_agent_settings_page', AI_AGENT_URL . 'assets/images/favicon20x20.png', 80);
}
add_action('admin_menu', 'ai_agent_add_menu');

function ai_agent_settings_page(){
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $settings = ai_agent_get_settings();
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

    if (isset($_POST['ai_agent_admin_reply_nonce']) && wp_verify_nonce($_POST['ai_agent_admin_reply_nonce'], 'ai_agent_reply_action')) {
        $ticket_id = intval($_POST['ticket_id']);
        $reply_text = sanitize_textarea_field($_POST['admin_reply']);
        if (!empty($reply_text)) {
            ai_agent_reply_support($ticket_id, $reply_text);
            echo '<div class="updated"><p>پاسخ شما با موفقیت برای کاربر ارسال شد.</p></div>';
        }
    }

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
            <a href="?page=ai-agent-settings&tab=support" class="nav-tab <?php echo $current_tab === 'support' ? 'nav-tab-active' : ''; ?>">پیام‌های پشتیبانی کارشناسان</a>
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
                             <p class="description">کلید دریافت شده از سایت (<code>mhtrxz.ir</code>) را وارد کنید.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ai_agent_model_search">مدل هوش مصنوعی</label></th>
                        <td>
                            <div class="ai-agent-model-search-wrap">
                                <input type="text" id="ai_agent_model_search" autocomplete="off" placeholder="جستجوی مدل..." value="<?php echo esc_attr($settings['model']); ?>" class="regular-text" />
                                <input type="hidden" name="ai_agent_settings[model]" id="ai_agent_model" value="<?php echo esc_attr($settings['model']); ?>" />
                                <?php wp_nonce_field('ai_agent_models_nonce_action', 'ai_agent_models_nonce_field'); ?>

                                <div id="ai-agent-models-list" class="ai-agent-models-list"></div>
                                <p class="ai-agent-mt6">
                                    <button type="button" id="ai-agent-load-more" class="button button-secondary button-small ai-agent-hidden">بارگذاری بیشتر</button>
                                </p>
                                <p class="description">مدل موردنظر را جستجو یا از لیست انتخاب کنید. مقدار فعلی: <code id="ai-agent-model-current"><?php echo esc_html($settings['model']); ?></code></p>
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
                        <th scope="row"><label for="ai_agent_max_tokens">تعداد توکن‌ها</label></th>
                        <td>
                            <input type="number" min="1" step="1" name="ai_agent_settings[max_tokens]" id="ai_agent_max_tokens" value="<?php echo esc_attr($settings['max_tokens']); ?>" />
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

                    <?php if (!empty($settings['daily_message_limit']) || !empty($settings['allowed_statuses'])) : ?>
                    <tr>
                        <th scope="row">اطلاعات دریافتی از سرور همگام‌سازی</th>
                        <td>
                            <?php if (!empty($settings['daily_message_limit'])) : ?>
                                <p class="description"><strong>حداکثر پیام روزانه:</strong> <?php echo intval($settings['daily_message_limit']); ?></p>
                            <?php endif; ?>
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
                            <p class="description ai-agent-readonly-note">این مقادیر فقط از سرور همگام‌سازی دریافت می‌شوند و قابل ویرایش نیستند.</p>
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
                                <p class="description ai-agent-mt6">این دکمه فقط محتوای جدید (آی‌دی‌هایی که قبلاً سینک نشده‌اند) را به سرور می‌فرستد و محتوای حذف‌شده را به‌عنوان حذف به سرور اطلاع می‌دهد.</p>
                            </div>

                            <div class="ai-agent-sync-all-wrap">
                                <button type="button" id="ai-agent-sync-all-btn" class="button button-secondary ai-agent-action-btn is-red">سینک تمامی محتوا</button>
                                <span id="ai-agent-sync-all-status" class="ai-agent-status-text"></span>
                                <?php wp_nonce_field('ai_agent_sync_all_nonce_action', 'ai_agent_sync_all_nonce_field'); ?>
                                <p class="description ai-agent-mt6">این دکمه بدون توجه به سینک قبلی، تمام محتوای تیک‌خورده را از ابتدا به سرور ارسال می‌کند. از این گزینه در صورت بروز مشکل یا نیاز به ارسال مجدد تمام داده‌ها استفاده کنید.</p>
                            </div>
                        </td>
                    </tr>
                </table>
                <?php submit_button('ذخیره تنظیمات افزونه'); ?>
            </form>

        <?php elseif ($current_tab === 'history') :
            $table_chats = $wpdb->prefix . 'ai_agent_chats';
            $chats = $wpdb->get_results("SELECT * FROM {$table_chats} ORDER BY id DESC LIMIT 100");
            ?>
            <h3>سابقه آخرین چت‌های انجام شده کاربران با مدل هوش مصنوعی</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="ai-agent-col-8">ID سشن</th>
                        <th class="ai-agent-col-35">سوال کاربر</th>
                        <th class="ai-agent-col-40">پاسخ مدل هوش مصنوعی</th>
                        <th class="ai-agent-col-10">بازخورد (Feedback)</th>
                        <th class="ai-agent-col-17">تاریخ ثبت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($chats)): foreach($chats as $chat): ?>
                        <tr>
                            <td><code><?php echo esc_html($chat->session_id); ?></code></td>
                            <td><?php echo esc_html($chat->question); ?></td>
                            <td><?php echo nl2br(esc_html($chat->answer)); ?></td>
                            <td>
                                <?php
                                    if($chat->feedback === 'like') echo '<span class="ai-agent-feedback-like">👍 پسندید</span>';
                                    elseif($chat->feedback === 'dislike') echo '<span class="ai-agent-feedback-dislike">👎 نپسندید</span>';
                                    else echo '<span class="ai-agent-muted-placeholder">ثبت نشده</span>';
                                ?>
                            </td>
                            <td><?php echo esc_html($chat->created_at); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5">هنوز هیچ گفت‌وگویی ثبت نشده است.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($current_tab === 'support') :
            $table_support = $wpdb->prefix . 'ai_agent_support';
            $tickets = $wpdb->get_results("SELECT * FROM {$table_support} ORDER BY status ASC, id DESC LIMIT 50");
            ?>
            <h3>پیام‌های ارجاع شده به کارشناسان پشتیبانی</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="ai-agent-col-25">متن پیام کاربر</th>
                        <th class="ai-agent-col-25">پاسخ شما (ادمین)</th>
                        <th class="ai-agent-col-10">وضعیت</th>
                        <th class="ai-agent-col-40">فرم پاسخگویی سریع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($tickets)): foreach($tickets as $ticket): ?>
                        <tr>
                            <td>
                                <strong>پیام:</strong> <?php echo esc_html($ticket->user_message); ?><br>
                                <small class="ai-agent-muted-small">سشن: <?php echo esc_html($ticket->session_id); ?> | تاریخ: <?php echo esc_html($ticket->created_at); ?></small>
                            </td>
                            <td>
                                <?php echo $ticket->admin_reply ? nl2br(esc_html($ticket->admin_reply)) : '<em class="ai-agent-no-reply">بدون پاسخ</em>'; ?>
                                <?php if($ticket->replied_at): ?><br><small class="ai-agent-muted-small">زمان پاسخ: <?php echo esc_html($ticket->replied_at); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php echo $ticket->status === 'pending' ? '<span class="ai-agent-badge-pending">در انتظار</span>' : '<span class="ai-agent-badge-answered">پاسخ داده شده</span>'; ?>
                            </td>
                            <td>
                                <form method="post" action="">
                                    <?php wp_nonce_field('ai_agent_reply_action', 'ai_agent_admin_reply_nonce'); ?>
                                    <input type="hidden" name="ticket_id" value="<?php echo intval($ticket->id); ?>">
                                    <textarea name="admin_reply" class="ai-agent-reply-textarea" placeholder="متن پاسخ خود را بنویسید..." required></textarea>
                                    <input type="submit" name="submit_admin_reply" class="button button-primary button-small ai-agent-mt5" value="ارسال پاسخ به کاربر">
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4">در حال حاضر هیچ پیام پشتیبانی ثبت نشده است.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
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