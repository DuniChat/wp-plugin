<?php

if (!defined('ABSPATH')) exit;

/*
============================================
تشخیص رنگ اصلی سایت میزبان

رنگ پیش‌فرض دستیار نارنجیِ برند دانیچَت بود، و روی هر سایتی که نصب
می‌شد مثل چیزی از بیرون به نظر می‌رسید — یک دکمه‌ی نارنجی گوشه‌ی یک
فروشگاه آبی. حالا هنگام فعال‌سازی، یک بار رنگ اصلی خودِ سایت خوانده
می‌شود و همان پیش‌فرض قرار می‌گیرد.

فقط منابعی خوانده می‌شوند که خود وردپرس یا قالب صریحاً به‌عنوان
«رنگ» اعلام کرده‌اند؛ هیچ CSS ای پارس نمی‌شود. حدس زدن از روی
استایل‌شیت، هم شکننده است و هم معمولاً رنگ متن یا حاشیه را برمی‌دارد
نه رنگ برند را.

به ترتیب اولویت:
  ۱) theme.json قالب (Full Site Editing) — پالت رسمی قالب
  ۲) الگوی رنگ سفارشی‌ساز (custom-background / custom-header)
  ۳) گزینه‌های رایج قالب‌های پرکاربرد ایرانی و بین‌المللی

اگر هیچ‌کدام نبود، رنگ برند دانیچَت باقی می‌ماند. این تابع فقط یک بار
هنگام فعال‌سازی صدا زده می‌شود و هرگز روی رنگی که مدیر خودش انتخاب
کرده بازنویسی نمی‌کند.
============================================
*/

if (!function_exists('ai_agent_is_usable_brand_color')) {
    /**
     * آیا این رنگ به‌درد رنگ اصلی می‌خورد؟
     *
     * سفید، مشکی و خاکستری‌ها رد می‌شوند: تقریباً هر قالبی این‌ها را در
     * پالتش دارد و اگر بپذیریمشان، عملاً همیشه اولین خانه‌ی پالت انتخاب
     * می‌شود که رنگ متن است، نه رنگ برند.
     */
    function ai_agent_is_usable_brand_color($hex)
    {
        $hex = sanitize_hex_color($hex);
        if (!$hex) {
            return false;
        }

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        // اشباع کم ⇒ خاکستری/سفید/مشکی
        if ($max - $min < 40) {
            return false;
        }

        // خیلی روشن یا خیلی تیره روی دکمه‌ی سفیدنویس خوانا نیست
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        if ($luminance > 0.82 || $luminance < 0.08) {
            return false;
        }

        return true;
    }
}

if (!function_exists('ai_agent_detect_site_primary_color')) {
    function ai_agent_detect_site_primary_color()
    {
        // ۱) پالت theme.json — منبعی که خود قالب به‌عنوان رنگ‌هایش اعلام کرده
        if (class_exists('WP_Theme_JSON_Resolver')) {
            $data = WP_Theme_JSON_Resolver::get_merged_data();
            if (is_object($data) && method_exists($data, 'get_settings')) {
                $theme_settings = $data->get_settings();
                $palette = isset($theme_settings['color']['palette']['theme'])
                    ? $theme_settings['color']['palette']['theme']
                    : array();

                // اسم‌های زیر تقریباً همیشه رنگ برند را نشان می‌کنند؛ اول
                // دنبال این‌ها می‌گردیم و تنها بعدش به ترتیبِ پالت تن می‌دهیم.
                $preferred = array('primary', 'accent', 'brand', 'main', 'theme');

                foreach ($preferred as $slug) {
                    foreach ($palette as $entry) {
                        if (!isset($entry['slug'], $entry['color'])) {
                            continue;
                        }
                        if (strpos(strtolower($entry['slug']), $slug) !== false
                            && ai_agent_is_usable_brand_color($entry['color'])) {
                            return sanitize_hex_color($entry['color']);
                        }
                    }
                }

                foreach ($palette as $entry) {
                    if (isset($entry['color']) && ai_agent_is_usable_brand_color($entry['color'])) {
                        return sanitize_hex_color($entry['color']);
                    }
                }
            }
        }

        // ۲) رنگ‌های سفارشی‌ساز وردپرس
        $header_color = get_theme_mod('header_textcolor');
        if ($header_color && $header_color !== 'blank') {
            $candidate = '#' . ltrim($header_color, '#');
            if (ai_agent_is_usable_brand_color($candidate)) {
                return sanitize_hex_color($candidate);
            }
        }

        // ۳) گزینه‌های رایج قالب‌ها. هیچ‌کدام استاندارد نیستند، ولی چون فقط
        //    خوانده می‌شوند و نتیجه اعتبارسنجی می‌شود، امتحان‌کردنشان ارزان است.
        $theme_mods = array(
            'primary_color', 'accent_color', 'brand_color', 'main_color',
            'theme_color', 'site_primary_color', 'link_color',
        );
        foreach ($theme_mods as $mod) {
            $value = get_theme_mod($mod);
            if (is_string($value) && ai_agent_is_usable_brand_color($value)) {
                return sanitize_hex_color($value);
            }
        }

        return '';
    }
}

