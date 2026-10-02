<?php
/**
 * Scheduled content sync.
 *
 * بدون این فایل، پایگاه دانشِ سایت به‌مرور از خود سایت فاصله می‌گیرد و
 * هیچ‌کس متوجه نمی‌شود تا وقتی که دستیار با اطمینان محصولی را توصیف
 * کند که هفته‌ها پیش حذف شده است. مدیر در تنظیمات یک ریتم انتخاب
 * می‌کند (روزانه، هر سه روز، هفتگی یا فقط دستی) و WP-Cron همان
 * سینک افزایشی‌ای را اجرا می‌کند که دکمه‌ی «به‌روزرسانی محتوا» اجرا
 * می‌کند.
 *
 * نکته‌ی WP-Cron: مبتنی بر بازدید است، یعنی «ساعت ۱۲ شب» یعنی «اولین
 * بازدید صفحه بعد از ساعت ۱۲». برای ایندکس‌کردن مجدد دقیقِ کافی است
 * و هاست اشتراکی را به کرُن سیستمی مجبور نمی‌کند؛ خودِ اجرا
 * timestamp خودش را ثبت می‌کند تا صفحه‌ی تنظیمات نشان دهد واقعاً
 * کِی اجرا شده، نه اینکه کِی قرار بود اجرا شود.
 */

if (!defined('ABSPATH')) exit;

const AI_AGENT_SYNC_CRON_HOOK = 'ai_agent_scheduled_sync';

/**
 * فاصله‌ی زمانی (ثانیه) هر ریتم. برای «فقط دستی» مقداری وجود ندارد:
 * یعنی اصلاً هیچ رویدادی زمان‌بندی نمی‌شود، نه اینکه زمان‌بندی شود و
 * رد شود.
 */
function ai_agent_sync_schedule_interval($schedule) {
    switch ($schedule) {
        case 'daily':
            return DAY_IN_SECONDS;
        case 'weekly':
            return WEEK_IN_SECONDS;
        case 'every_3_days':
            return 3 * DAY_IN_SECONDS;
    }
    return 0;
}

/**
 * ثبت بازه‌های سفارشی که وردپرس به‌صورت پیش‌فرض ندارد (سه‌روزه؛
 * روزانه و هفتگی هم موجودند اما زیر نام خودِ ما دوباره تعریف
 * می‌شوند تا کلید زمان‌بندی دقیقاً با مقدار تنظیم یکی باشد و با
 * زمان‌بندی افزونه‌ی دیگری اشتباه نشود).
 */
function ai_agent_register_cron_schedules($schedules) {
    $schedules['ai_agent_daily'] = array(
        'interval' => DAY_IN_SECONDS,
        'display'  => 'دانیچَت — روزانه',
    );
    $schedules['ai_agent_every_3_days'] = array(
        'interval' => 3 * DAY_IN_SECONDS,
        'display'  => 'دانیچَت — هر سه روز',
    );
    $schedules['ai_agent_weekly'] = array(
        'interval' => WEEK_IN_SECONDS,
        'display'  => 'دانیچَت — هفتگی',
    );
    return $schedules;
}
add_filter('cron_schedules', 'ai_agent_register_cron_schedules');

/**
 * اولین رخدادِ بعدیِ «ساعت $hour» در منطقه‌ی زمانی سایت، به‌صورت
 * UTC timestamp.
 *
 * وردپرس به UTC زمان‌بندی می‌کند ولی تنظیم، ساعتِ محلی است؛ پس این دو
 * باید همین‌جا با هم آشتی داده شوند، نه اینکه فرض کنیم سرور روی وقت
 * تهران اجرا می‌شود.
 */
function ai_agent_next_sync_timestamp($hour) {
    $hour = min(23, max(0, intval($hour)));
    $timezone = wp_timezone();
    $now      = new DateTime('now', $timezone);
    $next     = new DateTime('now', $timezone);
    $next->setTime($hour, 0, 0);

    if ($next <= $now) {
        $next->modify('+1 day');
    }

    return $next->getTimestamp();
}

