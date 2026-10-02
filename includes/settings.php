<?php

if (!defined('ABSPATH')) exit;

function ai_agent_get_settings(){
    $defaults = array(
        'color'               => '#F4865B',
        /*
        ====== رنگ دستیار ======
        color_light: رنگ پرایمری ویجت — روی دکمه‌ی شناور، هدر و دکمه‌ی
        ارسال می‌نشیند و موقع نصب از رنگ اصلی خودِ سایت خوانده می‌شود.

        color_dark: رنگ همان عناصر در حالت تاریک. به‌صورت پیش‌فرض از
        color_light با ضریب AI_AGENT_DARK_LIFT ساخته می‌شود (حالت
        خودکار)؛ کاربر می‌تواند در صفحه‌ی تنظیمات با پالت یا کد هگز
        دلخواه، آن را به‌صورت دستی عوض کند (color_dark_custom = 1).
        */
        'color_light'         => '#F4865B',
        'color_dark'          => '#F4865B',
        // آیا کاربر به‌صورت دستی رنگ تاریک را عوض کرده (1) یا هنوز روی
        // پیشنهاد خودکار است (0). در حالتِ auto، با هر تغییرِ رنگِ روشن،
        // رنگِ تاریک هم از همان فرمولِ auto ساخته می‌شود.
        'color_dark_custom'   => '0',

        'sync_schedule'       => 'every_3_days', // manual | daily | every_3_days | weekly
        'sync_hour'           => 0,             // ساعت اجرای خودکار (۰ = نیمه‌شب به وقت سایت)

        /*
        ============================================
        تم چت — از این‌جا کنترل می‌شود، نه از داخل ویجت

        آیکون ماه/خورشید داخل هدر چت حذف شد. تم دیگر انتخابِ
        بازدیدکننده نیست:
          - theme_mode = auto  : از تم خود سایت/سیستم پیروی می‌کند
          - theme_mode = light : همیشه روشن
          - theme_mode = dark  : همیشه تاریک

        و رنگ پس‌زمینه‌ی صفحه‌ی چت در هر دو حالت این‌جا قابل تغییر است.
        این سه فیلد صرفاً ظاهری‌اند و مستقیماً توسط ویجت خوانده می‌شوند.
        ============================================
        */
        'theme_mode'          => 'auto',    // auto | light | dark
        'chat_bg_light'       => '#FAF9F5',
        'chat_bg_dark'        => '#1F1E1D',
        'timeout'             => 15,
        'sync_types'          => array(), // فیلد آرایه‌ای برای چک‌باکس‌ها (تنظیم محلی — سینک کدام نوع محتوا)
        'api_key'             => '',      // کلید API کاربر برای احراز هویت با سرور همگام‌سازی
        'daily_message_limit' => 0,       // حداکثر پیام روزانه (فقط‌خواندنی از سرور — در پنل دانیچَت تنظیم می‌شود)
        'sync_images'         => false,   // آیا تصاویر محتوا هنگام سینک ارسال شوند؟ (تنظیم محلی)

        /*
        ============================================
        موقعیت آیکون افزونه (ویجت شناور) — تفکیک بر اساس دستگاه

        برای هر دستگاه (موبایل / تبلت / دسکتاپ) دو مقدار مستقل ذخیره می‌شود:
          - button_position_side_{device}   : 'right' (پیش‌فرض) یا 'left'
          - button_position_offset_y_{device}: مقدار به پیکسل؛
            مثبت ⇒ بالا، منفی ⇒ پایین، 0 ⇒ بدون تغییر

        بازه‌های دستگاه:
          - mobile  : عرض صفحه تا 768px
          - tablet  : عرض صفحه بین 769px تا 1024px
          - desktop : عرض صفحه از 1025px به بالا
        ============================================
        */
        'button_position_side'            => 'right',
        'button_position_offset_y'        => 0,

        'button_position_side_mobile'     => 'right',
        'button_position_offset_y_mobile' => 0,

        'button_position_side_tablet'     => 'right',
        'button_position_offset_y_tablet' => 0,

        'button_position_side_desktop'    => 'right',
        'button_position_offset_y_desktop'=> 0,
    );
    $saved = get_option('ai_agent_settings', array());
    $settings = wp_parse_args($saved, $defaults);

    /*
    ============================================
    مهاجرت خودکار از نسخه‌های قبلی:

    ۱) رنگ: اگر color_light / color_dark هنوز ذخیره نشده‌اند ولی رنگ
       قدیمی (color) موجود است، همان رنگ برای هر دو حالت کپی می‌شود.
    ۲) موقعیت: اگر مقادیر per-device ذخیره نشده‌اند ولی مقادیر قدیمی
       (button_position_side / button_position_offset_y) موجودند، همان
       مقادیر برای هر سه دستگاه کپی می‌شود تا پس از به‌روزرسانی
       افزونه، موقعیت فعلی سایت تغییر نکند.
    ============================================
    */
    if (!isset($saved['color_light']) && !empty($settings['color'])) {
        $settings['color_light'] = $settings['color'];
    }
    if (!isset($saved['color_dark']) && !empty($settings['color'])) {
        $settings['color_dark'] = $settings['color'];
    }

    $needs_position_migration = (
        !isset($saved['button_position_side_mobile']) ||
        !isset($saved['button_position_side_tablet']) ||
        !isset($saved['button_position_side_desktop'])
    );
    if ($needs_position_migration) {
        $legacy_side   = (isset($saved['button_position_side']) && $saved['button_position_side'] === 'left') ? 'left' : 'right';
        $legacy_offset = isset($saved['button_position_offset_y']) ? intval($saved['button_position_offset_y']) : 0;
        foreach (array('mobile', 'tablet', 'desktop') as $device) {
            if (!isset($saved['button_position_side_' . $device])) {
                $settings['button_position_side_' . $device] = $legacy_side;
            }
            if (!isset($saved['button_position_offset_y_' . $device])) {
                $settings['button_position_offset_y_' . $device] = $legacy_offset;
            }
        }
    }

    return $settings;
}

function ai_agent_register_settings(){
    register_setting('ai_agent_settings_group', 'ai_agent_settings', 'ai_agent_sanitize_settings');
}
add_action('admin_init', 'ai_agent_register_settings');

