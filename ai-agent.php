<?php
/*
Plugin Name: Dunichat
Plugin URI: https://dunichat.ir
Description: دستیار هوشمند دانیچت محصولی از دانیجت
Version: 1.0.7
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

define('AI_AGENT_PATH', plugin_dir_path(__FILE__));
define('AI_AGENT_URL', plugin_dir_url(__FILE__));


require_once AI_AGENT_PATH.'includes/db.php';
require_once AI_AGENT_PATH.'includes/settings.php';
require_once AI_AGENT_PATH.'includes/sync.php';
require_once AI_AGENT_PATH.'includes/enqueue.php';
require_once AI_AGENT_PATH.'includes/api.php';
require_once AI_AGENT_PATH.'includes/ajax.php';
require_once AI_AGENT_PATH.'includes/widget.php';
require_once AI_AGENT_PATH.'includes/updater.php';

// به‌روزرسان خودکار افزونه از طریق ریلیزهای گیت‌هاب (DuniChat/wp-plugin)
new Dunichat_GitHub_Updater(__FILE__);

// افزودن لینک «خانه» به ردیف افزونه در صفحه‌ی افزونه‌ها
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'dunichat_plugin_action_links');
function dunichat_plugin_action_links($links)
{
    array_unshift($links, '<a href="https://dunichat.ir" target="_blank" rel="noopener">خانه</a>');

    return $links;
}


register_activation_hook(__FILE__, 'ai_agent_install');