<?php
/**
 * A small stand-in for WordPress, so the plugin's own logic can be tested
 * without an install.
 *
 * Only the functions the plugin actually calls are here, and they behave the
 * way WordPress does in the ways the plugin depends on -- options are stored
 * and read back, wp_parse_args fills gaps without overwriting, hooks are
 * recorded so a test can fire one. Anything a test needs to observe (the
 * options table, the recorded hooks, the outbound requests) is reachable
 * through WPStub.
 *
 * This is deliberately not a WordPress test-suite install: the point is that
 * `composer test` runs anywhere, in a second, with nothing installed. The
 * paths that genuinely need WordPress -- rendering the settings screen, the
 * REST routes -- are covered against a real WordPress instead.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

final class WPStub
{
    /** @var array<string,mixed> the options table */
    public static array $options = [];
    /** @var array<string,mixed> */
    public static array $transients = [];
    /** @var array<string,array<int,array{callback:callable,priority:int,args:int}>> */
    public static array $hooks = [];
    /** @var array<int,array{url:string,args:array}> every outbound HTTP call */
    public static array $requests = [];
    /** @var array<string,mixed> canned responses keyed by a substring of the URL */
    public static array $responses = [];
    public static bool $userCan = true;
    /** @var array<int,array{type:string,payload:mixed}> wp_send_json_* calls */
    public static array $json = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$requests = [];
        self::$responses = [];
        self::$userCan = true;
        self::$json = [];
        // Hooks are not reset: the plugin registers them once, at include time.
    }

    /** Fire a hook the way WordPress would. */
    public static function fire(string $hook, ...$args)
    {
        if (empty(self::$hooks[$hook])) {
            return $args[0] ?? null;
        }
        $entries = self::$hooks[$hook];
        usort($entries, fn($a, $b) => $a['priority'] <=> $b['priority']);
        $value = $args[0] ?? null;
        foreach ($entries as $entry) {
            $passed = array_slice($args, 0, max(1, $entry['args']));
            $result = call_user_func_array($entry['callback'], $passed);
            if ($result !== null) {
                $value = $result;
            }
        }
        return $value;
    }

    public static function hasHook(string $hook, string $callback): bool
    {
        foreach (self::$hooks[$hook] ?? [] as $entry) {
            if ($entry['callback'] === $callback) {
                return true;
            }
        }
        return false;
    }
}

/** Thrown instead of exiting, so a test can assert on wp_send_json_*. */
class WPStubJsonSent extends RuntimeException {}

// ---------------------------------------------------------------- options

function get_option($name, $default = false)
{
    return array_key_exists($name, WPStub::$options) ? WPStub::$options[$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    $old = get_option($name);
    if ($old === $value) {
        return false;
    }
    $value = WPStub::fire('pre_update_option_' . $name, $value, $old, $name);
    WPStub::$options[$name] = $value;
    WPStub::fire('update_option_' . $name, $old, $value, $name);
    return true;
}

function delete_option($name)
{
    if (!array_key_exists($name, WPStub::$options)) {
        return false;
    }
    unset(WPStub::$options[$name]);
    return true;
}

function add_option($name, $value, $d = '', $autoload = null)
{
    if (array_key_exists($name, WPStub::$options)) {
        return false;
    }
    WPStub::$options[$name] = $value;
    return true;
}

function set_transient($k, $v, $ttl = 0) { WPStub::$transients[$k] = $v; return true; }
function get_transient($k) { return WPStub::$transients[$k] ?? false; }
function delete_transient($k) { unset(WPStub::$transients[$k]); return true; }

// ---------------------------------------------------------------- hooks

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    WPStub::$hooks[$hook][] = compact('callback', 'priority', 'args');
    return true;
}

function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    return add_action($hook, $callback, $priority, $args);
}

function do_action($hook, ...$args) { WPStub::fire($hook, ...$args); }
function apply_filters($hook, $value, ...$rest) { return WPStub::fire($hook, $value, ...$rest); }
function remove_all_filters($hook, $priority = false) { unset(WPStub::$hooks[$hook]); return true; }

