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

        /*
        ============================================
        تم چت — از این‌جا کنترل می‌شود، نه از داخل ویجت

        قبلاً یک آیکون ماه/خورشید داخل هدر چت بود و هر بازدیدکننده تم را
        برای خودش عوض می‌کرد. این تصمیمِ بازدیدکننده نیست: چت باید شبیه
        سایتی باشد که رویش نشسته، نه چیزی که وسطش تم جدا دارد. حالا:

          - theme_mode = auto  : از تم خود سایت/سیستم پیروی می‌کند
          - theme_mode = light : همیشه روشن
          - theme_mode = dark  : همیشه تاریک

        و رنگ پس‌زمینه‌ی صفحه‌ی چت در هر دو حالت این‌جا قابل تغییر است.
        پیش‌فرض‌ها همان کاغذ گرم و مشکیِ گرمِ اپلیکیشن کلاد هستند.
        ============================================
        */
        'theme_mode'          => 'auto',    // auto | light | dark
        'chat_bg_light'       => '#FAF9F5',
        'chat_bg_dark'        => '#1F1E1D',

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
        /*
        ساعت اجرا دیگر در صفحه‌ی تنظیمات انتخاب نمی‌شود. نیمه‌شب کم‌ترین
        ترافیک سایت را دارد و هیچ فروشگاهی دلیلی نداشت عوضش کند؛ فیلدش
        فقط یک تصمیم اضافه روی صفحه بود. مقدار همچنان ذخیره می‌شود چون
        زمان‌بند از همین می‌خواند.
        */
        'sync_hour'           => 0,              // نیمه‌شب به وقت تهران
        'daily_message_limit' => 1000,    // حداکثر پیام روزانه (قابل ویرایش کاربر و ارسال به سرور)
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

    /*
    رنگ حالت تاریک دیگر از فرم دریافت نمی‌شود؛ کاربر فقط رنگ روشن را
    انتخاب می‌کند و این‌جا همیشه خودکار از همان ساخته می‌شود (همان
    تابعی که هنگام فعال‌سازی افزونه هم برای پیش‌فرض استفاده می‌شود).
    */
    $output['color_dark'] = function_exists('ai_agent_lighten_hex')
        ? ai_agent_lighten_hex($color_light, AI_AGENT_DARK_LIFT)
        : $color_light;

    // کلید قدیمی color برای سازگاری (معادل رنگ حالت روشن)
    $output['color'] = $color_light;

    // تم چت: فقط سه مقدار مجاز است؛ هر چیز دیگری به auto برمی‌گردد.
    $theme_mode = isset($input['theme_mode']) ? sanitize_text_field($input['theme_mode']) : 'auto';
    $output['theme_mode'] = in_array($theme_mode, array('auto', 'light', 'dark'), true) ? $theme_mode : 'auto';

    $bg_light = isset($input['chat_bg_light']) ? sanitize_hex_color($input['chat_bg_light']) : '';
    $output['chat_bg_light'] = $bg_light ? $bg_light : '#FAF9F5';

    $bg_dark = isset($input['chat_bg_dark']) ? sanitize_hex_color($input['chat_bg_dark']) : '';
    $output['chat_bg_dark'] = $bg_dark ? $bg_dark : '#1F1E1D';

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
    $allowed_tones = array('formal', 'professional', 'neutral', 'friendly', 'warm', 'casual');
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

    $sync_hour = isset($input['sync_hour']) ? intval(ai_agent_en_digits($input['sync_hour'])) : 0;
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

    // ۳. خطای ارتباطی واقعی (شبکه/DNS/SSL) — این‌جا واقعاً کلید مشخص نیست
    if ($remote === false) {
        $in_progress = false;
        return array(
            'status'  => 'error',
            'message' => 'ارتباط با سرور همگام‌سازی برقرار نشد. لطفاً اتصال اینترنت را بررسی کنید.',
        );
    }

    // ۳.۵. سرور کد HTTP غیر ۲۰۰ برگردانده (مثلاً ۴۰۱ ⇒ کلید API نامعتبر است)
    if (isset($remote['__http_error'])) {
        $in_progress = false;
        return array(
            'status'  => 'error',
            'message' => $remote['__http_error'],
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
============================================
ذخیره‌ی خودکار فرم تنظیمات (بدون دکمه‌ی ذخیره)

از سمت جاوااسکریپت (settings.js → aiAgentAutoSave) با یک تأخیر کوتاه
بعد از هر تغییر در فرم فراخوانی می‌شود؛ کل فرم (همان‌طور که برای
options.php سریالایز می‌شد) این‌جا ارسال و مستقیماً با update_option()
ذخیره می‌شود.

نکته‌ی مهم: update_option() برای گزینه‌ای که با register_setting()
ثبت شده، همان فیلتر sanitize_option_ai_agent_settings (یعنی
ai_agent_sanitize_settings) را خودکار صدا می‌زند — دقیقاً همان تابعی
که فرم عادی options.php هم استفاده می‌کرد. پس این‌جا نیازی به
پاک‌سازی یا فراخوانی دستیِ منطق ذخیره نیست؛ همان مسیر واحد (شامل
PATCH/GET با سرور همگام‌سازی در ai_agent_after_settings_saved) دوباره
اجرا می‌شود.
============================================
*/
function ai_agent_ajax_save_settings_handler(){

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'شما دسترسی کافی برای این عملیات را ندارید.'));
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ai_agent_autosave_nonce_action')) {
        wp_send_json_error(array('message' => 'خطای امنیتی! اعتبار‌سنجی درخواست ناموفق بود. لطفاً صفحه را تازه کنید.'));
    }

    $input = (isset($_POST['ai_agent_settings']) && is_array($_POST['ai_agent_settings']))
        ? wp_unslash($_POST['ai_agent_settings'])
        : array();

    update_option('ai_agent_settings', $input);

    // نتیجه‌ی PATCH/GET سرور همگام‌سازی (اگر توکن ثبت شده باشد) در همین
    // درخواست انجام شده و در transient نشسته؛ همان را برای نمایش وضعیت
    // در رابط کاربری برمی‌گردانیم.
    $sync_result = get_transient('ai_agent_sync_result');

    wp_send_json_success(array(
        'message' => 'ذخیره شد.',
        'sync'    => $sync_result !== false ? $sync_result : null,
    ));
}
add_action('wp_ajax_ai_agent_save_settings', 'ai_agent_ajax_save_settings_handler');