function ai_agent_sanitize_settings($input){
    $old    = get_option('ai_agent_settings', array());
    $output = array();

    /*
    ============================================
    رنگ دستیار — یک رنگ پرایمری + رنگ دارکِ (auto یا custom)

    color_light: رنگ پرایمری ویجت (دکمه‌ی شناور، هدر، دکمه‌ی ارسال).
    انتخاب رنگ هیچ اجباری‌ای ندارد: کاربر می‌تواند رنگ سایتش را بزند، یا
    از پالت آماده یکی بردارد، یا کد هگز دستی بنویسد، یا با کلیک روی
    دایره‌ی رنگ، رنگ دلخواهش را همان‌جا بسازد.

    اگر کاربر رنگی را پاک کند (خالی بفرستد)، مقدار قبلی ذخیره‌شده
    حفظ می‌شود؛ و اگر مقدار قبلی هم نبود، رنگ پیش‌فرض برند اعمال می‌شود.
    کلید قدیمی color نیز برای سازگاری با نسخه‌های قبلی معتبر می‌ماند
    و همیشه با مقدار color_light همگام نگه داشته می‌شود.

    color_dark از فرم دریافت می‌شود. اگر کاربر رنگ تاریک را دستی عوض
    نکرده (color_dark_custom = 0) یا مقدار خالی/نامعتبر فرستاده، حالت
    خودکار فعال است و مقدار از color_light با ضریب AI_AGENT_DARK_LIFT
    ساخته می‌شود. اگر کاربر رنگ تاریک را دستی عوض کرده (color_dark_custom
    = 1) و مقدار معتبر است، همان مقدار ذخیره می‌شود. این‌طوری منطقِ
    قدیمی (ساختِ خودکارِ رنگ دارک از رنگ روشن) کاملاً حفظ می‌شود و
    در عین حال امکانِ سفارشی‌سازیِ رنگ دارک هم فراهم می‌شود.
    ============================================
    */
    $color_light = isset($input['color_light']) ? sanitize_hex_color($input['color_light']) : '';
    if (!$color_light) {
        $color_light = (isset($old['color_light']) && sanitize_hex_color($old['color_light'])) ? sanitize_hex_color($old['color_light']) : '#F4865B';
    }
    $output['color_light'] = $color_light;

    // رنگ حالت تاریک: یا از ورودی کاربر، یا از همان فرمولِ auto
    $auto_dark = function_exists('ai_agent_lighten_hex')
        ? ai_agent_lighten_hex($color_light, AI_AGENT_DARK_LIFT)
        : $color_light;

    $color_dark_input = isset($input['color_dark']) ? sanitize_hex_color($input['color_dark']) : '';
    $color_dark_custom = isset($input['color_dark_custom']) ? (string) $input['color_dark_custom'] : '0';

    if ($color_dark_custom === '1' && $color_dark_input && strtolower($color_dark_input) !== strtolower($auto_dark)) {
        // کاربر به‌صورت دستی یک رنگ تاریکِ متفاوت از پیشنهادِ خودکار انتخاب کرده
        $output['color_dark'] = $color_dark_input;
        $output['color_dark_custom'] = '1';
    } else {
        // حالت خودکار: از همان فرمولِ auto استفاده می‌شود
        $output['color_dark'] = $auto_dark;
        $output['color_dark_custom'] = '0';
    }

    // کلید قدیمی color برای سازگاری (معادل رنگ پرایمری)

    /*
    تم چت: فقط سه مقدار مجاز است؛ هر چیز دیگری به auto برمی‌گردد.
    رنگ پس‌زمینه‌ی چت هم فقط hex معتبر؛ در نبود مقدار معتبر، پیش‌فرض گرم.
    */
    $theme_mode = isset($input['theme_mode']) ? sanitize_text_field($input['theme_mode']) : 'auto';
    $output['theme_mode'] = in_array($theme_mode, array('auto', 'light', 'dark'), true) ? $theme_mode : 'auto';

    $bg_light = isset($input['chat_bg_light']) ? sanitize_hex_color($input['chat_bg_light']) : '';
    $output['chat_bg_light'] = $bg_light ? $bg_light : '#FAF9F5';

    $bg_dark = isset($input['chat_bg_dark']) ? sanitize_hex_color($input['chat_bg_dark']) : '';
    $output['chat_bg_dark'] = $bg_dark ? $bg_dark : '#1F1E1D';

    $output['color'] = $color_light;

    $timeout = isset($input['timeout']) ? intval($input['timeout']) : 15;
    $output['timeout'] = $timeout > 0 ? $timeout : 15;

    // پاکسازی آرایه چک‌باکس‌های سینک (تنظیم محلی)
    $output['sync_types'] = (isset($input['sync_types']) && is_array($input['sync_types'])) ? array_map('sanitize_text_field', $input['sync_types']) : array();

    /*
    ============================================
    همگام‌سازی خودکار — ریتم و ساعت اجرا

    ریتم فقط چهار مقدار شناخته‌شده دارد؛ مقدار نامعتبر به مقدار قبلی
    (یا پیش‌فرض هر سه روز) برمی‌گردد. ساعتِ اجرا هم بین ۰ تا ۲۳ کلمپ
    می‌شود. خودِ زمان‌بند (WP-Cron) بعد از ذخیره‌ی تنظیمات، با اکشن
    update_option_ai_agent_settings خودش را با این مقادیر هم‌راستا
    می‌کند.
    ============================================
    */
    $allowed_schedules = array('daily', 'every_3_days', 'weekly', 'manual');
    $schedule = isset($input['sync_schedule']) ? sanitize_text_field($input['sync_schedule']) : '';
    if (!in_array($schedule, $allowed_schedules, true)) {
        $schedule = (isset($old['sync_schedule']) && in_array($old['sync_schedule'], $allowed_schedules, true))
            ? $old['sync_schedule']
            : 'every_3_days';
    }
    $output['sync_schedule'] = $schedule;

    $sync_hour = isset($input['sync_hour']) ? intval(ai_agent_en_digits($input['sync_hour'])) : 0;
    $output['sync_hour'] = min(23, max(0, $sync_hour));

    // پاکسازی API Key (کلید احراز هویت کاربر با سرور همگام‌سازی)
    $output['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';

    /*
    پرچم «حذف کامل توکن» (api_key_delete):
    دکمه‌ی حذف توکن در پنل این فیلد مخفی را ۱ می‌کند. حذفِ واقعیِ
    کلیدِ رمزنگاری‌شده در فیلتر pre_update_option_ai_agent_settings
    (تابع ai_agent_persist_api_key_on_save) انجام می‌شود؛ این‌جا فقط
    پرچم معتبر عبور داده می‌شود. حذف فقط وقتی معتبر است که فیلدِ
    توکن هم خالی باشد (اگر کاربر توکن جدیدی همزمان وارد کرده باشد،
    توکن جدید جایگزین می‌شود و پرچم بی‌اثر است).
    */
    $api_key_delete = isset($input['api_key_delete']) ? (string) $input['api_key_delete'] : '0';
    $output['api_key_delete'] = ($api_key_delete === '1' && $output['api_key'] === '') ? '1' : '0';

    // daily_message_limit: مقدار واردشده توسط کاربر اعمال می‌شود؛ در صورت
    // نبود، مقدار قبلی حفظ می‌گردد. (این مقدار فقط محلی است؛ سقف نهایی
    // روزانه در پنل دانیچَت تنظیم می‌شود و از سرور خوانده می‌شود.)
    $daily_limit = isset($input['daily_message_limit']) ? intval($input['daily_message_limit']) : null;
    if ($daily_limit === null) {
        $output['daily_message_limit'] = isset($old['daily_message_limit']) ? intval($old['daily_message_limit']) : 0;
    } else {
        $output['daily_message_limit'] = max(0, $daily_limit);
    }

    // sync_images: چک‌باکس «سینک کردن تصاویر» — اگر تیک خورده باشد true (تنظیم محلی)
    $output['sync_images'] = !empty($input['sync_images']);

    /*
    ============================================
    موقعیت آیکون افزونه (ویجت شناور) — تفکیک بر اساس دستگاه

    برای هر دستگاه (موبایل / تبلت / دسکتاپ) دو مقدار ذخیره می‌شود:
      - button_position_side_{device}   : 'left' یا 'right' (پیش‌فرض 'right')
      - button_position_offset_y_{device}: عدد صحیح به پیکسل
        (مثبت ⇒ بالا، منفی ⇒ پایین، 0 ⇒ بدون تغییر)

    هر دستگاه می‌تواند مقادیری کاملاً مستقل و متفاوت از دستگاه‌های
    دیگر داشته باشد. مقادیر قدیمی button_position_side /
    button_position_offset_y نیز برای سازگاری حفظ می‌شوند و از
    مقادیر دسکتاپ کپی می‌گردند.

    مقدار ارسال‌شده از فرم بررسی می‌شود؛ اگر نامعتبر بود، مقدار قبلی
    یا پیش‌فرض به‌کار گرفته می‌شود.
    ============================================
    */
    foreach (array('mobile', 'tablet', 'desktop') as $device) {
        $side_key   = 'button_position_side_' . $device;
        $offset_key = 'button_position_offset_y_' . $device;

        $raw_side = isset($input[$side_key]) ? sanitize_text_field($input[$side_key]) : '';
        if ($raw_side === '') {
            $output[$side_key] = (isset($old[$side_key]) && $old[$side_key] === 'left') ? 'left' : 'right';
        } else {
            $output[$side_key] = ($raw_side === 'left') ? 'left' : 'right';
        }

        $raw_offset = isset($input[$offset_key]) ? intval($input[$offset_key]) : null;
        if ($raw_offset === null) {
            $output[$offset_key] = isset($old[$offset_key]) ? intval($old[$offset_key]) : 0;
        } else {
            $output[$offset_key] = $raw_offset;
        }
    }

    // مقادیر قدیمی (تک‌دستگاهه) برای سازگاری — از مقادیر دسکتاپ کپی می‌شوند
    $output['button_position_side']     = $output['button_position_side_desktop'];
    $output['button_position_offset_y'] = $output['button_position_offset_y_desktop'];

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

    /*
    درخواست حذف کامل توکن: پرچم api_key_delete = 1 (که سانایتیزر
    فقط وقتی آن را معتبر نگه می‌دارد که فیلد توکن خالی باشد) یعنی
    کاربر روی «حذف توکن» زده و تأیید کرده است. در این حالت کلید
    رمزنگاری‌شده از wp_options به‌طور کامل پاک می‌شود و بعد از ذخیره،
    بج وضعیت توکن «ثبت نشده» را نشان می‌دهد.
    */
    $wants_key_delete = (isset($value['api_key_delete']) && $value['api_key_delete'] === '1');

    if ($raw_api_key !== '') {
        // کاربر کلید جدید وارد کرده → نسخه‌ی رمزنگاری‌شده را ذخیره کن
        ai_agent_save_api_key($raw_api_key);
    } elseif ($wants_key_delete) {
        // درخواست حذف کامل: کلید رمزنگاری‌شده از دیتابیس پاک می‌شود
        ai_agent_delete_api_key();
    }
    // اگر خالی بود و پرچم حذف هم نبود، هیچ کاری نمی‌کنیم تا کلید قبلی حفظ شود

    // پرچم حذف، تنظیمات واقعی سایت نیست و نباید در wp_options بماند
    unset($value['api_key_delete']);

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
  ۴) در صورت موفقیت، مقادیر دریافتی (daily_message_limit) روی گزینه‌ی
     ai_agent_settings اعمال و ذخیره می‌شوند. سایر فیلدهای پاسخ
     (selected_model, starter_questions, organization_name, asr_enabled,
     tts_enabled) فقط‌خواندنی هستند و در پنل دانیچَت مدیریت می‌شوند.

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

    // ۵. بررسی وجود حداقل یکی از کلیدهای مورد انتظار در پاسخ جدید
    //    پاسخ GET /sync/settings فقط‌خواندنی است و شامل این فیلدهاست:
    //    selected_model, daily_message_limit, starter_questions,
    //    organization_name, asr_enabled, tts_enabled
    $has_expected = (
        isset($remote['selected_model']) ||
        isset($remote['daily_message_limit']) ||
        isset($remote['starter_questions']) ||
        isset($remote['organization_name']) ||
        isset($remote['asr_enabled']) ||
        isset($remote['tts_enabled'])
    );

    if (!$has_expected) {
        $in_progress = false;
        return array(
            'status'  => 'error',
            'message' => 'پاسخ سرور همگام‌سازی ساختار مورد انتظار را نداشت. لطفاً با پشتیبانی تماس بگیرید.',
        );
    }

    // ۶. اعمال مقادیر دریافتی روی تنظیمات محلی
    //    تنها daily_message_limit از سرور خوانده و روی تنظیمات محلی اعمال
    //    می‌شود. model و system_prompt دیگر بخشی از افزونه نیستند (در پنل
    //    دانیچَت مدیریت می‌شوند). sync_types و sync_images نیز از این پس
    //    تنظیماتی صرفاً محلی هستند و از سرور بازنویسی نمی‌شوند.
    $settings = ai_agent_get_settings();

    if (isset($remote['daily_message_limit'])) {
        $settings['daily_message_limit'] = intval($remote['daily_message_limit']);
    }

    // ۷. ذخیره در دیتابیس؛ چون این مقدار از سرور می‌آید (نه فرم کاربر)،
    //    فیلتر sanitize_option مربوط به فرم موقتاً قطع می‌شود تا مقادیر
    //    تازه‌ی سرور دست‌خوش اعتبارسنجیِ مخصوصِ فرم نشود.
    remove_filter('sanitize_option_ai_agent_settings', 'ai_agent_sanitize_settings');

    update_option('ai_agent_settings', $settings);

    add_filter('sanitize_option_ai_agent_settings', 'ai_agent_sanitize_settings');

    $in_progress = false;

    return array(
        'status'  => 'success',
        'message' => 'تنظیمات با موفقیت از سرور بازخوانی شد.',
        'data'    => $settings,
    );
}

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

