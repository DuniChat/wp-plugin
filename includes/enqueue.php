<?php

if (!defined('ABSPATH')) exit;


/*
============================================
بارگذاری استایل و اسکریپت ویجت چت و پاس‌دادن تنظیمات به JavaScript
============================================
*/

/*
تبدیل رنگ HEX به رشته‌ی «R, G, B» برای استفاده در rgba(var(...))
مثال: #2563eb → «37, 99, 235»
اگر رنگ نامعتبر بود، مقدار پیش‌فرض آبی برگردانده می‌شود.
*/
if (!function_exists('ai_agent_hex_to_rgb')) {
    function ai_agent_hex_to_rgb($hex){
        $hex = ltrim(trim((string) $hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '37, 99, 235';
        }
        return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
    }
}

/*
خواندن امن یکی از دو رنگ دستیار (light یا dark):
اولویت: مقدار per-mode → کلید قدیمی color → پیش‌فرض برند
*/
if (!function_exists('ai_agent_resolve_theme_color')) {
    function ai_agent_resolve_theme_color($settings, $mode){
        $key = 'color_' . $mode;
        $color = isset($settings[$key]) ? sanitize_hex_color(trim((string) $settings[$key])) : '';
        if (!$color) {
            $color = isset($settings['color']) ? sanitize_hex_color(trim((string) $settings['color'])) : '';
        }
        if (!$color) {
            $color = '#F4865B';
        }
        return $color;
    }
}

function ai_agent_enqueue(){

    $settings = ai_agent_get_settings();

    /*
    نسخه‌ی افزونه به‌عنوان query string به هر دو فایل اضافه می‌شود
    (نه null، نه خالی) تا وقتی افزونه به‌روزرسانی می‌شود، مرورگرها و
    کش‌های میانی (CDN، پراکسی) مجبور به گرفتن نسخه‌ی تازه‌ی فایل شوند.
    */
    wp_enqueue_style(
        'ai-agent-css',
        AI_AGENT_URL.'assets/css/ai-agent.css',
        array(),
        AI_AGENT_VERSION
    );

    wp_enqueue_script(
        'ai-agent-js',
        AI_AGENT_URL.'assets/js/ai-agent.js',
        array('jquery'),
        AI_AGENT_VERSION,
        true
    );

    /*
    ============================================
    رنگ دستیار — دو رنگ مستقل برای حالت لایت و دارک

    کاربر در صفحه‌ی تنظیمات دو رنگ انتخاب می‌کند:
      - color_light: رنگ ویجت در حالت روشن
      - color_dark : رنگ ویجت در حالت تاریک

    هر دو رنگ به‌صورت متغیر CSS روی #ai-agent تزریق می‌شوند و
    متغیر --ai-agent-theme-color بر اساس data-theme (که توسط JS
    بر اساس تنظیمات تم و تم خود سایت ست می‌شود) به یکی از این دو
    رنگ اشاره می‌کند.

    دو متغیر -rgb نیز برای ساخت سایه‌های شفاف rgba(...) لازم‌اند.
    ============================================
    */
    $color_light = ai_agent_resolve_theme_color($settings, 'light');
    /*
    رنگ حالت تاریک: اگر کاربر در صفحه‌ی تنظیمات به‌صورت دستی رنگِ
    متفاوتی برای حالت تاریک انتخاب کرده (color_dark_custom = 1)، همان
    مقدار ذخیره‌شده استفاده می‌شود. در غیر این‌صورت، از همان فرمولِ
    قدیمی (ساختِ خودکار از رنگِ روشن با ضریب AI_AGENT_DARK_LIFT) استفاده
    می‌شود تا منطقِ پیشین کاملاً حفظ شود.
    */
    $color_dark_custom = isset($settings['color_dark_custom']) ? (string) $settings['color_dark_custom'] : '0';
    $saved_color_dark   = isset($settings['color_dark']) ? sanitize_hex_color($settings['color_dark']) : '';
    if ($color_dark_custom === '1' && $saved_color_dark) {
        $color_dark = $saved_color_dark;
    } else {
        $color_dark = function_exists('ai_agent_lighten_hex')
            ? ai_agent_lighten_hex($color_light, AI_AGENT_DARK_LIFT)
            : ai_agent_resolve_theme_color($settings, 'dark');
    }

    $rgb_light = ai_agent_hex_to_rgb($color_light);
    $rgb_dark  = ai_agent_hex_to_rgb($color_dark);

    /*
    حالت تم چت از تنظیمات افزونه:
      auto  → از تم خود سایت پیروی می‌کند
      light → همیشه روشن
      dark  → همیشه تاریک
    */
    $theme_mode = isset($settings['theme_mode']) ? $settings['theme_mode'] : 'auto';
    if (!in_array($theme_mode, array('auto', 'light', 'dark'), true)) {
        $theme_mode = 'auto';
    }

    wp_localize_script(
    'ai-agent-js',
    'ai_agent',
    array(
        'ajax_url'         => admin_url('admin-ajax.php'),
        'timeout'          => intval($settings['timeout']) * 1000,
        // کلید قدیمی color برای سازگاری (معادل رنگ حالت روشن)
        'color'            => $color_light,
        'color_light'      => $color_light,
        'color_dark'       => $color_dark,
        // تم دیگر داخل ویجت انتخاب نمی‌شود؛ این مقدار تعیین می‌کند که از
        // سایت پیروی کند یا روی یکی از دو حالت قفل باشد.
        'theme_mode'       => $theme_mode,
        'session_cookie'   => AI_AGENT_SESSION_COOKIE,
        // حداکثر تعداد عکس‌های مجاز در هر پیام چت (سنجاق)
        'max_images'       => defined('AI_AGENT_MAX_CHAT_IMAGES') ? AI_AGENT_MAX_CHAT_IMAGES : 4,
    )
);

    /*
    ============================================
    اعمال موقعیت انتخابی کاربر روی ویجت شناور — تفکیک بر اساس دستگاه

    سه دسته تنظیم مستقل وجود دارد:
      - موبایل  : عرض تا 768px        → فقط دکمه جابه‌جا می‌شود (پنجره تمام‌صفحه)
      - تبلت    : عرض 769 تا 1024px   → دکمه + پنجره
      - دسکتاپ  : عرض از 1025px به بالا → دکمه + پنجره

    برای هر دستگاه دو مقدار کاربر قابل کنترل است:
      - side (سمت)     : 'left' یا 'right' (پیش‌فرض 'right')
      - offset_y (px)  : عدد صحیح به پیکسل؛ مثبت ⇒ بالا، منفی ⇒ پایین، 0 ⇒ بدون تغییر

    هر دستگاه می‌تواند سمت و مقدار جابجایی متفاوتی داشته باشد.

    ============================================================
    نکات مهم:
    ============================================================
    ۱) جداسازی کامل دستگاه‌ها
       ------------------------------------------------------------
       قوانین مربوط به #ai-agent-window فقط در بازه‌های تبلت و
       دسکتاپ نوشته می‌شوند تا در موبایل (max-width: 768px) با
       حالت تمام‌صفحه‌ی پنجره‌ی چت (right:0; left:0; top:0; bottom:0)
       تداخل نکنند.

    ۲) محدودسازی خودکار (clamp) برای جلوگیری از خروج از viewport
       ------------------------------------------------------------
       با استفاده از CSS max()/min() مقادیر bottom را clamp می‌کنیم:

       دکمه (دسکتاپ/تبلت):
           bottom = max(0px, min(30px + offset_y, 100vh - 60px))
           - کف 0px  ⇒ دکمه هرگز از پایین viewport خارج نمی‌شود.
           - سقف 100vh - 60px ⇒ دکمه هرگز از بالای viewport خارج نمی‌شود
             (60px = ارتفاع دکمه در دسکتاپ).

       پنجره (دسکتاپ/تبلت):
           bottom      = max(75px, min(105px + offset_y, 100vh - 100px))
           max-height  = 100vh - bottom - 10px  (با !important)
           - کف 75px ⇒ پنجره همیشه بالای دکمه می‌ماند (60px دکمه + 15px gap).
           - سقف 100vh - 100px ⇒ پنجره هرگز بالاتر از حد مجاز نمی‌رود.
           - max-height به‌صورت پویا کاهش می‌یابد تا بالای پنجره از viewport
             خارج نشود. اگر offset_y خیلی مثبت باشد، ارتفاع پنجره کوچک
             می‌شود ولی پنجره قابل‌مشاهده باقی می‌ماند.

       دکمه (موبایل):
           bottom = max(0px, min(max(20px, safe-area) + offset_y, 100vh - 56px))
           - با safe-area-inset-bottom آیفون محاسبه می‌شود.
           - سقف 100vh - 56px (56px = ارتفاع دکمه در موبایل).

       پنجره در موبایل: دست‌نخورده — همان تمام‌صفحه می‌ماند.
    ============================================
    */

    $position_css = '';

    foreach (array('desktop', 'tablet', 'mobile') as $device) {
        $side_key   = 'button_position_side_' . $device;
        $offset_key = 'button_position_offset_y_' . $device;

        $side     = (isset($settings[$side_key]) && $settings[$side_key] === 'left') ? 'left' : 'right';
        $opposite = ($side === 'left') ? 'right' : 'left';
        $offset_y = isset($settings[$offset_key]) ? intval($settings[$offset_key]) : 0;

        // علامت برای calc: مثبت ⇒ +offset_y، منفی ⇒ -|offset_y|
        $offset_sign = ($offset_y >= 0) ? '+' : '-';
        $offset_abs  = abs($offset_y);

        if ($device === 'mobile') {
            /*
            موبایل (max-width: 768px):
            فقط دکمه‌ی شناور جابه‌جا می‌شود؛ پنجره‌ی چت تمام‌صفحه است
            و موقعیت افقی/عمودی آن نباید override شود.
            */
            $button_base = "calc(max(20px, env(safe-area-inset-bottom)) {$offset_sign} {$offset_abs}px)";

            $position_css .= sprintf(
                '
        /* ====== موبایل (max-width: 768px) — فقط دکمه جابه‌جا می‌شود ====== */
        @media (max-width: 768px) {
            #ai-agent-button {
                %1$s: 20px;
                %2$s: auto;
                bottom: max(0px, min(%3$s, calc(100vh - 56px)));
            }
        }',
                $side,
                $opposite,
                $button_base
            );
        } else {
            /*
            دسکتاپ (min-width: 1025px) و تبلت (769px تا 1024px):
            هم دکمه و هم پنجره‌ی چت با سمت و آفست همان دستگاه تنظیم می‌شوند.
            */
            $button_base = "calc(30px {$offset_sign} {$offset_abs}px)";
            $window_base = "calc(105px {$offset_sign} {$offset_abs}px)";

            if ($device === 'tablet') {
                $media = '@media (min-width: 769px) and (max-width: 1024px)';
                $title = 'تبلت (769px تا 1024px)';
            } else {
                $media = '@media (min-width: 1025px)';
                $title = 'دسکتاپ (1025px به بالا)';
            }

            $position_css .= sprintf(
                '
        /* ====== %4$s ====== */
        %5$s {
            #ai-agent-button, #ai-agent-window {
                %1$s: 30px;
                %2$s: auto;
            }
            /* دکمه: clamp بین 0 (پایین viewport) و 100vh - 60px (بالای viewport) */
            #ai-agent-button {
                bottom: max(0px, min(%3$s, calc(100vh - 60px)));
            }
            /* پنجره: bottom بین 75px (بالای دکمه) و 100vh - 100px (سقف).
               max-height پویا: 100vh - bottom - 10px ⇒ اگر offset خیلی مثبت
               باشد، ارتفاع پنجره به‌صورت خودکار کاهش می‌یابد تا از بالا
               خارج نشود. */
            #ai-agent-window {
                bottom: max(75px, min(%6$s, calc(100vh - 100px)));
                max-height: calc(100vh - max(75px, min(%6$s, calc(100vh - 100px))) - 10px) !important;
            }
        }',
                $side,
                $opposite,
                $button_base,
                $title,
                $media,
                $window_base
            );
        }
    }

    /*
    ============================================
    CSS نهایی سفارشی:
      ۱) متغیرهای رنگ دستیار (لایت/دارک) + نگاشت به --ai-agent-theme-color
      ۲) قوانین موقعیت per-device (سه media query)
    ============================================
    */
    $custom_css = "
        /* ====== رنگ دستیار: دو رنگ مستقل برای حالت لایت/دارک ====== */
        #ai-agent {
            --ai-agent-color-light: {$color_light};
            --ai-agent-color-dark: {$color_dark};
            --ai-agent-color-light-rgb: {$rgb_light};
            --ai-agent-color-dark-rgb: {$rgb_dark};
        }
        /* حالت تاریک ⇒ رنگ دارک */
        #ai-agent[data-theme=\"dark\"] {
            --ai-agent-theme-color: var(--ai-agent-color-dark);
            --ai-agent-theme-color-rgb: var(--ai-agent-color-dark-rgb);
        }
        /* حالت روشن یا قبل از اجرای JS ⇒ رنگ لایت */
        #ai-agent[data-theme=\"light\"],
        #ai-agent:not([data-theme]) {
            --ai-agent-theme-color: var(--ai-agent-color-light);
            --ai-agent-theme-color-rgb: var(--ai-agent-color-light-rgb);
        }
        /*
        عناصر رنگی ویجت از رنگِ همان حالت پیروی می‌کنند — دکمه‌ی شناور،
        هدر و دکمه‌ی ارسال. حباب پیام کاربر عمداً این‌جا نیست: رنگ برند
        روی یک بلوکِ پررنگ کنار متنِ خودِ کاربر می‌نشست و سنگین‌ترین چیز
        روی صفحه می‌شد؛ رنگ خنثی‌اش (--ai-bubble در ai-agent.css) دست‌نخورده
        می‌ماند.
        */
        #ai-agent-button {
            background: var(--ai-agent-theme-color, {$color_light});
        }
        #ai-agent-header {
            background: var(--ai-agent-theme-color, {$color_light});
        }
        {$position_css}
    ";

    wp_add_inline_style('ai-agent-css', $custom_css);

}

add_action('wp_enqueue_scripts','ai_agent_enqueue');
