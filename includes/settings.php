<?php

if (!defined('ABSPATH')) exit;

function ai_agent_get_settings(){
    $defaults = array(
        'model'               => 'tencent/hy3:free',
        'color'               => '#F4865B',
        // ====== رنگ دستیار (دو رنگ مستقل برای حالت لایت و دارک) ======
        // color_light: رنگ ویجت وقتی چت در حالت روشن (Light) است
        // color_dark : رنگ ویجت وقتی چت در حالت تاریک (Dark) است
        'color_light'         => '#F4865B',
        'color_dark'          => '#F4865B',
        'timeout'             => 15,
        'sync_types'          => array(), // فیلد آرایه‌ای برای چک‌باکس‌ها
        'api_key'             => '',      // کلید API کاربر برای احراز هویت با سرور همگام‌سازی

        /*
        ============================================
        شخصیت دستیار

        دیگر پرامپت سیستمی آزاد وجود ندارد. کاربر لحن و میزان استفاده از
        ایموجی را انتخاب می‌کند و چند فیلد اطلاعاتی کوتاه پر می‌کند؛ متن
        دستورالعملی که به مدل داده می‌شود روی سرور از همین مقادیر ساخته
        می‌شود. دلیلش امنیتی است: یک فیلد متنی آزاد در بالاترین سطح
        اعتماد گفت‌وگو، مستقیم‌ترین راه برای دست‌کاری رفتار دستیار است و
        دسترسی به پیشخوان وردپرس برای سوءاستفاده از آن کافی بود.
        ============================================
        */
        'assistant_tone'      => 'neutral', // formal | professional | neutral | friendly | warm
        'emoji_usage'         => 'low',     // none | low | medium | high
        'organization_name'   => '',
        'business_field'      => '',
        'business_description'=> '',
        'support_phones'      => array(),   // حداکثر ۵ شماره
        'telegram_id'         => '',
        'instagram_id'        => '',

        /*
        ============================================
        همگام‌سازی زمان‌بندی‌شده

        محتوای سایت هر چند وقت یک‌بار به‌صورت خودکار دوباره ایندکس می‌شود
        تا پاسخ‌ها قدیمی نشوند. پیش‌فرض «هر سه روز» است: به‌اندازه‌ی کافی
        تازه برای یک فروشگاه معمولی، بدون اینکه هزینه‌ی embedding را
        بی‌دلیل بالا ببرد.
        ============================================
        */
        'sync_schedule'       => 'every_3_days', // manual | daily | every_3_days | weekly
        'sync_hour'           => 3,              // ساعت اجرای همگام‌سازی خودکار (۰ تا ۲۳)
        'daily_message_limit' => 0,       // حداکثر پیام روزانه (قابل ویرایش کاربر و ارسال به سرور)
        'allowed_statuses'    => array(), // وضعیت‌های مجاز برای هر نوع محتوا (از سرور همگام‌سازی)
        'sync_images'         => false,   // آیا تصاویر محتوا هنگام سینک ارسال شوند؟ (allow-image / deny-image)

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

    // چون لیست مدل‌ها به‌صورت پویا از API خارجی خوانده می‌شود، آرایه ثابتی برای اعتبارسنجی وجود ندارد
    $output['model'] = (isset($input['model']) && trim($input['model']) !== '') ? sanitize_text_field($input['model']) : 'tencent/hy3:free';

    /*
    ============================================
    رنگ دستیار — دو رنگ مستقل برای حالت لایت و دارک

    color_light: رنگ ویجت در حالت روشن (Light)
    color_dark : رنگ ویجت در حالت تاریک (Dark)

    اگر کاربر رنگی را پاک کند (خالی بفرستد)، مقدار قبلی ذخیره‌شده
    حفظ می‌شود؛ و اگر مقدار قبلی هم نبود، رنگ پیش‌فرض برند اعمال می‌شود.
    کلید قدیمی color نیز برای سازگاری با نسخه‌های قبلی معتبر می‌ماند
    و همیشه با مقدار color_light همگام نگه داشته می‌شود.
    ============================================
    */
    $color_light = isset($input['color_light']) ? sanitize_hex_color($input['color_light']) : '';
    if (!$color_light) {
        $color_light = (isset($old['color_light']) && sanitize_hex_color($old['color_light'])) ? sanitize_hex_color($old['color_light']) : '#F4865B';
    }
    $output['color_light'] = $color_light;

    $color_dark = isset($input['color_dark']) ? sanitize_hex_color($input['color_dark']) : '';
    if (!$color_dark) {
        $color_dark = (isset($old['color_dark']) && sanitize_hex_color($old['color_dark'])) ? sanitize_hex_color($old['color_dark']) : '#F4865B';
    }
    $output['color_dark'] = $color_dark;

    // کلید قدیمی color برای سازگاری (معادل رنگ حالت روشن)
    $output['color'] = $color_light;

    $timeout = isset($input['timeout']) ? intval($input['timeout']) : 15;
    $output['timeout'] = $timeout > 0 ? $timeout : 15;

    // پاکسازی آرایه چک‌باکس‌های سینک
    $output['sync_types'] = (isset($input['sync_types']) && is_array($input['sync_types'])) ? array_map('sanitize_text_field', $input['sync_types']) : array();

    /*
    ============================================
    شخصیت دستیار

    هر مقدار در برابر یک فهرست ثابت بررسی می‌شود؛ ورودی نامعتبر به
    مقدار قبلی یا پیش‌فرض برمی‌گردد، نه اینکه ذخیره شود. فیلدهای متنی
    هم روی سرور دوباره پاک‌سازی می‌شوند — این‌جا فقط طول و نوع کنترل
    می‌شود.
    ============================================
    */
    $allowed_tones = array('formal', 'professional', 'neutral', 'friendly', 'warm');
    $tone = isset($input['assistant_tone']) ? sanitize_text_field($input['assistant_tone']) : '';
    if (!in_array($tone, $allowed_tones, true)) {
        $tone = (isset($old['assistant_tone']) && in_array($old['assistant_tone'], $allowed_tones, true))
            ? $old['assistant_tone']
            : 'neutral';
    }
    $output['assistant_tone'] = $tone;

    $allowed_emoji = array('none', 'low', 'medium', 'high');
    $emoji = isset($input['emoji_usage']) ? sanitize_text_field($input['emoji_usage']) : '';
    if (!in_array($emoji, $allowed_emoji, true)) {
        $emoji = (isset($old['emoji_usage']) && in_array($old['emoji_usage'], $allowed_emoji, true))
            ? $old['emoji_usage']
            : 'low';
    }
    $output['emoji_usage'] = $emoji;

    $output['organization_name']    = isset($input['organization_name'])
        ? mb_substr(sanitize_text_field($input['organization_name']), 0, 200) : '';
    $output['business_field']       = isset($input['business_field'])
        ? mb_substr(sanitize_text_field($input['business_field']), 0, 200) : '';
    $output['business_description'] = isset($input['business_description'])
        ? mb_substr(sanitize_text_field($input['business_description']), 0, 500) : '';

    /*
    Support numbers arrive as one textarea, one per line: a repeatable field
    was more UI than the case deserves for at most five short values. Blank
    lines are dropped so a trailing newline does not become an empty number
    the assistant would then quote.
    */
    $phones = array();
    if (isset($input['support_phones'])) {
        $raw_phones = is_array($input['support_phones'])
            ? $input['support_phones']
            : preg_split('/[\r\n,]+/u', (string) $input['support_phones']);
        foreach ((array) $raw_phones as $raw_phone) {
            $phone = trim(ai_agent_en_digits(sanitize_text_field($raw_phone)));
            $phone = preg_replace('/[^0-9+\- ]/', '', $phone);
            $phone = trim(preg_replace('/\s+/', ' ', $phone));
            if ($phone !== '') {
                $phones[] = mb_substr($phone, 0, 32);
            }
            if (count($phones) >= 5) break;
        }
    }
    $output['support_phones'] = $phones;

    // Handles are stored without the leading @, which is added at display time.
    $output['telegram_id']  = isset($input['telegram_id'])
        ? mb_substr(preg_replace('/[^A-Za-z0-9_.]/', '', ltrim(sanitize_text_field($input['telegram_id']), '@')), 0, 100) : '';
    $output['instagram_id'] = isset($input['instagram_id'])
        ? mb_substr(preg_replace('/[^A-Za-z0-9_.]/', '', ltrim(sanitize_text_field($input['instagram_id']), '@')), 0, 100) : '';

    /*
    ============================================
    همگام‌سازی زمان‌بندی‌شده
    ============================================
    */
    $allowed_schedules = array('manual', 'daily', 'every_3_days', 'weekly');
    $schedule = isset($input['sync_schedule']) ? sanitize_text_field($input['sync_schedule']) : '';
    if (!in_array($schedule, $allowed_schedules, true)) {
        $schedule = (isset($old['sync_schedule']) && in_array($old['sync_schedule'], $allowed_schedules, true))
            ? $old['sync_schedule']
            : 'every_3_days';
    }
    $output['sync_schedule'] = $schedule;

    $sync_hour = isset($input['sync_hour']) ? intval(ai_agent_en_digits($input['sync_hour'])) : 3;
    $output['sync_hour'] = min(23, max(0, $sync_hour));

    /*
    ============================================
    API Key

    خالی بودن این فیلد یعنی «تغییری نده». صفحه‌ی تنظیمات هیچ‌وقت کلید
    ذخیره‌شده را در HTML چاپ نمی‌کند (نه حتی به‌صورت password)، چون هم
    نشتی غیرضروری است و هم باعث می‌شد مرورگر آن را با پسورد ذخیره‌شده‌ی
    وردپرس پر کند و کاربر کلیدی ببیند که هرگز وارد نکرده بود.
    ============================================
    */
    $submitted_key = isset($input['api_key']) ? trim(sanitize_text_field($input['api_key'])) : '';
    if ($submitted_key !== '') {
        $output['api_key'] = $submitted_key;
    } else {
        $output['api_key'] = isset($old['api_key']) ? $old['api_key'] : '';
    }

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

    // allowed_statuses همچنان تنها از سرور همگام‌سازی پر می‌شود (این‌جا فقط حفظ مقدار قبلی)
    $output['allowed_statuses']    = isset($old['allowed_statuses']) && is_array($old['allowed_statuses']) ? $old['allowed_statuses'] : array();

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
  ۴) در صورت موفقیت، مقادیر دریافتی (selected_model، فیلدهای شخصیت
     دستیار، allowed_content_types، allowed_statuses و daily_message_limit)
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
        isset($remote['assistant_tone']) ||
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
    foreach (array('assistant_tone', 'emoji_usage', 'sync_schedule') as $enum_key) {
        if (isset($remote[$enum_key]) && is_string($remote[$enum_key]) && $remote[$enum_key] !== '') {
            $settings[$enum_key] = $remote[$enum_key];
        }
    }
    foreach (array('organization_name', 'business_field', 'business_description', 'telegram_id', 'instagram_id') as $text_key) {
        if (array_key_exists($text_key, $remote)) {
            // The server sanitises these, so what comes back is what the
            // assistant will actually quote -- show that, not the draft.
            $settings[$text_key] = is_string($remote[$text_key]) ? $remote[$text_key] : '';
        }
    }
    if (isset($remote['support_phones']) && is_array($remote['support_phones'])) {
        $settings['support_phones'] = array_map('sanitize_text_field', $remote['support_phones']);
    }
    if (isset($remote['sync_hour'])) {
        $settings['sync_hour'] = min(23, max(0, intval($remote['sync_hour'])));
    }
    if (isset($remote['response_timeout_seconds'])) {
        $settings['timeout'] = max(1, intval($remote['response_timeout_seconds']));
    }
    if (isset($remote['last_full_sync_at']) && is_string($remote['last_full_sync_at'])) {
        $settings['last_full_sync_at'] = $remote['last_full_sync_at'];
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
        'allowed_content_types' => ai_agent_unmap_content_types(isset($value['sync_types']) ? $value['sync_types'] : array()),
        'allowed_statuses'      => $existing_allowed_statuses,
        'daily_message_limit'   => isset($value['daily_message_limit']) ? intval($value['daily_message_limit']) : 0,

        // The assistant persona. There is no system_prompt key: the server
        // composes the instruction text from exactly these fields.
        'assistant_tone'        => isset($value['assistant_tone']) ? (string) $value['assistant_tone'] : 'neutral',
        'emoji_usage'           => isset($value['emoji_usage']) ? (string) $value['emoji_usage'] : 'low',
        'organization_name'     => isset($value['organization_name']) ? (string) $value['organization_name'] : '',
        'business_field'        => isset($value['business_field']) ? (string) $value['business_field'] : '',
        'business_description'  => isset($value['business_description']) ? (string) $value['business_description'] : '',
        'support_phones'        => isset($value['support_phones']) && is_array($value['support_phones']) ? array_values($value['support_phones']) : array(),
        'telegram_id'           => isset($value['telegram_id']) ? (string) $value['telegram_id'] : '',
        'instagram_id'          => isset($value['instagram_id']) ? (string) $value['instagram_id'] : '',

        'response_timeout_seconds' => isset($value['timeout']) ? max(5, min(120, intval($value['timeout']))) : 15,
        'sync_schedule'         => isset($value['sync_schedule']) ? (string) $value['sync_schedule'] : 'every_3_days',
        'sync_hour'             => isset($value['sync_hour']) ? intval($value['sync_hour']) : 3,
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
                        <span class="ai-agent-wallet-label">موجودی کیف‌پول</span>
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
                <a class="ai-agent-btn ai-agent-btn-primary ai-agent-topbar-cta" href="https://dunichat.ir/dashboard/wallet" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    شارژ کیف‌پول
                </a>
            </div>
        </header>

        <?php
        /*
        ============================================
        نوار اعلان‌ها

        اعلان‌های دانیچَت (از جمله تغییر خودکار قیمت مدل‌ها به دنبال
        تغییر نرخ تتر) این‌جا نمایش داده می‌شوند. قیمت‌ها بدون اطلاع
        صاحب سایت تغییر می‌کردند و اولین جایی که متوجه می‌شد، صورت‌حساب
        بود.
        ============================================
        */
        ?>
        <div id="ai-agent-announcements" class="ai-agent-announcements" hidden>
            <div class="ai-agent-announcements-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11h3l7-5v12l-7-5H3z"/><path d="M17 8a5 5 0 0 1 0 8"/></svg>
            </div>
            <div class="ai-agent-announcements-body">
                <span id="ai-agent-announcement-title" class="ai-agent-announcement-title"></span>
                <span id="ai-agent-announcement-date" class="ai-agent-announcement-date"></span>
            </div>
            <div id="ai-agent-announcement-dots" class="ai-agent-announcement-dots"></div>
        </div>

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

                    <?php
                    /*
                    نوار ذخیره بالای فرم است و همان‌جا می‌چسبد؛ نسخه‌ی
                    تکراری‌اش ته صفحه حذف شد. این فرم بلند است و کاربر
                    بعد از هر تغییر باید تا انتها اسکرول می‌کرد تا دکمه‌ی
                    ذخیره را پیدا کند.

                    دکمه هم دکمه‌ی خودمان است، نه submit_button() وردپرس.
                    آن تابع کلاس button-primary را می‌گذارد که آبیِ
                    پیش‌فرض پیشخوان است و وسط یک پنل نارنجی وصله می‌زد.
                    */
                    ?>
                    <div class="ai-agent-sticky-actions">
                        <span class="ai-agent-sticky-actions-hint">پس از هر تغییر، تنظیمات را ذخیره کنید.</span>
                        <button type="submit" name="submit" class="ai-agent-btn ai-agent-btn-primary">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            ذخیره تنظیمات افزونه
                        </button>
                    </div>

                    <?php
                    /*
                    از این‌جا تا انتهای فرم یک کارت واحد است. قبلاً هر بخش
                    کارت جداگانه‌ای با حاشیه و سایه‌ی خودش بود؛ صفحه به هفت
                    جزیره‌ی شناور تقسیم می‌شد که هیچ‌کدام بر دیگری ارجحیتی
                    نداشت و چشم مجبور بود هر بار از نو شروع کند. حالا یک
                    ورق پیوسته است و بخش‌ها فقط با یک خط از هم جدا می‌شوند.

                    نوار ذخیره بیرونِ ورق مانده، چون چسبنده است و باید
                    هنگام اسکرول روی ورق بایستد.
                    */
                    ?>
                    <div class="ai-agent-sheet">

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
                                    <?php $last_scheduled = ai_agent_get_last_scheduled_sync(); ?>
                                    <div class="ai-agent-last-sync-grid">
                                        <div class="ai-agent-last-sync-item">
                                            <span class="ai-agent-last-sync-label">آخرین به‌روزرسانی</span>
                                            <span class="ai-agent-last-sync-value"><?php echo !empty($last_sync_time) ? esc_html($last_sync_time) : '<span class="ai-agent-muted-placeholder">ثبت نشده</span>'; ?></span>
                                        </div>
                                        <div class="ai-agent-last-sync-item">
                                            <span class="ai-agent-last-sync-label">ایندکس کامل</span>
                                            <span class="ai-agent-last-sync-value"><?php echo !empty($last_sync_all_time) ? esc_html($last_sync_all_time) : '<span class="ai-agent-muted-placeholder">ثبت نشده</span>'; ?></span>
                                        </div>
                                        <div class="ai-agent-last-sync-item">
                                            <span class="ai-agent-last-sync-label">آخرین اجرای خودکار</span>
                                            <span class="ai-agent-last-sync-value">
                                                <?php
                                                if ($last_scheduled && !empty($last_scheduled['time'])) {
                                                    echo esc_html(ai_agent_fa_digits($last_scheduled['time']));
                                                    if ($last_scheduled['status'] !== 'success') {
                                                        echo ' — <span class="ai-agent-muted-placeholder">' . esc_html($last_scheduled['message']) . '</span>';
                                                    }
                                                } else {
                                                    echo '<span class="ai-agent-muted-placeholder">هنوز اجرا نشده</span>';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <?php
                                /*
                                قبلاً دو دکمه‌ی جدا بود و تفاوتشان روشن نبود:
                                یکی فقط محتوای تغییرکرده را می‌فرستاد و دیگری
                                همه چیز را دوباره. حالا عمل اصلی یکی است و
                                ایندکس کامل پشت یک بازشو قرار گرفته، چون
                                کاری پرهزینه و به‌ندرت لازم است.
                                */
                                ?>
                                <div class="ai-agent-sync-block">
                                    <div class="ai-agent-sync-block-title">به‌روزرسانی محتوا</div>
                                    <p class="ai-agent-field-hint">
                                        محتوای جدید یا تغییرکرده‌ی سایت را برای دستیار می‌فرستد. هر بار محصول یا
                                        نوشته‌ی تازه‌ای اضافه کردید، همین دکمه را بزنید — یا زمان‌بندی خودکار را
                                        روشن بگذارید.
                                    </p>
                                    <div class="ai-agent-sync-block-actions">
                                        <button type="button" id="ai-agent-sync-btn" class="ai-agent-btn ai-agent-btn-primary">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                            به‌روزرسانی محتوا
                                        </button>
                                        <span id="ai-agent-sync-status" class="ai-agent-status-text"></span>
                                        <?php wp_nonce_field('ai_agent_sync_nonce_action', 'ai_agent_sync_nonce_field'); ?>
                                    </div>

                                    <details class="ai-agent-details ai-agent-mt">
                                        <summary>ایندکس کامل از نو</summary>
                                        <p class="ai-agent-field-hint">
                                            کل محتوای سایت را دوباره پردازش و ایندکس می‌کند، حتی مواردی که تغییر
                                            نکرده‌اند. چون برای هر سند دوباره هزینه‌ی پردازش کسر می‌شود، فقط وقتی
                                            لازم است که پاسخ‌های دستیار با محتوای سایت جور در نمی‌آید.
                                        </p>
                                        <div class="ai-agent-sync-block-actions">
                                            <button type="button" id="ai-agent-sync-all-btn" class="ai-agent-btn ai-agent-btn-outline ai-agent-btn-red">
                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                                                ایندکس کامل از نو
                                            </button>
                                            <span id="ai-agent-sync-all-status" class="ai-agent-status-text"></span>
                                            <?php wp_nonce_field('ai_agent_sync_all_nonce_action', 'ai_agent_sync_all_nonce_field'); ?>
                                        </div>
                                    </details>
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


                    <!-- ====== API Key ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                                توکن سایت
                            </h2>
                            <?php if (!empty(ai_agent_get_api_key())) : ?>
                                <span class="ai-agent-badge ai-agent-badge-ok">ثبت شده</span>
                            <?php else : ?>
                                <span class="ai-agent-badge ai-agent-badge-warn">ثبت نشده</span>
                            <?php endif; ?>
                        </header>
                        <div class="ai-agent-card-body">
                            <div class="ai-agent-field-row">
                                <label for="ai_agent_api_key" class="ai-agent-field-label">توکن (API Key)</label>
                                <?php
                                /*
                                مقدار این فیلد همیشه خالی است و کلید ذخیره‌شده
                                هرگز در HTML چاپ نمی‌شود. علاوه بر اینکه چاپ
                                نکردنش امن‌تر است، قبلاً باعث می‌شد مرورگر فیلد
                                را با پسورد ذخیره‌شده‌ی وردپرس پر کند و کاربر
                                کلیدی ببیند که خودش وارد نکرده بود. خالی
                                فرستادن فرم یعنی «کلید فعلی را نگه دار».
                                */
                                ?>
                                <div class="ai-agent-input-group">
                                    <input type="password" name="ai_agent_settings[api_key]" id="ai_agent_api_key"
                                           value="" class="ai-agent-input"
                                           autocomplete="off" data-lpignore="true" data-1p-ignore
                                           placeholder="<?php echo !empty(ai_agent_get_api_key()) ? 'کلید ذخیره شده — برای تغییر، کلید جدید را وارد کنید' : 'sk_live_...'; ?>" />
                                    <button type="button" id="ai-agent-toggle-api-key">نمایش</button>
                                    <button type="button" id="ai-agent-save-api-key" class="ai-agent-btn ai-agent-btn-primary">ذخیره‌ی توکن</button>
                                </div>
                                <span id="ai-agent-save-api-key-status" class="ai-agent-status-text"></span>
                                <?php wp_nonce_field('ai_agent_save_api_key_nonce_action', 'ai_agent_save_api_key_nonce_field'); ?>
                            </div>

                            <p class="ai-agent-field-hint">
                                توکن را از پنل دانیچَت بگیرید: ثبت‌نام کنید، سایت خود را اضافه کنید و توکن آن را کپی کنید.
                                با ذخیره‌ی توکن، سایت شما به‌صورت خودکار فعال می‌شود و نیازی به فعال‌سازی جداگانه نیست.
                            </p>
                            <div class="ai-agent-inline-actions">
                                <a class="ai-agent-btn ai-agent-btn-outline" href="https://dunichat.ir/login" target="_blank" rel="noopener">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    دریافت توکن از دانیچَت
                                </a>
                                <a class="ai-agent-btn ai-agent-btn-ghost" href="https://dunichat.ir/docs" target="_blank" rel="noopener">راهنمای راه‌اندازی</a>
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

                    <!-- ====== Assistant persona ====== -->
                    <?php
                    /*
                    ============================================
                    جای پرامپت سیستمی را این بخش گرفته است.

                    کاربر لحن و میزان ایموجی را انتخاب می‌کند و چند فیلد
                    اطلاعاتی کوتاه پر می‌کند؛ متن دستورالعمل روی سرور از
                    همین‌ها ساخته می‌شود. یک فیلد متنی آزاد در این جایگاه،
                    مستقیم‌ترین راه برای دست‌کاری رفتار دستیار بود.
                    ============================================
                    */
                    $ai_agent_tones = array(
                        'formal'       => array('رسمی', 'کوتاه و خشک'),
                        'professional' => array('حرفه‌ای', 'مؤدب و مختصر'),
                        'neutral'      => array('متعادل', 'حالت پیش‌فرض'),
                        'friendly'     => array('دوستانه', 'راحت اما حرفه‌ای'),
                        'warm'         => array('بسیار گرم', 'صمیمی و همدلانه'),
                    );
                    $ai_agent_emoji_levels = array(
                        'none'   => 'بدون ایموجی',
                        'low'    => 'کم',
                        'medium' => 'متوسط',
                        'high'   => 'زیاد',
                    );
                    ?>
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect x="4" y="8" width="16" height="12" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                                شخصیت دستیار
                            </h2>
                        </header>
                        <div class="ai-agent-card-body">

                            <div class="ai-agent-field-row">
                                <label class="ai-agent-field-label">لحن پاسخ‌گویی</label>
                                <div class="ai-agent-segmented" role="radiogroup" aria-label="لحن پاسخ‌گویی">
                                    <?php foreach ($ai_agent_tones as $tone_value => $tone_meta) : ?>
                                        <label class="ai-agent-segment">
                                            <input type="radio" name="ai_agent_settings[assistant_tone]" value="<?php echo esc_attr($tone_value); ?>" <?php checked($settings['assistant_tone'], $tone_value); ?> />
                                            <span class="ai-agent-segment-body">
                                                <span class="ai-agent-segment-title"><?php echo esc_html($tone_meta[0]); ?></span>
                                                <span class="ai-agent-segment-sub"><?php echo esc_html($tone_meta[1]); ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="ai-agent-field-hint">حالت پیش‌فرض «متعادل» است — نه خشک، نه بیش از حد صمیمی.</p>
                            </div>

                            <div class="ai-agent-field-row ai-agent-mt">
                                <label class="ai-agent-field-label">استفاده از ایموجی</label>
                                <div class="ai-agent-segmented" role="radiogroup" aria-label="استفاده از ایموجی">
                                    <?php foreach ($ai_agent_emoji_levels as $emoji_value => $emoji_label) : ?>
                                        <label class="ai-agent-segment">
                                            <input type="radio" name="ai_agent_settings[emoji_usage]" value="<?php echo esc_attr($emoji_value); ?>" <?php checked($settings['emoji_usage'], $emoji_value); ?> />
                                            <span class="ai-agent-segment-body">
                                                <span class="ai-agent-segment-title"><?php echo esc_html($emoji_label); ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="ai-agent-field-hint">فقط ایموجی‌های رسمی و مناسب محیط کاری استفاده می‌شوند.</p>
                            </div>

                            <div class="ai-agent-field-grid ai-agent-mt">
                                <div class="ai-agent-field-row">
                                    <label for="ai_agent_organization_name" class="ai-agent-field-label">نام مجموعه</label>
                                    <input type="text" class="ai-agent-input" maxlength="200"
                                           name="ai_agent_settings[organization_name]" id="ai_agent_organization_name"
                                           value="<?php echo esc_attr($settings['organization_name']); ?>"
                                           placeholder="مثلاً فروشگاه لوازم خانگی دانی" />
                                </div>
                                <div class="ai-agent-field-row">
                                    <label for="ai_agent_business_field" class="ai-agent-field-label">حوزه‌ی کاری</label>
                                    <input type="text" class="ai-agent-input" maxlength="200"
                                           name="ai_agent_settings[business_field]" id="ai_agent_business_field"
                                           value="<?php echo esc_attr($settings['business_field']); ?>"
                                           placeholder="مثلاً فروش آنلاین لوازم خانگی" />
                                </div>
                            </div>

                            <div class="ai-agent-field-row ai-agent-mt">
                                <label for="ai_agent_business_description" class="ai-agent-field-label">معرفی یک‌خطی</label>
                                <input type="text" class="ai-agent-input" maxlength="500"
                                       name="ai_agent_settings[business_description]" id="ai_agent_business_description"
                                       value="<?php echo esc_attr($settings['business_description']); ?>"
                                       placeholder="در یک جمله بگویید چه کاری انجام می‌دهید" />
                            </div>

                            <div class="ai-agent-field-grid ai-agent-mt">
                                <div class="ai-agent-field-row">
                                    <label for="ai_agent_support_phones" class="ai-agent-field-label">شماره‌های تماس پشتیبانی</label>
                                    <textarea class="ai-agent-textarea" rows="3" dir="ltr"
                                              name="ai_agent_settings[support_phones]" id="ai_agent_support_phones"
                                              placeholder="02128421452"><?php echo esc_textarea(implode("\n", (array) $settings['support_phones'])); ?></textarea>
                                    <p class="ai-agent-field-hint">هر شماره در یک خط — حداکثر ۵ شماره.</p>
                                </div>
                                <div class="ai-agent-field-row">
                                    <label for="ai_agent_telegram_id" class="ai-agent-field-label">آیدی تلگرام پشتیبانی</label>
                                    <input type="text" class="ai-agent-input" dir="ltr" maxlength="100"
                                           name="ai_agent_settings[telegram_id]" id="ai_agent_telegram_id"
                                           value="<?php echo esc_attr($settings['telegram_id']); ?>"
                                           placeholder="dunijet_support" />
                                    <label for="ai_agent_instagram_id" class="ai-agent-field-label ai-agent-mt">آیدی اینستاگرام</label>
                                    <input type="text" class="ai-agent-input" dir="ltr" maxlength="100"
                                           name="ai_agent_settings[instagram_id]" id="ai_agent_instagram_id"
                                           value="<?php echo esc_attr($settings['instagram_id']); ?>"
                                           placeholder="dunichat" />
                                </div>
                            </div>

                            <p class="ai-agent-field-hint ai-agent-mt">
                                این اطلاعات به دستیار داده می‌شود تا در پاسخ‌ها از آن‌ها استفاده کند. متن‌ها پیش از
                                استفاده روی سرور پاک‌سازی می‌شوند و به‌عنوان «داده» در اختیار مدل قرار می‌گیرند، نه دستور.
                            </p>
                        </div>
                    </section>

                    <!-- ====== Appearance (Dual Colors + Timeout) ====== -->
                    <section class="ai-agent-card">
                        <?php
                        /*
                        رنگ دستیار — دو رنگ مستقل دریافت می‌شود:
                          - color_light: رنگ ویجت در حالت روشن (Light)
                          - color_dark : رنگ ویجت در حالت تاریک (Dark)
                        هنگام نمایش به بازدیدکننده، هر حالتی که فعال باشد
                        از رنگ همان حالت استفاده می‌شود (دکمه شناور، هدر،
                        حباب پیام کاربر و رنگ فوکِس فیلد متن).
                        */ ?>
                        <header class="ai-agent-card-header">
                                <h2>
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
                                    رنگ دستیار
                                </h2>
                            </header>
                            <div class="ai-agent-card-body">
                                <div class="ai-agent-field-row">
                                    <label for="ai_agent_color_light" class="ai-agent-field-label">
                                        <span class="ai-agent-color-dot ai-agent-color-dot-light" aria-hidden="true"></span>
                                        رنگ در حالت روشن (Light)
                                    </label>
                                    <input type="text" name="ai_agent_settings[color_light]" id="ai_agent_color_light" value="<?php echo esc_attr($settings['color_light']); ?>" class="ai-agent-color-field" />
                                </div>
                                <div class="ai-agent-field-row ai-agent-mt">
                                    <label for="ai_agent_color_dark" class="ai-agent-field-label">
                                        <span class="ai-agent-color-dot ai-agent-color-dot-dark" aria-hidden="true"></span>
                                        رنگ در حالت تاریک (Dark)
                                    </label>
                                    <input type="text" name="ai_agent_settings[color_dark]" id="ai_agent_color_dark" value="<?php echo esc_attr($settings['color_dark']); ?>" class="ai-agent-color-field" />
                                </div>
                                <p class="ai-agent-field-hint">وقتی چت در حالت روشن یا تاریک نمایش داده می‌شود، رنگ همان حالت روی دکمه شناور، هدر، حباب پیام کاربر و فوکِس فیلد متن اعمال می‌گردد.</p>
                            </div>
                    </section>

                    <!-- ====== Response timeout ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                                <h2>
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    حداکثر زمان انتظار پاسخ
                                </h2>
                            </header>
                            <div class="ai-agent-card-body">
                                <?php
                                /*
                                عنوان قبلی «مدت پاسخ‌گویی» بود و این تصور را
                                می‌ساخت که دستیار حتماً همین‌قدر طول می‌کشد.
                                این عدد در واقع timeout است: اگر تا این مدت
                                اولین بخش پاسخ نرسد، درخواست قطع می‌شود.
                                */
                                ?>
                                <div class="ai-agent-number-input">
                                    <input type="number" min="5" max="120" step="1" name="ai_agent_settings[timeout]" id="ai_agent_timeout" value="<?php echo esc_attr($settings['timeout']); ?>" />
                                    <span class="ai-agent-number-suffix">ثانیه</span>
                                </div>
                                <p class="ai-agent-field-hint">
                                    اگر تا این مدت اولین بخش پاسخ از سرور نرسد، درخواست قطع و پیام خطا نمایش داده
                                    می‌شود. مقدار پیشنهادی ۱۵ ثانیه است.
                                </p>
                            </div>
                    </section>

                    <!-- ====== Widget Button Position (per device) ====== -->
                    <section class="ai-agent-card">
                        <header class="ai-agent-card-header">
                            <h2>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                                موقعیت آیکون افزونه
                            </h2>
                        </header>
                        <div class="ai-agent-card-body">
                            <?php
                            /*
                            انتخاب دستگاه (موبایل / تبلت / دسکتاپ):
                            سه آیکون کوچک در بالا نمایش داده می‌شود؛ با کلیک روی هر
                            آیکون، تنظیمات همان دستگاه (سمت قرارگیری + جابجایی عمودی)
                            نشان داده می‌شود و کاربر می‌تواند برای هر دستگاه مقادیری
                            کاملاً متفاوت و مستقل تعیین کند.

                            نکته: هر سه پنل همیشه در DOM باقی می‌مانند (فقط با CSS
                            مخفی/نمایش می‌شوند) تا مقادیر هر سه دستگاه هنگام ذخیره‌ی
                            فرم ارسال شوند.
                            */ ?>
                            <div class="ai-agent-field-row">
                                <label class="ai-agent-field-label">انتخاب دستگاه</label>
                                <div class="ai-agent-device-tabs" id="ai-agent-device-tabs" role="tablist">
                                    <button type="button" class="ai-agent-device-tab is-active" data-device="mobile" role="tab" aria-selected="true" title="تنظیمات موبایل">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                                        <span class="ai-agent-device-tab-label">موبایل</span>
                                    </button>
                                    <button type="button" class="ai-agent-device-tab" data-device="tablet" role="tab" aria-selected="false" title="تنظیمات تبلت">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                                        <span class="ai-agent-device-tab-label">تبلت</span>
                                    </button>
                                    <button type="button" class="ai-agent-device-tab" data-device="desktop" role="tab" aria-selected="false" title="تنظیمات دسکتاپ">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                        <span class="ai-agent-device-tab-label">دسکتاپ</span>
                                    </button>
                                </div>
                            </div>

                            <?php
                            /*
                            ============================================
                            چیدمان قبلی برای هر دستگاه دو رادیو (چپ/راست) و
                            یک فیلد عددی پیکسل بود و کاربر باید عدد را حدس
                            می‌زد و ذخیره می‌کرد تا نتیجه را ببیند.

                            حالا هر دستگاه یک ماکت است و آیکون داخلش
                            کشیدنی. کشیدن، هم سمت و هم فاصله را یک‌جا تعیین
                            می‌کند و همان لحظه دیده می‌شود. فیلدهای عددی
                            به‌عنوان hidden باقی مانده‌اند تا هم فرم دقیقاً
                            همان مقادیر قبلی را بفرستد و هم اگر جاوااسکریپت
                            اجرا نشد، بخش پایین (تنظیم دقیق) قابل استفاده
                            بماند.
                            ============================================
                            */
                            $ai_agent_devices = array(
                                'mobile'  => array('label' => 'موبایل',  'hint' => 'عرض صفحه تا ۷۶۸ پیکسل. در موبایل پنجره‌ی چت تمام‌صفحه است و این تنظیم فقط روی دکمه‌ی شناور اثر دارد.'),
                                'tablet'  => array('label' => 'تبلت',    'hint' => 'عرض صفحه بین ۷۶۹ تا ۱۰۲۴ پیکسل.'),
                                'desktop' => array('label' => 'دسکتاپ',  'hint' => 'عرض صفحه از ۱۰۲۵ پیکسل به بالا.'),
                            );
                            foreach ($ai_agent_devices as $ai_agent_device => $ai_agent_device_meta) :
                                $ai_agent_side_key   = 'button_position_side_' . $ai_agent_device;
                                $ai_agent_offset_key = 'button_position_offset_y_' . $ai_agent_device;
                                $ai_agent_side       = $settings[$ai_agent_side_key] === 'left' ? 'left' : 'right';
                                $ai_agent_offset     = intval($settings[$ai_agent_offset_key]);
                            ?>
                            <div class="ai-agent-device-panel<?php echo $ai_agent_device === 'mobile' ? ' is-active' : ''; ?>" data-device-panel="<?php echo esc_attr($ai_agent_device); ?>">

                                <div class="ai-agent-stage-wrap">
                                    <div class="ai-agent-stage ai-agent-stage-<?php echo esc_attr($ai_agent_device); ?>"
                                         data-stage="<?php echo esc_attr($ai_agent_device); ?>"
                                         data-side="<?php echo esc_attr($ai_agent_side); ?>"
                                         data-offset="<?php echo esc_attr($ai_agent_offset); ?>">
                                        <div class="ai-agent-stage-screen" aria-hidden="true">
                                            <span class="ai-agent-stage-line"></span>
                                            <span class="ai-agent-stage-line ai-agent-stage-line-short"></span>
                                            <span class="ai-agent-stage-line"></span>
                                            <span class="ai-agent-stage-line ai-agent-stage-line-short"></span>
                                        </div>
                                        <button type="button" class="ai-agent-stage-handle"
                                                data-stage-handle="<?php echo esc_attr($ai_agent_device); ?>"
                                                aria-label="جابه‌جایی آیکون دستیار — <?php echo esc_attr($ai_agent_device_meta['label']); ?>"
                                                title="بکشید تا جای آیکون را تعیین کنید">
                                            <img src="<?php echo esc_url(AI_AGENT_URL . 'assets/images/favicon46x46.png'); ?>" alt="" />
                                        </button>
                                    </div>
                                    <p class="ai-agent-stage-readout" data-stage-readout="<?php echo esc_attr($ai_agent_device); ?>"></p>
                                </div>

                                <p class="ai-agent-field-hint"><?php echo esc_html($ai_agent_device_meta['hint']); ?></p>

                                <details class="ai-agent-details ai-agent-mt">
                                    <summary>تنظیم دقیق با عدد</summary>

                                    <div class="ai-agent-field-row ai-agent-mt">
                                        <label class="ai-agent-field-label">سمت قرارگیری</label>
                                        <div class="ai-agent-segmented">
                                            <label class="ai-agent-segment">
                                                <input type="radio" data-position-side="<?php echo esc_attr($ai_agent_device); ?>"
                                                       name="ai_agent_settings[<?php echo esc_attr($ai_agent_side_key); ?>]" value="right" <?php checked($ai_agent_side, 'right'); ?>>
                                                <span class="ai-agent-segment-body"><span class="ai-agent-segment-title">راست</span></span>
                                            </label>
                                            <label class="ai-agent-segment">
                                                <input type="radio" data-position-side="<?php echo esc_attr($ai_agent_device); ?>"
                                                       name="ai_agent_settings[<?php echo esc_attr($ai_agent_side_key); ?>]" value="left" <?php checked($ai_agent_side, 'left'); ?>>
                                                <span class="ai-agent-segment-body"><span class="ai-agent-segment-title">چپ</span></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ai-agent-field-row ai-agent-mt">
                                        <label for="ai_agent_<?php echo esc_attr($ai_agent_offset_key); ?>" class="ai-agent-field-label">جابجایی عمودی</label>
                                        <div class="ai-agent-number-input">
                                            <input type="number" step="1" data-position-offset="<?php echo esc_attr($ai_agent_device); ?>"
                                                   name="ai_agent_settings[<?php echo esc_attr($ai_agent_offset_key); ?>]"
                                                   id="ai_agent_<?php echo esc_attr($ai_agent_offset_key); ?>"
                                                   value="<?php echo esc_attr($ai_agent_offset); ?>" />
                                            <span class="ai-agent-number-suffix">پیکسل (مثبت: بالاتر، منفی: پایین‌تر)</span>
                                        </div>
                                    </div>
                                </details>
                            </div>
                            <?php endforeach; ?>
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
                            <?php
                            /*
                            همگام‌سازی خودکار: بدون آن، محتوای دستیار به مرور از
                            سایت عقب می‌افتد و کسی متوجه نمی‌شود تا وقتی پاسخ
                            اشتباهی درباره‌ی محصولی بدهد که دیگر وجود ندارد.
                            */
                            $ai_agent_schedules = array(
                                'daily'        => 'روزانه',
                                'every_3_days' => 'هر سه روز',
                                'weekly'       => 'هفتگی',
                                'manual'       => 'فقط دستی',
                            );
                            ?>
                            <div class="ai-agent-field-row ai-agent-mt">
                                <label class="ai-agent-field-label">همگام‌سازی خودکار محتوا</label>
                                <div class="ai-agent-segmented">
                                    <?php foreach ($ai_agent_schedules as $schedule_value => $schedule_label) : ?>
                                        <label class="ai-agent-segment">
                                            <input type="radio" name="ai_agent_settings[sync_schedule]" value="<?php echo esc_attr($schedule_value); ?>" <?php checked($settings['sync_schedule'], $schedule_value); ?> />
                                            <span class="ai-agent-segment-body"><span class="ai-agent-segment-title"><?php echo esc_html($schedule_label); ?></span></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="ai-agent-field-hint">
                                    پیش‌فرض «هر سه روز» است. حتی با زمان‌بندی روشن، هر وقت خواستید می‌توانید از
                                    بالای همین صفحه دستی هم به‌روزرسانی کنید.
                                </p>
                            </div>

                            <div class="ai-agent-field-grid ai-agent-mt">
                                <div class="ai-agent-field-row">
                                    <label for="ai_agent_sync_hour" class="ai-agent-field-label">ساعت اجرای خودکار</label>
                                    <div class="ai-agent-number-input">
                                        <input type="number" min="0" max="23" step="1" name="ai_agent_settings[sync_hour]" id="ai_agent_sync_hour" value="<?php echo esc_attr(intval($settings['sync_hour'])); ?>" />
                                        <span class="ai-agent-number-suffix">به وقت تهران</span>
                                    </div>
                                </div>
                                <div class="ai-agent-field-row">
                                    <label for="ai_agent_daily_message_limit" class="ai-agent-field-label">حداکثر پیام روزانه</label>
                                    <div class="ai-agent-number-input">
                                        <input type="number" min="0" step="1" name="ai_agent_settings[daily_message_limit]" id="ai_agent_daily_message_limit" value="<?php echo esc_attr(intval($settings['daily_message_limit'])); ?>" />
                                        <span class="ai-agent-number-suffix">پیام در روز</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    </div><!-- /.ai-agent-sheet -->
                </form>

                <?php
                /*
                ============================================
                پشتیبانی دانیچَت

                کسی که دستیارش کار نمی‌کند همین صفحه را باز کرده، نه
                سایت دانیچَت را؛ پس راه تماس باید همین‌جا در دسترس باشد.
                این بخش بیرون از فرم است چون هیچ‌کدام از این‌ها تنظیمات
                نیستند.
                ============================================
                */
                ?>
                <section class="ai-agent-card ai-agent-support-card">
                    <header class="ai-agent-card-header">
                        <h2>
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
                            پشتیبانی دانیچَت
                        </h2>
                    </header>
                    <div class="ai-agent-card-body">
                        <p class="ai-agent-field-hint">
                            به مشکلی خوردید یا سوالی دارید؟ زنگ بزنید یا پیام بدهید — همیشه در دسترسیم.
                        </p>
                        <div class="ai-agent-support-links">
                            <a class="ai-agent-support-link" href="tel:+989900668721">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <span>
                                امیرحسین محمدی
                                <span class="ai-agent-support-value" dir="ltr"><?php echo esc_html(ai_agent_fa_digits('09900668721')); ?></span>
                            </span>
                            </a>
                            <a class="ai-agent-support-link" href="https://t.me/dunijet_support" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
                                <span>تلگرام <span class="ai-agent-support-value" dir="ltr">@dunijet_support</span></span>
                            </a>
                            <a class="ai-agent-support-link" href="https://instagram.com/dunichat.ir" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M16 8h.01"/><rect x="3" y="3" width="18" height="18" rx="5"/></svg>
                                <span>اینستاگرام <span class="ai-agent-support-value" dir="ltr">@dunichat.ir</span></span>
                            </a>
                            <a class="ai-agent-support-link" href="https://instagram.com/dunijet" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M16 8h.01"/><rect x="3" y="3" width="18" height="18" rx="5"/></svg>
                                <span>اینستاگرام <span class="ai-agent-support-value" dir="ltr">@dunijet</span></span>
                            </a>
                        </div>
                        <p class="ai-agent-field-hint ai-agent-mt">
                            دانیچَت محصولی از آژانس هوشمندسازی
                            <a href="https://dunijet.ir" target="_blank" rel="noopener">دانیجت</a>
                            است.
                        </p>
                    </div>
                </section>

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
                                <span class="ai-agent-filter-count" hidden data-count-status="">0</span>
                                همه
                            </button>
                            <button type="button" class="ai-agent-filter-btn" data-status="closed">
                                <span class="ai-agent-filter-count" hidden data-count-status="closed">0</span>
                                بسته‌شده
                            </button>
                            <button type="button" class="ai-agent-filter-btn" data-status="human">
                                <span class="ai-agent-filter-count" hidden data-count-status="human">0</span>
                                پشتیبان
                            </button>
                            <button type="button" class="ai-agent-filter-btn" data-status="pending_human">
                                <span class="ai-agent-filter-count" hidden data-count-status="pending_human">0</span>
                                در انتظار پشتیبان
                            </button>
                            <button type="button" class="ai-agent-filter-btn" data-status="bot">
                                <span class="ai-agent-filter-count" hidden data-count-status="bot">0</span>
                                ربات
                            </button>
                        </div>

                        <!-- نوار ابزار بالا -->
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
                                    <option value="5">۵</option>
                                    <option value="10" selected>۱۰</option>
                                    <option value="20">۲۰</option>
                                    <option value="50">۵۰</option>
                                    <option value="100">۱۰۰</option>
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
        AI_AGENT_VERSION
    );

    /*
    Components added in 1.1.0, plus the corrections to the existing ones.
    A separate file rather than edits in place: it is a coherent new set, and
    loading it after means its overrides land without !important.
    */
    wp_enqueue_style(
        'ai-agent-settings-components-css',
        AI_AGENT_URL . 'assets/css/settings-components.css',
        array('ai-agent-settings-css'),
        AI_AGENT_VERSION
    );

    // اسکریپت اختصاصی صفحه‌ی تنظیمات (قبلاً inline بود، اکنون فایل مجزا)
    // وابسته به jquery, wp-color-picker و Chart.js تا قبل از اجرا بارگذاری شده باشند
    wp_enqueue_script(
        'ai-agent-settings-js',
        AI_AGENT_URL . 'assets/js/settings.js',
        array('jquery', 'wp-color-picker', 'ai-agent-chartjs'),
        AI_AGENT_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'ai_agent_admin_enqueue');