/*
==========================================================================
صفحه‌ی تنظیمات — طراحی جدید (مبتنی بر دیزاین‌سیستم daniChat admin)

نکته‌ی منطق: همه‌ی عملیات‌ها مثل قبل با همان اندپوینت‌ها و همان
فرم options.php انجام می‌شوند. تنظیمات «خودکار» ذخیره نمی‌شوند؛
کاربر با دکمه‌ی «ذخیره تنظیمات» (انتهای فرم) ثبت می‌کند.
==========================================================================
*/
function ai_agent_settings_page(){
    if (!current_user_can('manage_options')) return;

    $settings = ai_agent_get_settings();

    $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'ai-agent-settings';
    // اگر کاربر از زیرمنوی «تاریخچه چت‌ها» وارد شده باشد، نمای پیش‌فرض history است
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : (($current_page === 'ai-agent-settings-history') ? 'history' : 'general');
    $initial_view = ($current_tab === 'history') ? 'history' : 'settings';

    /*
    ============================================
    بازخوانی تنظیمات از سرور همگام‌سازی — با همان تابع واحد
    ai_agent_sync_settings_from_server()، در دو حالت:

    الف) بعد از کلیک روی «ذخیره تنظیمات افزونه»:
       transient ai_agent_sync_result خوانده و پاک می‌شود.

    ب) بار اول که کاربر صفحه را باز می‌کند: همان تابع واحد مستقیماً
       فراخوانی می‌شود تا آخرین مقادیر از سرور خوانده شود.

    برخلاف طراحی قدیمی، نتیجه به‌صورت بنر قرمز/سبز بالای صفحه نمایش
    داده نمی‌شود؛ وضعیت توکن داخل بخش «توکن سایت» نشان داده می‌شود
    و خطاها هم همان‌جا به‌صورت یک یادداشت آرام می‌نشینند.
    ============================================
    */
    $token_note      = '';
    $token_note_kind = 'info';

    $save_result = get_transient('ai_agent_sync_result');
    if ($save_result !== false) {
        delete_transient('ai_agent_sync_result');
    } else {
        $save_result = ai_agent_sync_settings_from_server();
    }

    if (is_array($save_result) && isset($save_result['status'])) {
        if ($save_result['status'] === 'success') {
            $settings = $save_result['data'];
        } elseif ($save_result['status'] === 'error' && !empty($save_result['message'])) {
            $token_note      = $save_result['message'];
            $token_note_kind = 'error';
        }
    }

    // وضعیت توکن بر اساس نتیجه‌ی واقعی سرور (نه فقط «رشته‌ای ذخیره شده یا نه»)
    $has_api_key = !empty(ai_agent_get_api_key());
    $token_state = 'missing';
    if (is_array($save_result) && isset($save_result['status'])) {
        if ($save_result['status'] === 'success') {
            $token_state = 'connected';
        } elseif ($save_result['status'] === 'error') {
            $token_state = $has_api_key ? 'invalid' : 'missing';
        } else { // skipped — کلیدی ثبت نشده؛ حالت عادی نصب تازه
            $token_state = 'missing';
        }
    } elseif ($has_api_key) {
        $token_state = 'invalid';
    }

    $sync_types  = isset($settings['sync_types']) && is_array($settings['sync_types']) ? $settings['sync_types'] : array();
    $site_colors = function_exists('ai_agent_get_site_colors') ? ai_agent_get_site_colors() : array();

    $color_light = isset($settings['color_light']) ? $settings['color_light'] : '#F4865B';
    /*
    رنگ حالت تاریک در زمانِ رندرِ صفحه‌ی تنظیمات: اگر کاربر به‌صورت
    دستی رنگ تاریک را عوض کرده (color_dark_custom = 1)، همان مقدار
    ذخیره‌شده نشان داده می‌شود. در غیر این‌صورت، از همان فرمولِ auto
    ساخته می‌شود تا چیزی که کاربر در پیش‌نمایش می‌بیند با چیزی که روی
    سایت می‌نشیند یکی باشد.
    */
    $color_dark_custom_render = isset($settings['color_dark_custom']) ? (string) $settings['color_dark_custom'] : '0';
    $saved_color_dark_render  = isset($settings['color_dark']) ? sanitize_hex_color($settings['color_dark']) : '';
    if ($color_dark_custom_render === '1' && $saved_color_dark_render) {
        $color_dark = $saved_color_dark_render;
    } else {
        $color_dark = function_exists('ai_agent_lighten_hex')
            ? ai_agent_lighten_hex($color_light, AI_AGENT_DARK_LIFT)
            : $color_light;
    }

    $theme_mode    = isset($settings['theme_mode']) ? $settings['theme_mode'] : 'auto';
    $chat_bg_light = isset($settings['chat_bg_light']) ? $settings['chat_bg_light'] : '#FAF9F5';
    $chat_bg_dark  = isset($settings['chat_bg_dark']) ? $settings['chat_bg_dark'] : '#1F1E1D';

    // آخرین زمان‌های سینک (همان گزینه‌هایی که سینک موقع اتمام می‌نویسد)
    $last_sync_time     = get_option('ai_agent_last_sync_time', '');
    $last_sync_all_time = get_option('ai_agent_last_sync_all_time', '');

    // آخرین اجرای زمان‌بندی‌شده (WP-Cron) برای نمایش در آمار صفحه
    $last_scheduled = function_exists('ai_agent_get_last_scheduled_sync')
        ? ai_agent_get_last_scheduled_sync()
        : null;
    ?>
    <div class="ai-agent-app" dir="rtl">

        <header class="ai-agent-topbar">
            <div class="ai-agent-topbar-brand">
                <div class="ai-agent-brand-mark" aria-hidden="true">
                    <img src="<?php echo esc_url(AI_AGENT_URL . 'assets/images/favicon46x46.png'); ?>" alt="" />
                </div>
                <div class="ai-agent-brand-text">
                    <h1>دانیچَت</h1>
                    <span>پنل دستیار هوشمند سایت</span>
                </div>
            </div>
            <div class="ai-agent-topbar-tools">
                <div class="ai-agent-wallet-inline">
                    <span>موجودی کیف‌پول</span>
                    <strong id="ai-agent-wallet-balance-value" class="ai-agent-wallet-amount">—</strong>
                    <span id="ai-agent-wallet-balance-status" class="ai-agent-wallet-status"></span>
                    <button type="button" id="ai-agent-wallet-balance-refresh-btn" class="ai-agent-wallet-refresh" aria-label="به‌روزرسانی موجودی" title="به‌روزرسانی موجودی">
                        <svg class="ai-agent-sync-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                            <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                            <path d="M16 16h5v5"/>
                        </svg>
                    </button>
                    <?php wp_nonce_field('ai_agent_wallet_balance_nonce_action', 'ai_agent_wallet_balance_nonce_field'); ?>
                </div>
                <a class="ai-agent-text-btn" href="https://dunichat.ir/dashboard/wallet" target="_blank" rel="noopener">شارژ کیف‌پول</a>
            </div>
        </header>

        <?php
        /*
        دو نما، یک آدرس. کلیک روی هرکدام فقط کلاس is-active را جابه‌جا
        می‌کند؛ هیچ رفت‌وبرگشتی به سرور نیست. اگر از زیرمنوی «تاریخچه
        چت‌ها» یا با tab=history آمده باشیم، نمای گفت‌وگوها از اول باز است.
        */ ?>
        <nav class="ai-agent-tabs" role="tablist">
            <button type="button" class="ai-agent-tab<?php echo $initial_view === 'history' ? ' is-active' : ''; ?>" data-view="history" role="tab" aria-selected="<?php echo $initial_view === 'history' ? 'true' : 'false'; ?>">پشتیبانی و پیام‌ها</button>
            <button type="button" class="ai-agent-tab<?php echo $initial_view === 'settings' ? ' is-active' : ''; ?>" data-view="settings" role="tab" aria-selected="<?php echo $initial_view === 'settings' ? 'true' : 'false'; ?>">تنظیمات پلاگین</button>
        </nav>

        <!-- ================= نمای تنظیمات ================= -->
        <div class="ai-agent-view<?php echo $initial_view === 'settings' ? ' is-active' : ''; ?>" data-view-panel="settings">
        <form method="post" action="options.php">
            <?php settings_fields('ai_agent_settings_group'); ?>

            <div class="ai-agent-sheet">

                <!-- ---------- توکن سایت ---------- -->
                <section class="ai-agent-section">
                    <div class="ai-agent-section-head">
                        <h2>توکن سایت</h2>
                        <?php if ($token_state === 'connected') : ?>
                            <span class="ai-agent-badge ai-agent-badge-ok">متصل</span>
                        <?php elseif ($token_state === 'invalid') : ?>
                            <span class="ai-agent-badge ai-agent-badge-warn">کلید تأیید نشد</span>
                        <?php else : ?>
                            <span class="ai-agent-badge ai-agent-badge-warn">ثبت نشده</span>
                        <?php endif; ?>
                    </div>
                    <p class="ai-agent-section-intro">
                        توکن رو از پنل دانیچَت بردار و همین‌جا بچسبون. ثبت‌نام کن، سایتت رو
                        اضافه کن، توکنش رو کپی کن — بعد «ذخیره تنظیمات» رو بزن.
                    </p>
                    <div class="ai-agent-btn-row">
                        <?php
                        /*
                        مقدار ذخیره‌شده هیچ‌وقت در HTML چاپ نمی‌شود — نه حتی به‌صورت
                        password. فیلد همیشه خالی باز می‌شود و خالی ماندنش یعنی
                        «توکن را عوض نکن» (کلید قبلی دست‌نخورده باقی می‌ماند).

                        ذخیره‌ی توکن هم مثل بقیه‌ی تنظیمات فقط با دکمه‌ی
                        «ذخیره تنظیمات» انتهای فرم انجام می‌شود.

                        از این نسخه:
                        - دکمه‌ی چشم داخل خود فیلد (سمت راست) توکن را نمایش/
                          مخفی می‌کند.
                        - دکمه‌ی «حذف توکن» کنار فیلد است؛ با تأیید کاربر،
                          پرچم api_key_delete فعال می‌شود و بعد از «ذخیره
                          تنظیمات» کلید ذخیره‌شده در دیتابیس هم به‌طور کامل
                          پاک می‌شود.
                        - متن توکن چپ‌چین و LTR است (کلاس dc-ltr + lang="en").
                        */ ?>
                        <div class="ai-agent-token-wrap">
                            <input type="password" id="ai_agent_api_key" name="ai_agent_settings[api_key]"
                                   value="" class="ai-agent-input ai-agent-input-sm dc-ltr" lang="en"
                                   autocomplete="off" placeholder="sk_live_..." />
                            <button type="button" id="ai-agent-token-eye" class="ai-agent-token-eye"
                                    aria-label="نمایش توکن" title="نمایش توکن">
                                <svg class="ai-agent-token-eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="ai-agent-token-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            </button>
                        </div>
                        <button type="button" id="ai-agent-token-delete" class="ai-agent-btn ai-agent-btn-danger"
                                title="حذف کامل توکن ذخیره‌شده پس از ذخیره تنظیمات">
                            حذف توکن
                        </button>
                        <a class="ai-agent-btn" href="https://dunichat.ir/login" target="_blank" rel="noopener">دریافت توکن از دانیچَت</a>
                        <?php /* پرچم حذف توکن: با کلیک روی «حذف توکن» ۱ می‌شود؛ اگر کاربر پیش از ذخیره توکن جدیدی تایپ کند دوباره صفر می‌شود. */ ?>
                        <input type="hidden" id="ai_agent_api_key_delete" name="ai_agent_settings[api_key_delete]" value="0" />
                    </div>
                    <?php if ($token_note !== '') : ?>
                        <p class="ai-agent-note ai-agent-note-<?php echo esc_attr($token_note_kind); ?>"><?php echo esc_html($token_note); ?></p>
                    <?php endif; ?>
                </section>

                <!-- ---------- رنگ دستیار ---------- -->
                <section class="ai-agent-section">
                    <h2>رنگ دستیار</h2>
                    <p class="ai-agent-section-intro">
                        این رنگ روی دکمه‌ی شناور، هدر و دکمه‌ی ارسال می‌نشینه. موقع نصب از
                        رنگ اصلی خود‌سایتت خونده شده — هر وقت خواستی عوضش کن. رنگ حالت
                        تاریک رو خودمون از همین رنگ می‌سازیم.
                    </p>

                    <span class="ai-agent-label">رنگ پرایمری چت‌بات</span>

                    <?php if (!empty($site_colors)) : ?>
                        <div style="margin-top:12px;margin-bottom:16px">
                            <div class="ai-agent-sublabel">رنگ‌های سایت خودت (وردپرس<?php echo (did_action('elementor/loaded') || defined('ELEMENTOR_VERSION')) ? ' / المنتور' : ''; ?>)</div>
                            <div class="ai-agent-site-colors">
                                <?php foreach ($site_colors as $site_color) :
                                    $on = (strtoupper($site_color['hex']) === strtoupper($color_light)); ?>
                                    <button type="button" class="ai-agent-site-color-btn<?php echo $on ? ' is-active' : ''; ?>"
                                            data-hex="<?php echo esc_attr($site_color['hex']); ?>">
                                        <span class="ai-agent-site-color-dot" style="background:<?php echo esc_attr($site_color['hex']); ?>" aria-hidden="true"></span>
                                        <span class="ai-agent-site-color-text">
                                            <span class="ai-agent-site-color-name"><?php echo esc_html($site_color['label']); ?></span>
                                            <span class="ai-agent-site-color-hex dc-ltr" lang="en"><?php echo esc_html(strtoupper($site_color['hex'])); ?></span>
                                        </span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div style="margin-bottom:16px">
                        <div class="ai-agent-sublabel">یا از پالت آماده</div>
                        <div class="ai-agent-swatches" data-swatch-group="color_light">
                            <?php foreach (array('#C96442', '#F4865B', '#1F1E1D', '#7C5CFF', '#2563EB', '#0EA5E9', '#16A34A', '#D97706', '#DC2626') as $hex) :
                                $on = (strtoupper($hex) === strtoupper($color_light)); ?>
                                <button type="button" class="ai-agent-swatch<?php echo $on ? ' is-active' : ''; ?>"
                                        style="background:<?php echo esc_attr($hex); ?>"
                                        data-hex="<?php echo esc_attr($hex); ?>"
                                        title="<?php echo esc_attr($hex); ?>" aria-label="<?php echo esc_attr($hex); ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="ai-agent-inline-end">
                        <div class="ai-agent-field-group">
                            <label class="ai-agent-sublabel" for="ai_agent_color_light" style="margin:0">کد رنگ دلخواه</label>
                            <div class="ai-agent-inline" style="gap:8px">
                                <?php
                                /*
                                دایره‌ی رنگ: کلیک روی آن پالتِ ساخت رنگ مرورگر را باز
                                می‌کند تا کاربر بدون بلد بودن کد هگز، بتواند همان‌جا
                                رنگ دلخواهش را بسازد. خودِ input[type=color] بیرونِ
                                دید است و فقط دایره دیده می‌شود.
                                */ ?>
                                <button type="button" class="ai-agent-color-dot ai-agent-color-dot-btn" id="ai-agent-color-light-dot"
                                        style="background:<?php echo esc_attr($color_light); ?>"
                                        title="ساخت رنگ دلخواه" aria-label="ساخت رنگ دلخواه"></button>
                                <input type="color" id="ai-agent-color-picker" class="ai-agent-color-picker-input"
                                       value="<?php echo esc_attr($color_light); ?>" aria-label="ساخت رنگ دلخواه" tabindex="-1" />
                                <input type="text" class="ai-agent-input ai-agent-input-hex ai-agent-color-field dc-ltr" lang="en"
                                       id="ai_agent_color_light" name="ai_agent_settings[color_light]"
                                       value="<?php echo esc_attr(strtoupper($color_light)); ?>" placeholder="#F4865B" />
                            </div>
                        </div>
                    </div>

                    <?php
                    /*
                    ============================================
                    انتخاب رنگ حالت تاریک — با حفظِ رفتار خودکار

                    منطقِ قدیمی حفظ می‌شود: وقتی رنگِ روشن انتخاب می‌شود،
                    رنگِ تاریک به‌صورت خودکار از همان (با ضریب AI_AGENT_DARK_LIFT)
                    ساخته می‌شود. این رنگ به‌عنوان «پیشنهاد خودکار» به کاربر
                    نشان داده می‌شود.

                    حالا کاربر می‌تواند این پیشنهاد را بپذیرد (به‌طور پیش‌فرض
                    پذیرفته شده) یا با یکی از راه‌های زیر آن را تغییر دهد:
                      - پالت آماده
                      - دایره‌ی رنگ (پالت ساخت رنگ مرورگر)
                      - کد هگز دستی

                    یک فیلد مخفی color_dark_custom نشان می‌دهد آیا کاربر رنگ
                    را دستی عوض کرده یا هنوز روی پیشنهاد خودکار است. وقتی روی
                    خودکار است، با هر تغییرِ رنگِ روشن، رنگِ تاریک هم به‌صورت
                    خودکار به‌روز می‌شود.
                    ============================================
                    */
                    $color_dark_custom = isset($settings['color_dark_custom']) ? (string) $settings['color_dark_custom'] : '0';
                    $color_dark_value  = isset($settings['color_dark']) ? $settings['color_dark'] : $color_dark;
                    // اگر رنگ تاریک ذخیره‌شده با پیشنهاد خودکارِ فعلی یکی باشد،
                    // حالت auto در نظر گرفته می‌شود.
                    $auto_dark_check = function_exists('ai_agent_lighten_hex')
                        ? strtoupper(ai_agent_lighten_hex($color_light, AI_AGENT_DARK_LIFT))
                        : strtoupper($color_light);
                    if (strtoupper($color_dark_value) === $auto_dark_check) {
                        $color_dark_custom = '0';
                    }
                    ?>
                    <div class="ai-agent-dark-color-group" style="margin-top:24px">
                        <div class="ai-agent-dark-suggestion-row">
                            <span class="ai-agent-sublabel" style="margin:0; flex:0 0 auto;">پیشنهاد خودکار:</span>
                            <span class="ai-agent-color-dot ai-agent-color-dot-sm" id="ai-agent-dark-suggestion-dot"
                                  style="background:<?php echo esc_attr($color_dark); ?>" aria-hidden="true"></span>
                            <span id="ai-agent-dark-suggestion-value" class="dc-ltr" lang="en"><?php echo esc_html(strtoupper($color_dark)); ?></span>
                            <button type="button" id="ai-agent-dark-use-suggestion" class="ai-agent-text-link" title="استفاده از این پیشنهاد برای رنگ حالت تاریک">
                                استفاده از این پیشنهاد
                            </button>
                        </div>

                        <div style="margin-bottom:16px">
                            <div class="ai-agent-sublabel">پالت آماده برای حالت تاریک</div>
                            <div class="ai-agent-swatches" data-swatch-group="color_dark">
                                <?php foreach (array('#F0A68A', '#FFB088', '#9D8AFF', '#60A5FA', '#38BDF8', '#4ADE80', '#FBBF24', '#F87171', '#F4865B') as $hex) :
                                    $on = (strtoupper($hex) === strtoupper($color_dark_value)); ?>
                                    <button type="button" class="ai-agent-swatch<?php echo $on ? ' is-active' : ''; ?>"
                                            style="background:<?php echo esc_attr($hex); ?>"
                                            data-hex="<?php echo esc_attr($hex); ?>"
                                            title="<?php echo esc_attr($hex); ?>"
                                            aria-label="<?php echo esc_attr($hex); ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="ai-agent-inline" style="gap:8px">
                            <?php /* دایره‌ی رنگ برای حالت تاریک: کلیک روی آن پالتِ ساخت رنگ مرورگر را باز می‌کند. */ ?>
                            <button type="button" class="ai-agent-color-dot ai-agent-color-dot-btn" id="ai-agent-color-dark-dot-btn"
                                    style="background:<?php echo esc_attr($color_dark_value); ?>"
                                    title="ساخت رنگ دلخواه برای حالت تاریک"
                                    aria-label="ساخت رنگ دلخواه برای حالت تاریک"></button>
                            <input type="color" id="ai-agent-color-dark-picker" class="ai-agent-color-picker-input"
                                   value="<?php echo esc_attr($color_dark_value); ?>"
                                   aria-label="ساخت رنگ دلخواه برای حالت تاریک" tabindex="-1" />
                            <input type="text" class="ai-agent-input ai-agent-input-hex ai-agent-color-field dc-ltr" lang="en"
                                   id="ai_agent_color_dark" name="ai_agent_settings[color_dark]"
                                   value="<?php echo esc_attr(strtoupper($color_dark_value)); ?>"
                                   placeholder="<?php echo esc_attr(strtoupper($color_dark)); ?>" />
                        </div>

                        <?php /* فیلد مخفی: آیا کاربر رنگ تاریک را دستی عوض کرده (1) یا هنوز روی پیشنهاد خودکار است (0) */ ?>
                        <input type="hidden" id="ai_agent_color_dark_custom" name="ai_agent_settings[color_dark_custom]"
                               value="<?php echo esc_attr($color_dark_custom); ?>" />
                    </div>
                </section>

                <!-- ---------- تم صفحه‌ی چت ---------- -->
                <section class="ai-agent-section">
                    <h2>تم صفحه‌ی چت</h2>
                    <p class="ai-agent-section-intro">
                        در حالت «هماهنگ با سایت»، افزونه تم قالب رو تشخیص می‌ده و اگه
                        بازدیدکننده کلید شب/روزِ سایت رو بزنه، چت هم با همون عوض می‌شه.
                    </p>
                    <?php ai_agent_render_segmented('theme_mode', array(
                        'auto'  => array('هماهنگ با سایت', 'حالت پیش‌فرض'),
                        'light' => array('همیشه روشن', 'بدون توجه به سایت'),
                        'dark'  => array('همیشه تاریک', 'بدون توجه به سایت'),
                    ), $theme_mode, 'حالت نمایش چت'); ?>

                    <div class="ai-agent-grid" style="margin-top:24px">
                        <div>
                            <div class="ai-agent-label" style="margin-bottom:12px">پس‌زمینه‌ی چت در حالت روشن</div>
                            <?php ai_agent_render_swatches('chat_bg_light',
                                array('#FAF9F5', '#FFFFFF', '#F5F5F4', '#F1F5F9', '#FDF6F3', '#F7F7F2'),
                                $chat_bg_light); ?>
                        </div>
                        <div>
                            <div class="ai-agent-label" style="margin-bottom:12px">پس‌زمینه‌ی چت در حالت تاریک</div>
                            <?php ai_agent_render_swatches('chat_bg_dark',
                                array('#1F1E1D', '#000000', '#18181B', '#0F172A', '#221E1C', '#2A2724'),
                                $chat_bg_dark); ?>
                        </div>
                    </div>
                </section>

                <!-- ---------- مدت پاسخ‌گویی ---------- -->
                <section class="ai-agent-section">
                    <h2>مدت پاسخ‌گویی</h2>
                    <div class="ai-agent-field-row" style="margin-top:16px">
                        <label class="ai-agent-label" for="ai_agent_timeout">حداکثر زمان انتظار برای پاسخ</label>
                        <div class="ai-agent-inline">
                            <input type="number" min="1" step="1" class="ai-agent-input ai-agent-input-num dc-ltr" lang="en"
                                   name="ai_agent_settings[timeout]" id="ai_agent_timeout"
                                   value="<?php echo esc_attr($settings['timeout']); ?>" />
                            <span class="ai-agent-unit">ثانیه</span>
                        </div>
                    </div>
                </section>

                <!-- ---------- موقعیت آیکون افزونه ---------- -->
                <section class="ai-agent-section">
                    <h2>موقعیت آیکون افزونه</h2>
                    <div class="ai-agent-segmented" id="ai-agent-device-tabs" role="tablist" style="margin-top:16px">
                        <?php foreach (array('mobile' => 'موبایل', 'tablet' => 'تبلت', 'desktop' => 'دسکتاپ') as $device => $device_label) : ?>
                            <button type="button" class="ai-agent-segment ai-agent-device-tab<?php echo $device === 'mobile' ? ' is-active' : ''; ?>"
                                    data-device="<?php echo esc_attr($device); ?>" role="tab"
                                    aria-selected="<?php echo $device === 'mobile' ? 'true' : 'false'; ?>">
                                <span class="ai-agent-segment-title"><?php echo esc_html($device_label); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    /*
                    انتخاب موقعیت با ماکت تعاملی: کاربر دکمه‌ی شناور را روی
                    ماکتِ هر دستگاه می‌کشد (یا با کیبورد جابه‌جا می‌کند) و
                    مقدار نهایی در دو input مخفی با همان نام‌های قبلی
                    (button_position_side_{device} / button_position_offset_y_{device})
                    نگه داشته می‌شود؛ یعنی منطق ذخیره‌سازی و اعمال موقعیت
                    روی سایت دقیقاً همان است که بود.
                    */
                    $device_hints = array(
                        'mobile'  => 'عرض صفحه تا ۷۶۸ پیکسل. در موبایل پنجره‌ی چت تمام‌صفحه است و این تنظیم فقط روی دکمه‌ی شناور اثر دارد.',
                        'tablet'  => 'عرض صفحه بین ۷۶۹ تا ۱۰۲۴ پیکسل.',
                        'desktop' => 'عرض صفحه از ۱۰۲۵ پیکسل به بالا.',
                    );
                    foreach (array('mobile', 'tablet', 'desktop') as $device) :
                        $side_key   = 'button_position_side_' . $device;
                        $offset_key = 'button_position_offset_y_' . $device;
                        $side       = isset($settings[$side_key]) ? $settings[$side_key] : 'right';
                        $offset     = isset($settings[$offset_key]) ? intval($settings[$offset_key]) : 0;
                    ?>
                        <div class="ai-agent-device-panel<?php echo $device === 'mobile' ? ' is-active' : ''; ?>" data-device-panel="<?php echo esc_attr($device); ?>">
                            <input type="hidden" name="ai_agent_settings[<?php echo esc_attr($side_key); ?>]"
                                   id="ai_agent_<?php echo esc_attr($side_key); ?>"
                                   data-position-side="<?php echo esc_attr($device); ?>"
                                   value="<?php echo esc_attr($side); ?>" />
                            <input type="hidden" name="ai_agent_settings[<?php echo esc_attr($offset_key); ?>]"
                                   id="ai_agent_<?php echo esc_attr($offset_key); ?>"
                                   data-position-offset="<?php echo esc_attr($device); ?>"
                                   value="<?php echo esc_attr($offset); ?>" />

                            <div class="ai-agent-stage-wrap">
                                <div class="ai-agent-stage ai-agent-stage-<?php echo esc_attr($device); ?>"
                                     data-stage="<?php echo esc_attr($device); ?>"
                                     data-side="<?php echo esc_attr($side); ?>"
                                     data-offset="<?php echo esc_attr($offset); ?>">
                                    <span class="ai-agent-stage-line"></span>
                                    <span class="ai-agent-stage-line ai-agent-stage-line-short"></span>
                                    <span class="ai-agent-stage-line"></span>
                                    <span class="ai-agent-stage-line ai-agent-stage-line-shorter"></span>
                                    <button type="button" class="ai-agent-stage-handle" aria-label="جابه‌جایی دکمه‌ی شناور">
                                        <img src="<?php echo esc_url(AI_AGENT_URL . 'assets/images/favicon46x46.png'); ?>" alt="" />
                                    </button>
                                </div>
                                <p class="ai-agent-stage-readout" data-stage-readout="<?php echo esc_attr($device); ?>"></p>
                            </div>
                            <p class="ai-agent-hint" style="text-align:center"><?php echo esc_html($device_hints[$device]); ?></p>
                        </div>
                    <?php endforeach; ?>
                </section>

                <!-- ---------- منابع داده ---------- -->
                <section class="ai-agent-section">
                    <h2>منابع داده جهت همگام‌سازی</h2>
                    <div class="ai-agent-tiles" style="margin-top:16px">
                        <?php
                        $sources = array(
                            'posts'         => array('نوشته‌ها', 'Posts'),
                            'pages'         => array('برگه‌ها', 'Pages'),
                            'products'      => array('محصولات فروشگاه', 'WooCommerce Products'),
                            'product_cats'  => array('دسته‌بندی محصولات', 'Product Categories'),
                        );
                        foreach ($sources as $source => $meta) :
                            $on = in_array($source, $sync_types, true); ?>
                            <label class="ai-agent-tile<?php echo $on ? ' is-active' : ''; ?>">
                                <input type="checkbox" name="ai_agent_settings[sync_types][]" value="<?php echo esc_attr($source); ?>" <?php checked($on); ?> />
                                <span class="ai-agent-tile-box" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                                <span class="ai-agent-tile-text">
                                    <span class="ai-agent-tile-title"><?php echo esc_html($meta[0]); ?></span>
                                    <span class="ai-agent-tile-sub dc-ltr" lang="en"><?php echo esc_html($meta[1]); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <?php $images_on = !empty($settings['sync_images']); ?>
                        <label class="ai-agent-tile<?php echo $images_on ? ' is-active' : ''; ?>">
                            <input type="checkbox" name="ai_agent_settings[sync_images]" value="1" <?php checked($images_on); ?> />
                            <span class="ai-agent-tile-box" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                            <span class="ai-agent-tile-text">
                                <span class="ai-agent-tile-title">سینک تصاویر</span>
                                <span class="ai-agent-tile-sub">ارسال تصاویر هنگام همگام‌سازی</span>
                            </span>
                        </label>
                    </div>

                    <div style="margin-top:24px">
                        <div class="ai-agent-label" style="margin-bottom:6px">همگام‌سازی خودکار محتوا چت‌بات هوشمند</div>
                        <p class="ai-agent-hint" style="margin-bottom:12px">
                            برای اینکه چت‌بات همیشه آپدیت بمونه: هر بار محصولی یا مقاله‌ای در سایت
                            تغییر دادی، می‌تونی دستی «به‌روزرسانی محتوا» رو بزنی — یا همین
                            زمان‌بندی رو روشن بگذاری تا خودکار انجام بشه.
                        </p>
                        <?php ai_agent_render_segmented('sync_schedule', array(
                            'daily'        => 'روزانه',
                            'every_3_days' => 'هر سه روز',
                            'weekly'       => 'هفتگی',
                            'manual'       => 'فقط دستی',
                        ), isset($settings['sync_schedule']) ? $settings['sync_schedule'] : 'every_3_days', 'همگام‌سازی خودکار'); ?>
                        <input type="hidden" name="ai_agent_settings[sync_hour]" value="0" />
                        <p class="ai-agent-hint" style="margin-top:12px">
                            هر شب ساعت ۱۲ خودکار به‌روزرسانی می‌شه. اگه همین حالا می‌خوای،
                            دکمه‌ی «به‌روزرسانی محتوا» رو بزن.
                        </p>
                    </div>

                    <div class="ai-agent-field-row" style="margin-top:24px">
                        <label class="ai-agent-label" for="ai_agent_daily_message_limit">حداکثر پیام روزانه</label>
                        <div class="ai-agent-inline">
                            <input type="number" class="ai-agent-input ai-agent-input-num dc-ltr" lang="en" min="0"
                                   id="ai_agent_daily_message_limit" name="ai_agent_settings[daily_message_limit]"
                                   value="<?php echo esc_attr($settings['daily_message_limit']); ?>" />
                            <span class="ai-agent-unit">پیام در روز</span>
                        </div>
                    </div>
                </section>

                <!-- ---------- استعلام وضعیت و عملیات همگام‌سازی ---------- -->
                <section class="ai-agent-section">
                    <h2>استعلام وضعیت و عملیات همگام‌سازی</h2>
                    <p class="ai-agent-section-intro">
                        محتوای تازه یا تغییرکرده‌ی سایت رو برای دستیار می‌فرسته. هر بار محصول
                        یا نوشته‌ی جدید گذاشتی، دکمه‌ی «همگام‌سازی اطلاعات» رو بزن. «سینک تمامی
                        محتوا» همه‌چیز رو از نو پردازش می‌کنه و هزینه‌بره.
                    </p>

                    <div class="ai-agent-sync-grid">

                        <!-- ستون راست: عملیات -->
                        <div class="ai-agent-sync-actions">

                            <div class="ai-agent-sync-block">
                                <div class="ai-agent-sync-block-title">بازخوانی تنظیمات</div>
                                <div class="ai-agent-btn-row">
                                    <button type="button" id="ai-agent-reload-settings-btn" class="ai-agent-btn">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                        بارگذاری از سرور
                                    </button>
                                    <span id="ai-agent-reload-settings-status" class="ai-agent-status-text"></span>
                                    <?php wp_nonce_field('ai_agent_reload_settings_nonce_action', 'ai_agent_reload_settings_nonce_field'); ?>
                                </div>
                            </div>

                            <div class="ai-agent-sync-block">
                                <div class="ai-agent-sync-block-title">آخرین همگام‌سازی</div>
                                <div class="ai-agent-stat-grid">
                                    <div class="ai-agent-stat ai-agent-last-sync-item" data-sync-slot="last">
                                        <span class="ai-agent-stat-label">سینک افزایشی</span>
                                        <span class="ai-agent-stat-value ai-agent-last-sync-value<?php echo empty($last_sync_time) ? ' is-empty' : ''; ?>"><?php
                                            echo !empty($last_sync_time) ? esc_html(ai_agent_format_jalali_datetime($last_sync_time)) : 'ثبت نشده';
                                        ?></span>
                                    </div>
                                    <div class="ai-agent-stat ai-agent-last-sync-item" data-sync-slot="all">
                                        <span class="ai-agent-stat-label">سینک کامل</span>
                                        <span class="ai-agent-stat-value ai-agent-last-sync-value<?php echo empty($last_sync_all_time) ? ' is-empty' : ''; ?>"><?php
                                            echo !empty($last_sync_all_time) ? esc_html(ai_agent_format_jalali_datetime($last_sync_all_time)) : 'ثبت نشده';
                                        ?></span>
                                    </div>
                                    <div class="ai-agent-stat">
                                        <span class="ai-agent-stat-label">آخرین اجرای خودکار</span>
                                        <span class="ai-agent-stat-value<?php echo empty($last_scheduled['time']) ? ' is-empty' : ''; ?>"><?php
                                            echo !empty($last_scheduled['time']) ? esc_html(ai_agent_format_jalali_datetime($last_scheduled['time'])) : 'هنوز اجرا نشده';
                                        ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="ai-agent-sync-block">
                                <div class="ai-agent-sync-block-title">عملیات نهایی داده‌ها</div>
                                <div class="ai-agent-btn-row">
                                    <button type="button" id="ai-agent-sync-btn" class="ai-agent-btn ai-agent-btn-primary">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                        همگام‌سازی اطلاعات
                                    </button>
                                    <span id="ai-agent-sync-status" class="ai-agent-status-text"></span>
                                    <?php wp_nonce_field('ai_agent_sync_nonce_action', 'ai_agent_sync_nonce_field'); ?>
                                </div>
                                <div class="ai-agent-btn-row" style="margin-top:10px">
                                    <button type="button" id="ai-agent-sync-all-btn" class="ai-agent-btn ai-agent-btn-danger-outline">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                                        سینک تمامی محتوا
                                    </button>
                                    <span id="ai-agent-sync-all-status" class="ai-agent-status-text"></span>
                                    <?php wp_nonce_field('ai_agent_sync_all_nonce_action', 'ai_agent_sync_all_nonce_field'); ?>
                                </div>
                            </div>

                        </div>

                        <!-- ستون چپ: نمودار وضعیت + استعلام -->
                        <div class="ai-agent-sync-chart">
                            <div class="ai-agent-chart-wrap">
                                <canvas id="ai-agent-status-chart" height="220"></canvas>
                            </div>
                            <div class="ai-agent-btn-row" style="margin-top:10px">
                                <button type="button" id="ai-agent-check-status-btn" class="ai-agent-btn">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                    استعلام وضعیت
                                </button>
                                <span id="ai-agent-check-status-status" class="ai-agent-status-text"></span>
                                <?php wp_nonce_field('ai_agent_sync_status_nonce_action', 'ai_agent_sync_status_nonce_field'); ?>
                            </div>
                        </div>

                    </div>
                </section>

            </div>

            <!-- ---------- نوار ذخیره ---------- -->
            <?php
            /*
            تنظیمات خودکار ذخیره نمی‌شوند. همه‌ی فیلدهای این فرم فقط با
            کلیک روی دکمه‌ی «ذخیره تنظیمات» ثبت می‌شوند (ارسال فرم به
            options.php — همان مسیر استاندارد وردپرس).
            */ ?>
            <div class="ai-agent-savebar">
                <div class="ai-agent-savebar-inner">
                    <span class="ai-agent-savebar-hint">تغییرات فقط با کلیک روی «ذخیره تنظیمات» اعمال می‌شوند.</span>
                    <button type="submit" class="ai-agent-btn ai-agent-btn-primary ai-agent-savebar-btn">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        ذخیره تنظیمات
                    </button>
                </div>
            </div>
        </form>
        </div>

        <!-- ================= نمای گفت‌وگوها ================= -->
        <div class="ai-agent-view<?php echo $initial_view === 'history' ? ' is-active' : ''; ?>" data-view-panel="history">
            <?php wp_nonce_field('ai_agent_chat_sessions_nonce_action', 'ai_agent_chat_sessions_nonce_field'); ?>
            <div class="ai-agent-sheet">
                <section class="ai-agent-section">
                    <h2>گفت‌وگوها و پشتیبانی</h2>
                    <p class="ai-agent-section-intro">
                        هر گفت‌وگویی که بازدیدکننده‌ها با دستیار داشته‌اند این‌جاست. اگر کسی
                        پشتیبان انسانی خواسته، با برچسب «در انتظار پشتیبان» بالا می‌آید و
                        می‌توانی همان‌جا جوابش را بدهی.
                    </p>

                    <div class="ai-agent-filters" id="ai-agent-status-filters">
                        <button type="button" class="ai-agent-filter-btn is-active" data-status="">
                            همه <span class="ai-agent-filter-count" hidden data-count-status="">0</span>
                        </button>
                        <button type="button" class="ai-agent-filter-btn" data-status="pending_human">
                            در انتظار پشتیبان <span class="ai-agent-filter-count" hidden data-count-status="pending_human">0</span>
                        </button>
                        <button type="button" class="ai-agent-filter-btn" data-status="human">
                            پشتیبان <span class="ai-agent-filter-count" hidden data-count-status="human">0</span>
                        </button>
                        <button type="button" class="ai-agent-filter-btn" data-status="bot">
                            ربات <span class="ai-agent-filter-count" hidden data-count-status="bot">0</span>
                        </button>
                        <button type="button" class="ai-agent-filter-btn" data-status="closed">
                            بسته‌شده <span class="ai-agent-filter-count" hidden data-count-status="closed">0</span>
                        </button>
                    </div>

                    <div class="ai-agent-sessions-toolbar">
                        <div class="ai-agent-sessions-page-size">
                            <label for="ai-agent-sessions-per-page">نمایش در صفحه:</label>
                            <select id="ai-agent-sessions-per-page">
                                <option value="5">۵</option>
                                <option value="10" selected>۱۰</option>
                                <option value="20">۲۰</option>
                                <option value="50">۵۰</option>
                                <option value="100">۱۰۰</option>
                            </select>
                        </div>
                        <span id="ai-agent-sessions-page-info"></span>
                        <div class="ai-agent-sessions-page-nav">
                            <span id="ai-agent-sessions-total-info"></span>
                            <button type="button" id="ai-agent-sessions-prev-btn" class="ai-agent-page-btn" disabled aria-label="صفحه قبلی">‹</button>
                            <button type="button" id="ai-agent-sessions-next-btn" class="ai-agent-page-btn" disabled aria-label="صفحه بعدی">›</button>
                        </div>
                    </div>

                    <div id="ai-agent-sessions-loading" class="ai-agent-sessions-loading" style="display:none;">در حال بارگذاری...</div>
                    <div id="ai-agent-sessions-error" class="ai-agent-sessions-error" style="display:none;"></div>
                    <?php
                    /*
                    فهرست تا وقتی این نما باز نشده گرفته نمی‌شود، پس جای
                    خالی‌اش نباید یک شکاف بی‌توضیح باشد. اگر توکنی هم ثبت
                    نشده، اصلاً گفت‌وگویی وجود ندارد که بیاید.
                    */ ?>
                    <div id="ai-agent-sessions-list" class="ai-agent-sessions-list">
                        <div class="ai-agent-empty"><?php
                            echo $has_api_key
                                ? 'در حال آماده‌سازی فهرست گفت‌وگوها…'
                                : 'کلید API خودتون رو وارد و ذخیره کنین تا گفت‌وگوها این‌جا بیاد.';
                        ?></div>
                    </div>

                    <div class="ai-agent-sessions-toolbar">
                        <div class="ai-agent-sessions-page-size">
                            <label for="ai-agent-sessions-per-page-bottom">نمایش در صفحه:</label>
                            <select id="ai-agent-sessions-per-page-bottom">
                                <option value="5">۵</option>
                                <option value="10" selected>۱۰</option>
                                <option value="20">۲۰</option>
                                <option value="50">۵۰</option>
                                <option value="100">۱۰۰</option>
                            </select>
                        </div>
                        <div class="ai-agent-sessions-page-nav">
                            <button type="button" id="ai-agent-sessions-prev-btn-bottom" class="ai-agent-page-btn" disabled aria-label="صفحه قبلی">‹</button>
                            <button type="button" id="ai-agent-sessions-next-btn-bottom" class="ai-agent-page-btn" disabled aria-label="صفحه بعدی">›</button>
                        </div>
                    </div>
                </section>
            </div>
        </div>

    </div>
    <?php
}