function remove_action($hook, $callback, $priority = 10)
{
    if (empty(WPStub::$hooks[$hook])) {
        return false;
    }
    WPStub::$hooks[$hook] = array_values(array_filter(
        WPStub::$hooks[$hook],
        fn($entry) => !($entry['callback'] === $callback && $entry['priority'] === $priority)
    ));
    return true;
}

function remove_filter($hook, $callback, $priority = 10) { return remove_action($hook, $callback, $priority); }
function has_action($hook, $callback = false) { return WPStub::hasHook($hook, (string) $callback); }

// ---------------------------------------------------------------- args / escaping

function wp_parse_args($args, $defaults = [])
{
    if (is_object($args)) {
        $args = get_object_vars($args);
    } elseif (is_string($args)) {
        parse_str($args, $args);
    }
    return is_array($defaults) ? array_merge($defaults, (array) $args) : (array) $args;
}

function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return filter_var((string) $u, FILTER_SANITIZE_URL) ?: ''; }
function esc_url_raw($u) { return esc_url($u); }
function esc_js($t) { return addslashes((string) $t); }
function esc_textarea($t) { return esc_html($t); }

function sanitize_text_field($t)
{
    $t = (string) $t;
    $t = strip_tags($t);
    $t = preg_replace('/[\r\n\t]+/', ' ', $t);
    return trim(preg_replace('/ +/', ' ', $t));
}

function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function sanitize_email($e) { return filter_var((string) $e, FILTER_SANITIZE_EMAIL) ?: ''; }

function sanitize_hex_color($color)
{
    $color = (string) $color;
    return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color) ? $color : null;
}

function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function wp_kses_post($t) { return (string) $t; }
function wp_strip_all_tags($t) { return strip_tags((string) $t); }
function absint($n) { return abs((int) $n); }

function wp_json_encode($data, $flags = 0, $depth = 512)
{
    return json_encode($data, $flags | JSON_UNESCAPED_UNICODE, $depth);
}

// ---------------------------------------------------------------- misc

function __($t, $d = null) { return $t; }
function _e($t, $d = null) { echo $t; }
function esc_html__($t, $d = null) { return esc_html($t); }
function checked($a, $b = true, $echo = true) { $r = ((string) $a === (string) $b) ? " checked='checked'" : ''; if ($echo) { echo $r; } return $r; }
function selected($a, $b = true, $echo = true) { $r = ((string) $a === (string) $b) ? " selected='selected'" : ''; if ($echo) { echo $r; } return $r; }
function disabled($a, $b = true, $echo = true) { $r = ((string) $a === (string) $b) ? " disabled='disabled'" : ''; if ($echo) { echo $r; } return $r; }

/** @var array<string,mixed> theme mods the stub reports */
function get_theme_mod($name, $default = false) { return WPStub::$options['__theme_mod_' . $name] ?? $default; }
function get_theme_mods() { return []; }
function wp_get_global_settings($path = [], $context = []) { return []; }
function get_stylesheet_directory() { return sys_get_temp_dir() . '/stub-theme'; }
function get_template_directory() { return get_stylesheet_directory(); }

function current_user_can($cap) { return WPStub::$userCan; }
function get_current_user_id() { return WPStub::$userCan ? 1 : 0; }
function is_user_logged_in() { return WPStub::$userCan; }

