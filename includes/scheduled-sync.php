<?php
/**
 * Scheduled content sync.
 *
 * Without this, a site's knowledge base drifts away from the site itself and
 * nobody notices until the assistant confidently describes a product that was
 * removed weeks ago. The owner picks a cadence in the settings (daily, every
 * three days, weekly, or manual only) and an hour, and WP-Cron runs the same
 * incremental sync the button runs.
 *
 * WP-Cron is traffic-driven, so "3am" means "the first page view after 3am".
 * That is accurate enough for re-indexing and avoids requiring a real system
 * cron on a shared host; the run itself records its own timestamp, so the
 * settings page reports when it actually happened rather than when it was due.
 */

if (!defined('ABSPATH')) exit;

const AI_AGENT_SYNC_CRON_HOOK = 'ai_agent_scheduled_sync';

/**
 * Interval in seconds for each cadence. `manual` has none: it means the event
 * is unscheduled entirely rather than scheduled and skipped.
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
 * Register the custom intervals WordPress does not ship (three-day; daily and
 * weekly already exist but are re-declared under our own names so the schedule
 * key matches the setting value exactly and cannot be confused with another
 * plugin's).
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
 * The next occurrence of `$hour` in the site's timezone, as a UTC timestamp.
 *
 * WordPress schedules in UTC while the setting is a local hour, so the two have
 * to be reconciled here rather than by assuming the server runs on Tehran time.
 */
function ai_agent_next_sync_timestamp($hour) {
    $timezone = wp_timezone();
    $now      = new DateTime('now', $timezone);
    $next     = new DateTime('now', $timezone);
    $next->setTime((int) $hour, 0, 0);

    if ($next <= $now) {
        $next->modify('+1 day');
    }

    return $next->getTimestamp();
}

/**
 * Bring the scheduled event in line with the current settings.
 *
 * Called after every settings save. Re-scheduling unconditionally would push
 * the next run forward every time the form is saved, so the existing event is
 * left alone when the cadence and hour have not changed.
 */
function ai_agent_reschedule_sync() {
    $settings = ai_agent_get_settings();
    $schedule = isset($settings['sync_schedule']) ? $settings['sync_schedule'] : 'every_3_days';
    $hour     = isset($settings['sync_hour']) ? intval($settings['sync_hour']) : 3;

    $existing = wp_get_scheduled_event(AI_AGENT_SYNC_CRON_HOOK);
    $wanted   = 'ai_agent_' . $schedule;

    if ($schedule === 'manual') {
        if ($existing) {
            wp_clear_scheduled_hook(AI_AGENT_SYNC_CRON_HOOK);
        }
        return;
    }

    if ($existing && $existing->schedule === $wanted) {
        $existing_hour = (int) wp_date('G', $existing->timestamp);
        if ($existing_hour === $hour) {
            return; // Already correct; leave the next run where it is.
        }
    }

    wp_clear_scheduled_hook(AI_AGENT_SYNC_CRON_HOOK);
    wp_schedule_event(ai_agent_next_sync_timestamp($hour), $wanted, AI_AGENT_SYNC_CRON_HOOK);
}
add_action('update_option_ai_agent_settings', 'ai_agent_reschedule_sync', 20);

/**
 * Run the sync, and remember the outcome for the settings page.
 *
 * A missing API key or no selected content types are ordinary states for a
 * half-configured site, not errors worth retrying, so the run simply records
 * why it did nothing.
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
 * Schedule on activation so a fresh install starts on the default cadence
 * without anyone having to open the settings page and press save.
 */
function ai_agent_schedule_sync_on_activation() {
    ai_agent_reschedule_sync();
}

/** Deactivation must not leave an orphaned event behind. */
function ai_agent_unschedule_sync_on_deactivation() {
    wp_clear_scheduled_hook(AI_AGENT_SYNC_CRON_HOOK);
}

/** The last scheduled run, for display. Null when none has happened. */
function ai_agent_get_last_scheduled_sync() {
    $value = get_option('ai_agent_last_scheduled_sync', null);
    return is_array($value) ? $value : null;
}
