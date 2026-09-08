<?php
/*
Plugin Name: Dunichat
Plugin URI: https://dunichat.ir
Description: دستیار هوشمند دانیچت محصولی از دانیجت
Version: 1.8.0
Requires at least: 6.0
Requires PHP: 7.4
Author: Dunijet
Author URI: https://dunijet.ir
License: GPL v2 or later
Text Domain: Dunichat
Update URI: https://github.com/DuniChat/wp-plugin
*/

if (!defined('ABSPATH')) {
    exit;
}

// Asset URLs are versioned with this, so a plugin update does not leave
// browsers serving last release's CSS from cache.
define('AI_AGENT_VERSION', '1.8.0');
/*
مقدار روشن‌شدن رنگ برند برای حالت تاریک. رنگی که روی کاغذ روشن درست
به نظر می‌رسد، روی پس‌زمینه‌ی مشکی یا می‌سوزد یا گم می‌شود؛ ۲۸٪ به‌سمت
سفید همان رنگ را در دارک خوانا نگه می‌دارد. یک‌جا تعریف شده تا رنگی که
هنگام نصب ساخته می‌شود با رنگی که هنگام ذخیره ساخته می‌شود یکی باشد —
قبلاً دو عدد متفاوت بودند و رنگ دارک بعد از اولین ذخیره عوض می‌شد.
*/
define('AI_AGENT_DARK_LIFT', 0.28);
define('AI_AGENT_PATH', plugin_dir_path(__FILE__));
define('AI_AGENT_URL', plugin_dir_url(__FILE__));


require_once AI_AGENT_PATH.'includes/format.php';
require_once AI_AGENT_PATH.'includes/site-color.php';
require_once AI_AGENT_PATH.'includes/db.php';
require_once AI_AGENT_PATH.'includes/settings.php';
require_once AI_AGENT_PATH.'includes/sync.php';
require_once AI_AGENT_PATH.'includes/enqueue.php';
require_once AI_AGENT_PATH.'includes/api.php';
require_once AI_AGENT_PATH.'includes/api-extras.php';
require_once AI_AGENT_PATH.'includes/shop-bridge.php';
require_once AI_AGENT_PATH.'includes/ajax.php';
require_once AI_AGENT_PATH.'includes/ajax-extras.php';
require_once AI_AGENT_PATH.'includes/widget.php';
require_once AI_AGENT_PATH.'includes/updater.php';
require_once AI_AGENT_PATH.'includes/scheduled-sync.php';

// به‌روزرسان خودکار افزونه از طریق ریلیزهای گیت‌هاب (DuniChat/wp-plugin)
new Dunichat_GitHub_Updater(__FILE__);

// افزودن لینک «خانه» به ردیف افزونه در صفحه‌ی افزونه‌ها
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'dunichat_plugin_action_links');
function dunichat_plugin_action_links($links)
{
    array_unshift(
        $links,
        '<a href="'.esc_url(admin_url('admin.php?page=ai-agent-settings&tab=general')).'">تنظیمات</a>',
        '<a href="https://dunichat.ir" target="_blank" rel="noopener">خانه</a>'
    );

    return $links;
}


register_activation_hook(__FILE__, 'ai_agent_install');
// یک بار، هنگام فعال‌سازی: رنگ اصلی سایت میزبان پیش‌فرض دستیار می‌شود.
register_activation_hook(__FILE__, 'ai_agent_seed_color_from_site');
register_activation_hook(__FILE__, 'ai_agent_schedule_sync_on_activation');
register_deactivation_hook(__FILE__, 'ai_agent_unschedule_sync_on_deactivation');