/*
==========================================================================
منوی پیشخوان — یک آیتم، یک صفحه، یک آدرس

قبلاً دو زیرمنو با دو slug جدا بود («تنظیمات پلاگین» و «پشتیبانی و
پیام‌ها») و رفتن از یکی به دیگری یعنی بارگذاری کامل صفحه و یک کال
دوباره به سرور همگام‌سازی. حالا هر دو، دو نمای همان یک صفحه‌اند و
جابه‌جایی‌شان در خود مرورگر انجام می‌شود.
==========================================================================
*/
function ai_agent_add_menu(){
    add_menu_page(
        'دانیچَت',
        'دانیچَت',
        'manage_options',
        'ai-agent-settings',
        'ai_agent_settings_page',
        AI_AGENT_URL . 'assets/images/favicon20x20.png',
        80
    );
}
add_action('admin_menu', 'ai_agent_add_menu');

/*
==========================================================================
گزینه‌های ثابتِ صفحه‌ی تنظیمات

از خود تابع رندر بیرون کشیده شده‌اند تا آن تابع فقط چیدمان باشد. هر
آرایه: کلید ذخیره‌شده => [عنوان، زیرعنوان].
==========================================================================
*/
function ai_agent_tone_options(){
    return array(
        'formal'       => array('رسمی', 'کوتاه و خشک'),
        'professional' => array('حرفه‌ای', 'مؤدب و مختصر'),
        'neutral'      => array('متعادل', 'حالت پیش‌فرض'),
        'friendly'     => array('دوستانه', 'راحت اما حرفه‌ای'),
        'warm'         => array('بسیار گرم', 'صمیمی و همدل'),
        'casual'       => array('داش‌مشتی', 'خودمونی و رُک'),
    );
}

/** نمونه‌ی جمله‌ی هر لحن — کاربر پیش از انتخاب می‌بیند چه چیزی می‌گیرد. */
function ai_agent_tone_examples(){
    return array(
        'formal'       => 'سفارش شما ثبت شد. کد پیگیری ۱۲۳۴۵ است.',
        'professional' => 'سفارشتون ثبت شد؛ کد پیگیری ۱۲۳۴۵ است. اگر سوالی بود در خدمتم.',
        'neutral'      => 'سفارشت ثبت شد! کد پیگیریت ۱۲۳۴۵ه. کاری بود بگو.',
        'friendly'     => 'ثبت شد ✅ کد پیگیریت ۱۲۳۴۵ه — هر سوالی داشتی همین‌جا بپرس.',
        'warm'         => 'ثبت شد عزیزم! 😍 کد پیگیریت ۱۲۳۴۵ه، خیالت راحت باشه؛ هر وقت خواستی هستم.',
        'casual'       => 'حله داداش، ثبت شد 👌 کدت ۱۲۳۴۵ه. کاری داشتی صدا بزن.',
    );
}

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