/*
==========================================================================
گزینه‌های ثابتِ صفحه‌ی تنظیمات — توابع کمکی رندر (از طراحی جدید)
==========================================================================
*/

/**
 * یک گروه دکمه‌ی رادیویی (سگمنت).
 *
 * $options: value => [title, sub]  — sub اختیاری است.
 */
function ai_agent_render_segmented($name, $options, $current, $aria_label = ''){
    ?>
    <div class="ai-agent-segmented" role="radiogroup"<?php echo $aria_label ? ' aria-label="' . esc_attr($aria_label) . '"' : ''; ?>>
        <?php foreach ($options as $value => $meta) :
            $title = is_array($meta) ? $meta[0] : $meta;
            $sub   = (is_array($meta) && isset($meta[1])) ? $meta[1] : '';
            $on    = ((string) $current === (string) $value);
        ?>
            <label class="ai-agent-segment<?php echo $on ? ' is-active' : ''; ?>">
                <input type="radio" name="ai_agent_settings[<?php echo esc_attr($name); ?>]"
                       value="<?php echo esc_attr($value); ?>" <?php checked($on); ?> />
                <span class="ai-agent-segment-title"><?php echo esc_html($title); ?></span>
                <?php if ($sub !== '') : ?>
                    <span class="ai-agent-segment-sub"><?php echo esc_html($sub); ?></span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
}