/**
 * هم‌راستا کردن رویداد زمان‌بندی‌شده با تنظیمات فعلی.
 *
 * بعد از هر ذخیره‌ی تنظیمات صدا زده می‌شود. زمان‌بندیِ مجددِ بی‌قید و
 * شرط، اجرای بعدی را با هر بار ذخیره‌ی فرم به جلو هل می‌داد؛ پس وقتی
 * ریتم و ساعت تغییر نکرده‌اند، رویداد موجود دست‌نخورده می‌ماند.
 */
function ai_agent_reschedule_sync() {
    $settings = ai_agent_get_settings();
    $schedule = isset($settings['sync_schedule']) ? $settings['sync_schedule'] : 'every_3_days';
    $hour     = isset($settings['sync_hour']) ? intval($settings['sync_hour']) : 0;

    // مقدار نامعتبر هرگز نباید به wp_schedule_event برسد — هر مقداری
    // خارج از چهار ریتم شناخته‌شده مثل «دستی» رفتار می‌کند.
    if (!in_array($schedule, array('daily', 'every_3_days', 'weekly'), true)) {
        $schedule = 'manual';
    }

    $existing = wp_get_scheduled_event(AI_AGENT_SYNC_CRON_HOOK);

    if ($schedule === 'manual') {
        if ($existing) {
            wp_clear_scheduled_hook(AI_AGENT_SYNC_CRON_HOOK);
        }
        return;
    }

    $wanted = 'ai_agent_' . $schedule;

    if ($existing && $existing->schedule === $wanted) {
        $existing_hour = (int) wp_date('G', $existing->timestamp);
        if ($existing_hour === $hour) {
            return; // از قبل درست است؛ اجرای بعدی سر جای خودش می‌ماند.
        }
    }

    wp_clear_scheduled_hook(AI_AGENT_SYNC_CRON_HOOK);
    wp_schedule_event(ai_agent_next_sync_timestamp($hour), $wanted, AI_AGENT_SYNC_CRON_HOOK);
}
add_action('update_option_ai_agent_settings', 'ai_agent_reschedule_sync', 20);

/**
 * اجرای سینک و به خاطر سپردن نتیجه برای صفحه‌ی تنظیمات.
 *
 * نبودِ API Key یا انتخاب‌نشدن نوع محتوا، حالت‌های عادیِ یک سایتِ
 * نیمه‌تنظیم‌شده‌اند نه خطایی که ارزش دوباره تلاش داشته باشد؛ پس اجرا
 * فقط دلیلِ کاری‌نکردن را ثبت می‌کند.
 */
function ai_agent_run_scheduled_sync() {
    if (empty(ai_agent_get_api_key())) {
        update_option('ai_agent_last_scheduled_sync', array(
            'time'    => current_time('mysql'),
            'status'  => 'skipped',
            'message' => 'توکن سایت ثبت نشده است.',
        ), false);
        return;
    }

    $result = ai_agent_run_incremental_sync();

    update_option('ai_agent_last_scheduled_sync', array(
        'time'    => current_time('mysql'),
        'status'  => !empty($result['success']) ? 'success' : 'error',
        'message' => isset($result['data']['message']) ? $result['data']['message'] : '',
    ), false);
}
add_action(AI_AGENT_SYNC_CRON_HOOK, 'ai_agent_run_scheduled_sync');

/**
 * زمان‌بندی هنگام فعال‌سازی، تا یک نصب تازه بدون اینکه کسی لازم باشد
 * صفحه‌ی تنظیمات را باز کند و ذخیره بزند، روی ریتم پیش‌فرض شروع شود.
 */
function ai_agent_schedule_sync_on_activation() {
    ai_agent_reschedule_sync();
}

/** غیرفعال‌سازی نباید رویدادِ یتیمی به جا بگذارد. */
function ai_agent_unschedule_sync_on_deactivation() {
    wp_clear_scheduled_hook(AI_AGENT_SYNC_CRON_HOOK);
}

/** آخرین اجرای زمان‌بندی‌شده، برای نمایش. وقتی هیچ اجرایی نبوده null. */
function ai_agent_get_last_scheduled_sync() {
    $value = get_option('ai_agent_last_scheduled_sync', null);
    return is_array($value) ? $value : null;
}