function ai_agent_settings_page(){
    if (!current_user_can('manage_options')) return;

    $settings = ai_agent_get_settings();

    /*
    ============================================
    بازخوانی تنظیمات از سرور همگام‌سازی

    نتیجه‌ی این کال دیگر به‌صورت نوار قرمز بالای صفحه نمایش داده
    نمی‌شود. دلیلش ساده است: پرتکرارترین حالتِ این خطا، سایتی است که
    تازه افزونه را نصب کرده و هنوز توکن نگذاشته — و آن‌وقت اولین چیزی
    که کاربر می‌دید یک خطای قرمز بود برای کاری که خودش هنوز فرصت نکرده
    انجام دهد. حالا هر خطای مربوط به توکن، یک یادداشت آرام داخل همان
    بخش «توکن سایت» است و بقیه‌ی خطاها هم داخل ورق می‌نشینند.
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

    $has_api_key = !empty(ai_agent_get_api_key());

    if ($save_result['status'] === 'success') {
        $settings = $save_result['data'];
    } elseif ($save_result['status'] === 'skipped') {
        // هنوز توکنی ثبت نشده — حالت عادیِ نصب تازه، نه خطا.
        if (!$has_api_key) {
            $token_note = 'کلید API خودتون رو وارد کنین تا دستیار راه بیفته.';
        }
    } else {
        /*
        خطای واقعی. اگر توکن ثبت شده ولی سرور ۴۰۱ داده، یعنی توکن غلط
        است؛ همان یادداشت توکن، فقط با رنگ خطا. بقیه‌ی خطاها (شبکه،
        ۵xx) هم همان‌جا می‌نشینند تا صفحه با یک بنر قرمز شروع نشود.
        */
        $token_note      = $save_result['message'];
        $token_note_kind = 'error';
    }

    $tone_options  = ai_agent_tone_options();
    $tone_examples = ai_agent_tone_examples();
    $current_tone  = isset($settings['assistant_tone']) ? $settings['assistant_tone'] : 'neutral';

    $sync_types  = isset($settings['sync_types']) && is_array($settings['sync_types']) ? $settings['sync_types'] : array();
    $site_colors = function_exists('ai_agent_get_site_colors') ? ai_agent_get_site_colors() : array();

    $color_light = isset($settings['color_light']) ? $settings['color_light'] : '#C96442';
    $color_dark  = function_exists('ai_agent_lighten_hex')
        ? ai_agent_lighten_hex($color_light, AI_AGENT_DARK_LIFT)
        : $color_light;

    $last_sync_time     = get_option('ai_agent_last_sync_time', '');
    $last_sync_all_time = get_option('ai_agent_last_sync_all_time', '');
    $last_scheduled     = get_option('ai_agent_last_scheduled_sync', array());
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
                    <?php wp_nonce_field('ai_agent_wallet_balance_nonce_action', 'ai_agent_wallet_balance_nonce_field'); ?>
                </div>
                <a class="ai-agent-text-btn" href="https://dunichat.ir/dashboard/wallet" target="_blank" rel="noopener">شارژ کیف‌پول</a>
                <span class="ai-agent-autosave-hint" id="ai-agent-autosave-hint">تنظیمات خودکار ذخیره می‌شوند</span>
            </div>
        </header>

        <?php
        /*
        دو نما، یک آدرس. کلیک روی هرکدام فقط کلاس is-active را جابه‌جا
        می‌کند؛ هیچ رفت‌وبرگشتی به سرور نیست.
        */ ?>
        <nav class="ai-agent-tabs" role="tablist">
            <button type="button" class="ai-agent-tab" data-view="history" role="tab" aria-selected="false">پشتیبانی و پیام‌ها</button>
            <button type="button" class="ai-agent-tab is-active" data-view="settings" role="tab" aria-selected="true">تنظیمات پلاگین</button>
        </nav>

        <!-- ================= نمای تنظیمات ================= -->
        <div class="ai-agent-view is-active" data-view-panel="settings">
        <form method="post" action="options.php">
            <?php
            settings_fields('ai_agent_settings_group');
            wp_nonce_field('ai_agent_autosave_nonce_action', 'ai_agent_autosave_nonce_field');
            ?>

            <div class="ai-agent-sheet">

                <!-- ---------- توکن سایت ---------- -->
                <section class="ai-agent-section">
                    <div class="ai-agent-section-head">
                        <h2>توکن سایت</h2>
                        <?php if ($has_api_key) : ?>
                            <span class="ai-agent-badge ai-agent-badge-ok">ثبت شده</span>
                        <?php else : ?>
                            <span class="ai-agent-badge ai-agent-badge-warn">ثبت نشده</span>
                        <?php endif; ?>
                    </div>
                    <p class="ai-agent-section-intro">
                        توکن رو از پنل دانیچَت بردار و همین‌جا بچسبون. ثبت‌نام کن، سایتت رو
                        اضافه کن، توکنش رو کپی کن — همین. با ذخیره‌ی توکن، سایتت خودکار فعال می‌شه.
                    </p>
                    <div class="ai-agent-btn-row">
                        <?php
                        /*
                        مقدار ذخیره‌شده هیچ‌وقت در HTML چاپ نمی‌شود — نه حتی به‌صورت
                        password. فیلد همیشه خالی باز می‌شود و خالی ماندنش یعنی
                        «توکن را عوض نکن».
                        */ ?>
                        <input type="password" id="ai_agent_api_key" name="ai_agent_settings[api_key]"
                               value="" class="ai-agent-input ai-agent-input-sm dc-ltr" lang="en"
                               style="flex:1 1 280px;min-width:0;width:auto"
                               autocomplete="off" placeholder="sk_live_..." />
                        <button type="button" id="ai-agent-toggle-api-key" class="ai-agent-btn">نمایش</button>
                        <button type="button" id="ai-agent-save-api-key" class="ai-agent-btn ai-agent-btn-primary">ذخیره‌ی توکن</button>
                        <?php wp_nonce_field('ai_agent_save_api_key_nonce_action', 'ai_agent_save_api_key_nonce_field'); ?>
                        <a class="ai-agent-btn" href="https://dunichat.ir/login" target="_blank" rel="noopener">دریافت توکن از دانیچَت</a>
                    </div>
                    <span id="ai-agent-save-api-key-status" class="ai-agent-status-text"></span>
                    <?php if ($token_note !== '') : ?>
                        <p class="ai-agent-note ai-agent-note-<?php echo esc_attr($token_note_kind); ?>"><?php echo esc_html($token_note); ?></p>
                    <?php endif; ?>
                </section>

                <!-- ---------- مدل هوش مصنوعی ---------- -->
                <section class="ai-agent-section">
                    <h2>مدل هوش مصنوعی</h2>
                    <p class="ai-agent-section-intro">
                        لیست از سرور دانیچَت میاد — یکی رو انتخاب کن و برو. کنار اسم هر مدل،
                        هزینه‌ی تقریبی یک گفت‌وگوی پشتیبانی با همان مدل نوشته شده.
                    </p>
                    <?php wp_nonce_field('ai_agent_models_nonce_action', 'ai_agent_models_nonce_field'); ?>
                    <select id="ai_agent_model" name="ai_agent_settings[model]"
                            class="ai-agent-input dc-ltr" lang="en" style="max-width:520px">
                        <?php
                        /*
                        تنها گزینه‌ی اولیه، همان چیزی است که ذخیره شده. بقیه‌ی لیست را
                        settings.js از اندپوینت مدل‌ها می‌گیرد و جای این می‌گذارد؛ اگر
                        آن کال شکست بخورد، انتخاب فعلی کاربر دست‌نخورده می‌ماند.
                        */ ?>
                        <option value="<?php echo esc_attr($settings['model']); ?>" selected><?php echo esc_html($settings['model']); ?></option>
                    </select>
                    <p id="ai-agent-models-status" class="ai-agent-status-text" style="display:block;margin-top:8px">در حال دریافت لیست مدل‌ها…</p>
                </section>

                <!-- ---------- شخصیت دستیار ---------- -->
                <section class="ai-agent-section">
                    <h2>شخصیت دستیار</h2>

                    <div class="ai-agent-field-group" style="margin-top:18px">
                        <span class="ai-agent-label">لحن پاسخ‌گویی</span>
                        <?php ai_agent_render_segmented('assistant_tone', $tone_options, $current_tone, 'لحن پاسخ‌گویی'); ?>
                        <p class="ai-agent-hint">
                            مثال:
                            <span id="ai-agent-tone-example" style="color:var(--dc-text)"><?php
                                echo esc_html(isset($tone_examples[$current_tone]) ? $tone_examples[$current_tone] : '');
                            ?></span>
                        </p>
                    </div>

                    <div class="ai-agent-field-group">
                        <span class="ai-agent-label">استفاده از ایموجی</span>
                        <?php ai_agent_render_segmented('emoji_usage', array(
                            'none'   => 'بدون ایموجی',
                            'low'    => 'کم',
                            'medium' => 'متوسط',
                            'high'   => 'زیاد',
                        ), isset($settings['emoji_usage']) ? $settings['emoji_usage'] : 'low', 'استفاده از ایموجی'); ?>
                        <p class="ai-agent-hint">فقط ایموجی‌های رسمی و مناسب محیط کاری استفاده می‌شوند.</p>
                    </div>

                    <div class="ai-agent-grid">
                        <div class="ai-agent-field-group">
                            <label class="ai-agent-label" for="ai_agent_organization_name">نام مجموعه</label>
                            <input type="text" class="ai-agent-input" maxlength="200"
                                   id="ai_agent_organization_name" name="ai_agent_settings[organization_name]"
                                   value="<?php echo esc_attr($settings['organization_name']); ?>"
                                   placeholder="مثلاً فروشگاه لوازم خانگی دانی" />
                        </div>
                        <div class="ai-agent-field-group">
                            <label class="ai-agent-label" for="ai_agent_business_field">حوزه‌ی کاری</label>
                            <input type="text" class="ai-agent-input" maxlength="200"
                                   id="ai_agent_business_field" name="ai_agent_settings[business_field]"
                                   value="<?php echo esc_attr($settings['business_field']); ?>"
                                   placeholder="مثلاً فروش آنلاین لوازم خانگی" />
                        </div>
                    </div>

                    <div class="ai-agent-field-group" style="margin-top:22px">
                        <label class="ai-agent-label" for="ai_agent_business_description">معرفی یک‌خطی</label>
                        <input type="text" class="ai-agent-input" maxlength="500"
                               id="ai_agent_business_description" name="ai_agent_settings[business_description]"
                               value="<?php echo esc_attr($settings['business_description']); ?>"
                               placeholder="در یک جمله بگو چی کار می‌کنی" />
                    </div>

                    <div class="ai-agent-grid">
                        <div class="ai-agent-field-group">
                            <span class="ai-agent-label">شماره‌های تماس پشتیبانی</span>
                            <div class="ai-agent-phone-rows" id="ai-agent-phone-rows">
                                <?php
                                $phones = !empty($settings['support_phones']) ? $settings['support_phones'] : array('');
                                foreach ($phones as $phone) : ?>
                                    <div class="ai-agent-phone-row">
                                        <input type="tel" class="ai-agent-input dc-ltr" lang="en"
                                               name="ai_agent_settings[support_phones][]"
                                               value="<?php echo esc_attr($phone); ?>" placeholder="02128421452" />
                                        <button type="button" class="ai-agent-btn ai-agent-btn-square ai-agent-phone-remove" aria-label="حذف این شماره">−</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="ai-agent-phone-add" class="ai-agent-btn ai-agent-btn-sm" style="align-self:flex-start">+ افزودن شماره</button>
                            <p class="ai-agent-hint">حداکثر ۵ شماره.</p>
                        </div>
                        <div class="ai-agent-field-group" style="gap:22px">
                            <div class="ai-agent-field-group">
                                <label class="ai-agent-label" for="ai_agent_telegram_id">آیدی تلگرام پشتیبانی</label>
                                <input type="text" class="ai-agent-input dc-ltr" lang="en" maxlength="100"
                                       id="ai_agent_telegram_id" name="ai_agent_settings[telegram_id]"
                                       value="<?php echo esc_attr($settings['telegram_id']); ?>" placeholder="dunijet_support" />
                            </div>
                            <div class="ai-agent-field-group">
                                <label class="ai-agent-label" for="ai_agent_instagram_id">آیدی اینستاگرام</label>
                                <input type="text" class="ai-agent-input dc-ltr" lang="en" maxlength="100"
                                       id="ai_agent_instagram_id" name="ai_agent_settings[instagram_id]"
                                       value="<?php echo esc_attr($settings['instagram_id']); ?>" placeholder="dunichat.ir" />
                            </div>
                        </div>
                    </div>

                    <p class="ai-agent-hint" style="margin-top:22px">
                        این اطلاعات به دستیار داده می‌شود تا در پاسخ‌ها از آن‌ها استفاده کند و
                        سوال‌های شروع گفت‌وگو از روی همین‌ها ساخته می‌شوند. متن‌ها پیش از
                        استفاده روی سرور پاک‌سازی می‌شوند و به‌عنوان «داده» در اختیار مدل
                        قرار می‌گیرند، نه دستور.
                    </p>
                </section>

                <!-- ---------- رنگ دستیار ---------- -->
                <section class="ai-agent-section">
                    <h2>رنگ دستیار</h2>
                    <p class="ai-agent-section-intro">
                        این رنگ روی دکمه‌ی شناور، هدر و دکمه‌ی ارسال می‌نشینه. موقع نصب از
                        رنگ اصلی خودِ سایتت خونده شده — هر وقت خواستی عوضش کن. رنگ حالت
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
                            <?php foreach (array('#C96442', '#1F1E1D', '#7C5CFF', '#2563EB', '#0EA5E9', '#16A34A', '#D97706', '#DC2626') as $hex) :
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
                                <span class="ai-agent-color-dot" id="ai-agent-color-light-dot"
                                      style="background:<?php echo esc_attr($color_light); ?>" aria-hidden="true"></span>
                                <input type="text" class="ai-agent-input ai-agent-input-hex ai-agent-color-field dc-ltr" lang="en"
                                       id="ai_agent_color_light" name="ai_agent_settings[color_light]"
                                       value="<?php echo esc_attr(strtoupper($color_light)); ?>" placeholder="#C96442" />
                            </div>
                        </div>
                        <div class="ai-agent-field-group">
                            <span class="ai-agent-sublabel" style="margin:0">رنگ حالت تاریک — خودکار ساخته می‌شه</span>
                            <div class="ai-agent-color-readonly">
                                <span class="ai-agent-color-dot ai-agent-color-dot-sm" id="ai-agent-color-dark-dot"
                                      style="background:<?php echo esc_attr($color_dark); ?>" aria-hidden="true"></span>
                                <span id="ai-agent-color-dark-value" class="dc-ltr" lang="en"><?php echo esc_html(strtoupper($color_dark)); ?></span>
                            </div>
                        </div>
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
                    ), isset($settings['theme_mode']) ? $settings['theme_mode'] : 'auto', 'حالت نمایش چت'); ?>

                    <?php
                    /*
                    پس‌زمینه‌ی چت دیگر فیلد متنیِ کد رنگ نیست. کاربر قرار نبود
                    hex بنویسد؛ چند رنگ درست انتخاب شده و او یکی را می‌زند.
                    */ ?>
                    <div class="ai-agent-grid" style="margin-top:24px">
                        <div>
                            <div class="ai-agent-label" style="margin-bottom:12px">پس‌زمینه‌ی چت در حالت روشن</div>
                            <?php ai_agent_render_swatches('chat_bg_light',
                                array('#FAF9F5', '#FFFFFF', '#F5F5F4', '#F1F5F9', '#FDF6F3', '#F7F7F2'),
                                isset($settings['chat_bg_light']) ? $settings['chat_bg_light'] : '#FAF9F5'); ?>
                        </div>
                        <div>
                            <div class="ai-agent-label" style="margin-bottom:12px">پس‌زمینه‌ی چت در حالت تاریک</div>
                            <?php ai_agent_render_swatches('chat_bg_dark',
                                array('#1F1E1D', '#000000', '#18181B', '#0F172A', '#221E1C', '#2A2724'),
                                isset($settings['chat_bg_dark']) ? $settings['chat_bg_dark'] : '#1F1E1D'); ?>
                        </div>
                    </div>
                </section>

                <!-- ---------- زمان انتظار ---------- -->
                <section class="ai-agent-section">
                    <h2>حداکثر زمان انتظار پاسخ</h2>
                    <p class="ai-agent-section-intro">
                        اگه تا این مدت اولین تکه‌ی پاسخ نرسید، درخواست قطع می‌شه. ۱۵ ثانیه پیشنهاد ماست.
                    </p>
                    <div class="ai-agent-inline">
                        <input type="number" class="ai-agent-input dc-ltr" lang="en" style="width:96px"
                               id="ai_agent_timeout" name="ai_agent_settings[timeout]" min="5" max="120"
                               value="<?php echo esc_attr($settings['timeout']); ?>" />
                        <span class="ai-agent-unit">ثانیه</span>
                    </div>
                </section>

                <!-- ---------- موقعیت آیکون ---------- -->
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
                                   id="ai_agent_<?php echo esc_attr($side_key); ?>" value="<?php echo esc_attr($side); ?>" />
                            <input type="hidden" name="ai_agent_settings[<?php echo esc_attr($offset_key); ?>]"
                                   id="ai_agent_<?php echo esc_attr($offset_key); ?>" value="<?php echo esc_attr($offset); ?>" />

                            <div class="ai-agent-stage-wrap">
                                <div class="ai-agent-stage ai-agent-stage-<?php echo esc_attr($device); ?>"
                                     data-device="<?php echo esc_attr($device); ?>"
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
                    </div>

                    <div class="ai-agent-grid" style="margin-top:24px">
                        <div class="ai-agent-field-group">
                            <span class="ai-agent-label">ساعت اجرای خودکار</span>
                            <?php
                            /*
                            ساعت اجرا دیگر انتخابی نیست: نیمه‌شب کم‌ترین ترافیک سایت را
                            دارد و هیچ فروشگاهی دلیلی نداشت آن را عوض کند — فیلدش فقط
                            یک تصمیم اضافه روی صفحه بود. مقدار همچنان ذخیره می‌شود تا
                            زمان‌بند از همان بخواند.
                            */ ?>
                            <input type="hidden" name="ai_agent_settings[sync_hour]" value="0" />
                            <p class="ai-agent-hint">
                                هر شب ساعت ۱۲ خودکار به‌روزرسانی می‌شه. اگه همین حالا می‌خوای،
                                دکمه‌ی «به‌روزرسانی محتوا» رو بزن.
                            </p>
                        </div>
                        <div class="ai-agent-field-group">
                            <label class="ai-agent-label" for="ai_agent_daily_message_limit">حداکثر پیام روزانه</label>
                            <div class="ai-agent-inline">
                                <input type="number" class="ai-agent-input ai-agent-input-num dc-ltr" lang="en" min="0"
                                       id="ai_agent_daily_message_limit" name="ai_agent_settings[daily_message_limit]"
                                       value="<?php echo esc_attr($settings['daily_message_limit']); ?>" />
                                <span class="ai-agent-unit">پیام در روز</span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ---------- همگام‌سازی محتوا ---------- -->
                <section class="ai-agent-section">
                    <h2>همگام‌سازی محتوا</h2>
                    <p class="ai-agent-section-intro">
                        محتوای تازه یا تغییرکرده‌ی سایت رو برای دستیار می‌فرسته. هر بار محصول
                        یا نوشته‌ی جدید گذاشتی، همین دکمه رو بزن. «ایندکس کامل» همه‌چیز رو از نو
                        پردازش می‌کنه و هزینه‌بره — فقط وقتی لازمه که پاسخ‌ها با محتوای سایت
                        جور در نمیاد.
                    </p>
                    <div class="ai-agent-btn-row">
                        <button type="button" id="ai-agent-sync-btn" class="ai-agent-btn ai-agent-btn-primary">به‌روزرسانی محتوا</button>
                        <button type="button" id="ai-agent-sync-all-btn" class="ai-agent-btn">ایندکس کامل از نو</button>
                        <button type="button" id="ai-agent-check-status-btn" class="ai-agent-btn">استعلام وضعیت</button>
                        <?php
                        wp_nonce_field('ai_agent_sync_nonce_action', 'ai_agent_sync_nonce_field');
                        wp_nonce_field('ai_agent_sync_all_nonce_action', 'ai_agent_sync_all_nonce_field');
                        wp_nonce_field('ai_agent_sync_status_nonce_action', 'ai_agent_sync_status_nonce_field');
                        ?>
                    </div>
                    <p style="margin-top:10px">
                        <span id="ai-agent-sync-status" class="ai-agent-status-text"></span>
                        <span id="ai-agent-sync-all-status" class="ai-agent-status-text"></span>
                        <span id="ai-agent-check-status-status" class="ai-agent-status-text"></span>
                    </p>

                    <div class="ai-agent-stat-grid">
                        <div class="ai-agent-stat ai-agent-last-sync-item" data-sync-slot="last">
                            <span class="ai-agent-stat-label">آخرین به‌روزرسانی</span>
                            <span class="ai-agent-stat-value ai-agent-last-sync-value<?php echo empty($last_sync_time) ? ' is-empty' : ''; ?>"><?php
                                echo !empty($last_sync_time) ? esc_html($last_sync_time) : 'ثبت نشده';
                            ?></span>
                        </div>
                        <div class="ai-agent-stat ai-agent-last-sync-item" data-sync-slot="all">
                            <span class="ai-agent-stat-label">ایندکس کامل</span>
                            <span class="ai-agent-stat-value ai-agent-last-sync-value<?php echo empty($last_sync_all_time) ? ' is-empty' : ''; ?>"><?php
                                echo !empty($last_sync_all_time) ? esc_html($last_sync_all_time) : 'ثبت نشده';
                            ?></span>
                        </div>
                        <div class="ai-agent-stat">
                            <span class="ai-agent-stat-label">آخرین اجرای خودکار</span>
                            <span class="ai-agent-stat-value<?php echo empty($last_scheduled['time']) ? ' is-empty' : ''; ?>"><?php
                                echo !empty($last_scheduled['time']) ? esc_html($last_scheduled['time']) : 'هنوز اجرا نشده';
                            ?></span>
                        </div>
                    </div>
                </section>

                <!-- ---------- ربات تلگرام و بله ---------- -->
                <section class="ai-agent-section" id="ai-agent-bots-section">
                    <div class="ai-agent-section-head">
                        <h2>ربات پشتیبانی در تلگرام و بله</h2>
                        <span class="ai-agent-badge" id="ai-agent-bots-badge">در حال بررسی…</span>
                    </div>
                    <p class="ai-agent-section-intro">
                        همین دستیار می‌تونه توی تلگرام یا بله هم جواب مشتری‌هات رو بده — با همون
                        اطلاعات سایت و همون مدلی که بالا انتخاب کردی. کافیه توکن ربات رو بدی.
                        می‌تونی هر دو رو وصل کنی؛ اگه یه روز تلگرام قطع شد، بله سرِ پاست.
                    </p>

                    <?php wp_nonce_field('ai_agent_bots_nonce_action', 'ai_agent_bots_nonce_field'); ?>

                    <div class="ai-agent-bot-cards">
                        <?php
                        $bot_platforms = array(
                            'telegram' => array(
                                'label'   => 'تلگرام',
                                'father'  => '@BotFather',
                                'link'    => 'https://t.me/BotFather',
                                'example' => '@yourshop_bot',
                            ),
                            'bale' => array(
                                'label'   => 'بله',
                                'father'  => '@botfather',
                                'link'    => 'https://ble.ir/botfather',
                                'example' => '@yourshop_bot',
                            ),
                        );
                        foreach ($bot_platforms as $platform => $meta) : ?>
                            <div class="ai-agent-bot-card" data-platform="<?php echo esc_attr($platform); ?>">
                                <div class="ai-agent-bot-card-head">
                                    <strong><?php echo esc_html($meta['label']); ?></strong>
                                    <span class="ai-agent-badge ai-agent-bot-state">وصل نیست</span>
                                </div>

                                <p class="ai-agent-hint ai-agent-bot-username" hidden></p>

                                <div class="ai-agent-btn-row">
                                    <input type="password" class="ai-agent-input ai-agent-input-sm dc-ltr ai-agent-bot-token"
                                           lang="en" autocomplete="off" placeholder="123456789:AA..."
                                           style="flex:1 1 240px;min-width:0;width:auto" />
                                    <button type="button" class="ai-agent-btn ai-agent-btn-primary ai-agent-bot-save">ذخیره و اتصال</button>
                                    <button type="button" class="ai-agent-btn ai-agent-bot-delete" hidden>حذف ربات</button>
                                </div>
                                <span class="ai-agent-status-text ai-agent-bot-status"></span>

                                <details class="ai-agent-bot-guide">
                                    <summary>توکن رو از کجا بیارم؟</summary>
                                    <ol>
                                        <li>
                                            توی <?php echo esc_html($meta['label']); ?> برو سراغ
                                            <a href="<?php echo esc_url($meta['link']); ?>" target="_blank" rel="noopener" class="dc-ltr" lang="en"><?php echo esc_html($meta['father']); ?></a>
                                            و استارتش کن.
                                        </li>
                                        <li>دستور <code class="dc-ltr" lang="en">/newbot</code> رو بفرست.</li>
                                        <li>یه اسم برای ربات بذار (مثلاً «پشتیبانی <?php echo esc_html(get_bloginfo('name')); ?>»).</li>
                                        <li>
                                            بعد آیدی ربات رو می‌خواد. آیدی <strong>حتماً</strong> باید به
                                            <code class="dc-ltr" lang="en">bot</code> ختم بشه —
                                            مثلاً <code class="dc-ltr" lang="en"><?php echo esc_html($meta['example']); ?></code>.
                                        </li>
                                        <li>یه توکن بلند بهت می‌ده؛ کاملش رو کپی کن و همین بالا بچسبون و «ذخیره و اتصال» رو بزن.</li>
                                    </ol>
                                    <p class="ai-agent-hint">
                                        بعدش حتماً برای ربات یه عکس پروفایل (<code class="dc-ltr" lang="en">/setuserpic</code>)،
                                        یه اسم درست‌وحسابی (<code class="dc-ltr" lang="en">/setname</code>) و یه توضیح کوتاه
                                        (<code class="dc-ltr" lang="en">/setdescription</code>) بذار. مشتری قبل از اینکه
                                        حرف بزنه، همین‌ها رو می‌بینه.
                                    </p>
                                </details>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="ai-agent-hint" style="margin-top:16px">
                        وقتی حداقل یکی از این دو وصل باشه، توی چت سایت هم یه دکمه اضافه می‌شه که
                        بازدیدکننده می‌تونه ادامه‌ی گفت‌وگوش رو ببره توی پیام‌رسان — به‌دردِ وقتی
                        می‌خوره که پشتیبان انسانی آنلاین نیست و کاربر نمی‌تونه پای سایت منتظر بمونه.
                    </p>
                </section>

                <!-- ---------- دانش دستی و پرسش‌وپاسخ ---------- -->
                <section class="ai-agent-section" id="ai-agent-knowledge-section">
                    <div class="ai-agent-section-head">
                        <h2>اطلاعات اضافه و پرسش‌وپاسخ</h2>
                        <span class="ai-agent-badge" id="ai-agent-knowledge-badge">—</span>
                    </div>
                    <p class="ai-agent-section-intro">
                        هرچی توی سایت نیست ولی دستیار باید بدونه، همین‌جا اضافه کن: فایل اکسل قیمت‌ها،
                        سوالات پرتکرار، یه PDF، یا حتی یه عکس. کنار محتوای سایت ایندکس می‌شه و دستیار
                        موقع جواب‌دادن ازش استفاده می‌کنه.
                    </p>

                    <?php wp_nonce_field('ai_agent_knowledge_nonce_action', 'ai_agent_knowledge_nonce_field'); ?>

                    <div class="ai-agent-knowledge-tabs" role="tablist">
                        <button type="button" class="ai-agent-segment is-active" data-knowledge-tab="documents" role="tab">اسناد و فایل‌ها</button>
                        <button type="button" class="ai-agent-segment" data-knowledge-tab="qa" role="tab">پرسش‌وپاسخ</button>
                    </div>

                    <!-- اسناد -->
                    <div class="ai-agent-knowledge-panel is-active" data-knowledge-panel="documents">
                        <div class="ai-agent-field">
                            <span class="ai-agent-label">آپلود فایل</span>
                            <div class="ai-agent-btn-row">
                                <input type="file" id="ai-agent-knowledge-file" class="ai-agent-input" style="flex:1 1 260px;min-width:0"
                                       accept=".xlsx,.xlsm,.csv,.tsv,.pdf,.txt,.md,.json,.png,.jpg,.jpeg,.webp" />
                                <button type="button" id="ai-agent-knowledge-upload" class="ai-agent-btn ai-agent-btn-primary">آپلود و ایندکس</button>
                            </div>
                            <p class="ai-agent-hint">
                                اکسل (<span class="dc-ltr" lang="en">.xlsx</span>)، CSV، PDF، متن و عکس.
                                فایل اکسل قدیمی <span class="dc-ltr" lang="en">.xls</span> پشتیبانی نمی‌شه؛
                                با «ذخیره به‌صورت» تبدیلش کن به <span class="dc-ltr" lang="en">.xlsx</span>.
                            </p>
                        </div>

                        <div class="ai-agent-field">
                            <span class="ai-agent-label">یا متن رو مستقیم بنویس</span>
                            <input type="text" id="ai-agent-knowledge-title" class="ai-agent-input"
                                   placeholder="عنوان — مثلاً «شرایط مرجوعی کالا»" />
                            <textarea id="ai-agent-knowledge-content" class="ai-agent-input" rows="4"
                                      placeholder="متن کامل…"></textarea>
                            <div class="ai-agent-btn-row">
                                <button type="button" id="ai-agent-knowledge-add" class="ai-agent-btn ai-agent-btn-primary">افزودن و ایندکس</button>
                                <button type="button" id="ai-agent-knowledge-reindex-all" class="ai-agent-btn">ایندکس دوباره‌ی همه</button>
                            </div>
                            <span id="ai-agent-knowledge-status" class="ai-agent-status-text"></span>
                        </div>

                        <div id="ai-agent-knowledge-list" class="ai-agent-knowledge-list"></div>
                    </div>

                    <!-- پرسش‌وپاسخ -->
                    <div class="ai-agent-knowledge-panel" data-knowledge-panel="qa">
                        <p class="ai-agent-hint">
                            «اگه این رو پرسیدن، این رو جواب بده.» هر جفت پرسش‌وپاسخ جدا ایندکس می‌شه،
                            پس دستیار دقیقاً همون جوابی رو می‌ده که تو نوشتی.
                        </p>
                        <div class="ai-agent-field">
                            <input type="text" id="ai-agent-qa-question" class="ai-agent-input"
                                   placeholder="پرسش — مثلاً «هزینه ارسال چقدره؟»" />
                            <textarea id="ai-agent-qa-answer" class="ai-agent-input" rows="3"
                                      placeholder="پاسخی که دستیار باید بده…"></textarea>
                            <div class="ai-agent-btn-row">
                                <button type="button" id="ai-agent-qa-add" class="ai-agent-btn ai-agent-btn-primary">افزودن پرسش‌وپاسخ</button>
                            </div>
                            <span id="ai-agent-qa-status" class="ai-agent-status-text"></span>
                        </div>

                        <div id="ai-agent-qa-list" class="ai-agent-knowledge-list"></div>
                    </div>
                </section>

                <!-- ---------- پشتیبانی ---------- -->
                <section class="ai-agent-section">
                    <h2>پشتیبانی دانیچَت</h2>
                    <p class="ai-agent-section-intro">
                        به مشکلی خوردی یا سوالی داری؟ زنگ بزن یا پیام بده — همیشه در دسترسیم.
                    </p>
                    <div class="ai-agent-support-links">
                        <a class="ai-agent-support-link" href="tel:+989900668721">
                            امیرحسین محمدی <span class="dc-ltr" lang="en"><?php echo esc_html(ai_agent_fa_digits('09900668721')); ?></span>
                        </a>
                        <a class="ai-agent-support-link" href="https://t.me/dunijet_support" target="_blank" rel="noopener">
                            تلگرام <span class="dc-ltr" lang="en">@dunijet_support</span>
                        </a>
                        <a class="ai-agent-support-link" href="https://instagram.com/dunichat.ir" target="_blank" rel="noopener">
                            اینستاگرام <span class="dc-ltr" lang="en">@dunichat.ir</span>
                        </a>
                        <a class="ai-agent-support-link" href="https://instagram.com/dunijet" target="_blank" rel="noopener">
                            اینستاگرام <span class="dc-ltr" lang="en">@dunijet</span>
                        </a>
                    </div>
                    <p class="ai-agent-hint" style="margin-top:16px">
                        دانیچَت محصولی از آژانس هوشمندسازی <a href="https://dunijet.ir" target="_blank" rel="noopener">دانیجت</a> است.
                    </p>
                </section>

            </div>
        </form>
        </div>

        <!-- ================= نمای گفت‌وگوها ================= -->
        <div class="ai-agent-view" data-view-panel="history">
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
                    نشده، اصلاً گفت‌وگویی وجود ندارد که بیاید — همان را
                    می‌گوییم به‌جای «هیچ جلسه‌ای یافت نشد».
                    */ ?>
                    <div id="ai-agent-sessions-list" class="ai-agent-sessions-list">
                        <div class="ai-agent-empty"><?php
                            echo $has_api_key
                                ? 'در حال آماده‌سازی فهرست گفت‌وگوها…'
                                : 'کلید API خودتون رو وارد کنین تا گفت‌وگوها این‌جا بیاد.';
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
بارگذاری استایل/اسکریپت صفحه‌ی تنظیمات