if (!function_exists('ai_agent_seed_color_from_site')) {
    /**
     * رنگ سایت را فقط یک بار، هنگام فعال‌سازی، به‌عنوان پیش‌فرض می‌نشاند.
     *
     * هرگز روی انتخاب مدیر بازنویسی نمی‌کند: اگر رنگی غیر از پیش‌فرض برند
     * ذخیره شده باشد، یعنی کسی آگاهانه انتخابش کرده و دست نمی‌خورد.
     */
    function ai_agent_seed_color_from_site()
    {
        $saved = get_option('ai_agent_settings', array());
        if (!is_array($saved)) {
            $saved = array();
        }

        $brand_default = '#F4865B';
        $light = isset($saved['color_light']) ? sanitize_hex_color($saved['color_light']) : '';
        $dark  = isset($saved['color_dark'])  ? sanitize_hex_color($saved['color_dark'])  : '';

        $untouched = (!$light || strtolower($light) === strtolower($brand_default))
                  && (!$dark  || strtolower($dark)  === strtolower($brand_default));
        if (!$untouched) {
            return;
        }

        $detected = ai_agent_detect_site_primary_color();
        if (!$detected) {
            return;
        }

        $saved['color_light'] = $detected;
        // در حالت تاریک همان رنگ کمی روشن‌تر می‌شود. یک رنگ برندِ تیره روی
        // پس‌زمینه‌ی مشکی عملاً ناپیدا می‌شود، و کاربر نباید مجبور باشد
        // دستی دنبال ورینت تیره‌اش بگردد.
        $saved['color_dark'] = ai_agent_lighten_hex($detected, 0.18);

        update_option('ai_agent_settings', $saved);
    }
}

/*
============================================
لیست رنگ‌های نام‌دار خودِ سایت (نه فقط یک حدس، بلکه چند گزینه)

برای بخش «رنگ دستیار» در صفحه‌ی تنظیمات: کاربر باید بتواند از میان
رنگ‌هایی که خودِ سایتش (وردپرس یا المنتور) به‌عنوان پالت رسمی‌اش
معرفی کرده، یکی را با یک کلیک انتخاب کند — نه اینکه کد هگز را از
یک‌جای دیگر کپی کند.

اولویت با پالت المنتور است (اگر نصب و پیکربندی شده باشد)، چون
بیشتر سایت‌های فارسی‌زبان با آن ساخته می‌شوند و رنگ‌هایش دقیقاً
همان‌هایی هستند که در طراحی سایت استفاده شده. اگر المنتور در کار
نبود، به پالت theme.json قالب برمی‌گردیم.
============================================
*/

