<?php
/*
Plugin Name: dunichat
Plugin URI: https://dunichat.ir
Description: دستیار هوشمند دانیچت محصولی از دانیجت
Version: 1.0.0
Requires at least: 6.0
Requires PHP: 7.4
Author: ManiKamyabi
Author URI: https://dunichat.ir
License: GPL v2 or later
Text Domain: dunichat
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


register_activation_hook(__FILE__, 'ai_agent_install');