/** یک ردیف سوییچ رنگ گرد. مقدار انتخاب‌شده در یک input مخفی می‌نشیند. */
function ai_agent_render_swatches($name, $palette, $current){
    $current = strtoupper((string) $current);
    ?>
    <div class="ai-agent-swatches" data-swatch-group="<?php echo esc_attr($name); ?>">
        <?php foreach ($palette as $hex) :
            $on = (strtoupper($hex) === $current);
        ?>
            <button type="button"
                    class="ai-agent-swatch<?php echo $on ? ' is-active' : ''; ?>"
                    style="background:<?php echo esc_attr($hex); ?>"
                    data-hex="<?php echo esc_attr($hex); ?>"
                    title="<?php echo esc_attr(strtoupper($hex)); ?>"
                    aria-label="<?php echo esc_attr(strtoupper($hex)); ?>"></button>
        <?php endforeach; ?>
    </div>
    <input type="hidden" name="ai_agent_settings[<?php echo esc_attr($name); ?>]"
           id="ai_agent_<?php echo esc_attr($name); ?>"
           value="<?php echo esc_attr($current); ?>" />
    <?php
}

/*
==========================================================================
بارگذاری استایل/اسکریپت صفحه‌ی تنظیمات — برای صفحه‌ی اصلی
(ai-agent-settings) و زیرمنوی تاریخچه چت‌ها (ai-agent-settings-history)

- دیزاین‌سیستم جدید: dunichat-admin.css + لایه‌ی مکمل برای بخش‌هایی
  که از نسخه‌ی قبلی حفظ شده‌اند (کمبوباکس مدل، لیست وضعیت سرور،
  نمودار دایره‌ای وضعیت و نوار ذخیره)
- Chart.js برای نمودار دایره‌ای «استعلام وضعیت» لازم است.
==========================================================================
*/
function ai_agent_admin_enqueue($hook){
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    if (!in_array($page, array('ai-agent-settings', 'ai-agent-settings-history'), true)) return;

    wp_enqueue_style(
        'ai-agent-admin-css',
        AI_AGENT_URL . 'assets/css/dunichat-admin.css',
        array(),
        AI_AGENT_VERSION
    );

    wp_enqueue_style(
        'ai-agent-admin-compat-css',
        AI_AGENT_URL . 'assets/css/dunichat-admin-compat.css',
        array('ai-agent-admin-css'),
        AI_AGENT_VERSION
    );

    wp_enqueue_script('ai-agent-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true);

    wp_enqueue_script(
        'ai-agent-settings-js',
        AI_AGENT_URL . 'assets/js/settings.js',
        array('jquery', 'ai-agent-chartjs'),
        AI_AGENT_VERSION,
        true
    );

    /*
    ضریب روشن‌کردن رنگ برند برای پیش‌نمایش رنگ حالت تاریک در صفحه.
    */
    wp_localize_script('ai-agent-settings-js', 'aiAgentAdmin', array(
        'darkLift' => AI_AGENT_DARK_LIFT,
    ));
}
add_action('admin_enqueue_scripts', 'ai_agent_admin_enqueue');