یک فایل CSS به‌جای دو تا: SettingsStyles.css و settings-components.css
لایه‌های متناقض روی هم بودند و هر بخش صفحه ظاهر کمی متفاوتی می‌گرفت.
Chart.js هم دیگر بارگذاری نمی‌شود — نموداری در صفحه نمانده و آن کادر
خاکستریِ خالی که جایش را گرفته بود، با آن رفت.
==========================================================================
*/
function ai_agent_admin_enqueue($hook){
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    if ($page !== 'ai-agent-settings') return;

    wp_enqueue_style(
        'ai-agent-admin-css',
        AI_AGENT_URL . 'assets/css/dunichat-admin.css',
        array(),
        AI_AGENT_VERSION
    );

    wp_enqueue_script(
        'ai-agent-settings-js',
        AI_AGENT_URL . 'assets/js/settings.js',
        array('jquery'),
        AI_AGENT_VERSION,
        true
    );

    /*
    نمونه‌جمله‌ی هر لحن، تا انتخاب لحن بدون رفت‌وبرگشت به سرور، متن زیرش
    را عوض کند. همان آرایه‌ای که PHP برای رندر اولیه استفاده می‌کند.
    */
    wp_localize_script('ai-agent-settings-js', 'aiAgentAdmin', array(
        'toneExamples' => ai_agent_tone_examples(),
        'darkLift'     => AI_AGENT_DARK_LIFT,
    ));
}
add_action('admin_enqueue_scripts', 'ai_agent_admin_enqueue');