function admin_url($p = '') { return 'https://example.test/wp-admin/' . ltrim((string) $p, '/'); }
function home_url($p = '') { return 'https://example.test/' . ltrim((string) $p, '/'); }
function site_url($p = '') { return home_url($p); }
function get_bloginfo($what = 'name') { return $what === 'name' ? 'Example' : ''; }
function plugin_dir_path($f) { return dirname((string) $f) . '/'; }
function plugin_dir_url($f) { return 'https://example.test/wp-content/plugins/dunichat/'; }
function plugin_basename($f) { return 'dunichat/' . basename((string) $f); }
function register_setting(...$a) { return true; }
function add_menu_page(...$a) { return 'toplevel_page_stub'; }
function add_submenu_page(...$a) { return 'stub_submenu'; }
function wp_enqueue_script(...$a) { return true; }
function wp_enqueue_style(...$a) { return true; }
function wp_localize_script(...$a) { return true; }
function wp_register_script(...$a) { return true; }
function wp_nonce_field(...$a) { return ''; }
function wp_create_nonce($a = '') { return 'nonce'; }
function wp_verify_nonce($nonce, $action = -1) { return $nonce === 'nonce' ? 1 : false; }
function wp_generate_password($len = 12, $special = true, $extra = false)
{
    return substr(str_repeat(bin2hex(random_bytes(32)), 3), 0, $len);
}
function wp_rand($min = 0, $max = 0) { return $max > $min ? random_int($min, $max) : 0; }
function current_time($type = 'timestamp', $gmt = 0) { return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); }
function human_time_diff($from, $to = 0) { $to = $to ?: time(); $diff = max(0, $to - $from); return $diff < 60 ? 'چند ثانیه' : (int) round($diff / 60) . ' دقیقه'; }
function wp_date($format, $ts = null) { return gmdate($format, $ts ?? time()); }
function wp_timezone_string() { return 'Asia/Tehran'; }

function wp_send_json_success($data = null)
{
    WPStub::$json[] = ['type' => 'success', 'payload' => $data];
    throw new WPStubJsonSent('success');
}

function wp_send_json_error($data = null)
{
    WPStub::$json[] = ['type' => 'error', 'payload' => $data];
    throw new WPStubJsonSent('error');
}

// ---------------------------------------------------------------- HTTP

class WP_Error
{
    private array $errors = [];
    public function __construct($code = '', $message = '') { if ($code !== '') { $this->errors[$code] = [$message]; } }
    public function get_error_message() { $f = reset($this->errors); return $f ? $f[0] : ''; }
    public function get_error_code() { $k = array_keys($this->errors); return $k ? $k[0] : ''; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

function wp_remote_request($url, $args = [])
{
    WPStub::$requests[] = ['url' => $url, 'args' => $args];
    foreach (WPStub::$responses as $needle => $response) {
        if (strpos((string) $url, (string) $needle) !== false) {
            return $response;
        }
    }
    return ['response' => ['code' => 200], 'body' => '{}', 'headers' => []];
}

function wp_remote_get($url, $args = []) { $args['method'] = 'GET'; return wp_remote_request($url, $args); }
function wp_remote_post($url, $args = []) { $args['method'] = 'POST'; return wp_remote_request($url, $args); }
function wp_remote_retrieve_body($r) { return is_wp_error($r) ? '' : ($r['body'] ?? ''); }
function wp_remote_retrieve_response_code($r) { return is_wp_error($r) ? 0 : ($r['response']['code'] ?? 0); }
function wp_remote_retrieve_header($r, $h) { return is_wp_error($r) ? '' : ($r['headers'][$h] ?? ''); }

// ---------------------------------------------------------------- load the plugin

// WordPress's time constants, which the plugin uses for cache and cookie TTLs.
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);

// The plugin encrypts the stored API key with WordPress's AUTH_KEY.
define('AUTH_KEY', 'test-auth-key-not-a-real-secret');
define('AUTH_SALT', 'test-auth-salt-not-a-real-secret');

define('AI_AGENT_TESTS', true);
define('AI_AGENT_VERSION', '2.4.0');
define('AI_AGENT_DARK_LIFT', 0.28);
define('AI_AGENT_PATH', dirname(__DIR__) . '/');
define('AI_AGENT_URL', 'https://example.test/wp-content/plugins/dunichat/');

require_once AI_AGENT_PATH . 'includes/format.php';
require_once AI_AGENT_PATH . 'includes/site-color.php';
require_once AI_AGENT_PATH . 'includes/db.php';
require_once AI_AGENT_PATH . 'includes/settings.php';
require_once AI_AGENT_PATH . 'includes/api.php';
require_once AI_AGENT_PATH . 'includes/api-extras.php';
require_once AI_AGENT_PATH . 'includes/updater.php';
require_once AI_AGENT_PATH . 'includes/enqueue.php';