if (!function_exists('ai_agent_get_elementor_colors')) {
    /** رنگ‌های سراسری المنتور (Global Colors)، اگر افزونه‌ی المنتور فعال باشد. */
    function ai_agent_get_elementor_colors()
    {
        $kit_id = get_option('elementor_active_kit');
        if (!$kit_id) {
            return array();
        }

        $page_settings = get_post_meta((int) $kit_id, '_elementor_page_settings', true);
        if (!is_array($page_settings)) {
            return array();
        }

        // عنوان‌های پیش‌فرض چهار رنگ سیستمی المنتور، برای وقتی که کاربر
        // خودش اسمی رویشان نگذاشته است.
        $default_labels = array(
            'primary'   => 'رنگ اصلی سایت',
            'secondary' => 'رنگ دوم سایت',
            'text'      => 'رنگ متن سایت',
            'accent'    => 'رنگ تأکید سایت',
        );

        $colors = array();

        if (!empty($page_settings['system_colors']) && is_array($page_settings['system_colors'])) {
            foreach ($page_settings['system_colors'] as $entry) {
                if (empty($entry['color'])) {
                    continue;
                }
                $hex = sanitize_hex_color($entry['color']);
                if (!$hex) {
                    continue;
                }
                $slug  = isset($entry['_id']) ? $entry['_id'] : '';
                $label = !empty($entry['title']) ? $entry['title'] : (isset($default_labels[$slug]) ? $default_labels[$slug] : 'رنگ سایت');
                $colors[] = array('label' => sanitize_text_field($label), 'hex' => $hex);
            }
        }

        if (!empty($page_settings['custom_colors']) && is_array($page_settings['custom_colors'])) {
            foreach ($page_settings['custom_colors'] as $entry) {
                if (empty($entry['color'])) {
                    continue;
                }
                $hex = sanitize_hex_color($entry['color']);
                if (!$hex) {
                    continue;
                }
                $label = !empty($entry['title']) ? $entry['title'] : 'رنگ سفارشی سایت';
                $colors[] = array('label' => sanitize_text_field($label), 'hex' => $hex);
            }
        }

        return $colors;
    }
}

if (!function_exists('ai_agent_get_theme_json_colors')) {
    /** پالت رنگ theme.json قالب (وردپرس Full Site Editing)، چند رنگ نه فقط یکی. */
    function ai_agent_get_theme_json_colors()
    {
        if (!class_exists('WP_Theme_JSON_Resolver')) {
            return array();
        }

        $data = WP_Theme_JSON_Resolver::get_merged_data();
        if (!is_object($data) || !method_exists($data, 'get_settings')) {
            return array();
        }

        $theme_settings = $data->get_settings();
        $palette = isset($theme_settings['color']['palette']['theme'])
            ? $theme_settings['color']['palette']['theme']
            : array();

        $colors = array();
        foreach ($palette as $entry) {
            if (empty($entry['color'])) {
                continue;
            }
            $hex = sanitize_hex_color($entry['color']);
            if (!$hex) {
                continue;
            }
            $label = !empty($entry['name']) ? $entry['name'] : (isset($entry['slug']) ? $entry['slug'] : 'رنگ قالب');
            $colors[] = array('label' => sanitize_text_field($label), 'hex' => $hex);
        }

        return $colors;
    }
}

if (!function_exists('ai_agent_get_site_colors')) {
    /**
     * حداکثر چهار رنگ نام‌دار برای پیشنهاد در بخش «رنگ دستیار».
     *
     * اول المنتور، بعد پالت قالب. هیچ‌کدام تضمین‌شده نیستند — روی سایتی
     * که هیچ‌کدام را ندارد، آرایه خالی برمی‌گردد و آن بخش از فرم اصلاً
     * نمایش داده نمی‌شود.
     */
    function ai_agent_get_site_colors()
    {
        $colors = ai_agent_get_elementor_colors();
        if (empty($colors)) {
            $colors = ai_agent_get_theme_json_colors();
        }
        return array_slice($colors, 0, 4);
    }
}

if (!function_exists('ai_agent_lighten_hex')) {
    /** رنگ را به نسبت $amount (بین ۰ تا ۱) به سمت سفید می‌برد. */
    function ai_agent_lighten_hex($hex, $amount)
    {
        $hex = sanitize_hex_color($hex);
        if (!$hex) {
            return $hex;
        }
        $amount = max(0, min(1, (float) $amount));

        $parts = array();
        foreach (array(1, 3, 5) as $offset) {
            $channel = hexdec(substr($hex, $offset, 2));
            $parts[] = str_pad(
                dechex((int) round($channel + (255 - $channel) * $amount)),
                2,
                '0',
                STR_PAD_LEFT
            );
        }

        return '#' . implode('', $parts);
    }
}
