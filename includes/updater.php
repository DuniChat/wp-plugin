<?php

if (!defined('ABSPATH')) exit;

/**
 * =========================================================
 *  به‌روزرسان خودکار دانیچت (بر پایه‌ی ریلیزهای گیت‌هاب)
 * =========================================================
 *
 * این کلاس آخرین ریلیز گیت‌هاب را چک می‌کند و در صورت وجود نسخه‌ی جدید،
 * اعلان به‌روزرسانی را - دقیقاً مثل افزونه‌های مخزن وردپرس - در صفحه‌ی
 * افزونه‌ها نمایش می‌دهد و فرآیند دانلود و نصب را خود وردپرس انجام می‌دهد.
 *
 * فرآیند انتشار نسخه‌ی جدید (چک‌لیست توسعه‌دهنده):
 *   ۱) مقدار Version در هدر فایل ai-agent.php را افزایش بده (مثلاً 1.0.4)
 *   ۲) تغییرات را کامیت و پوش کن
 *   ۳) در گیت‌هاب یک Release با تگ vX.Y.Z (مثلاً v1.0.4) منتشر کن
 *      - لازم نیست فایل زیپی پیوست کنی؛ اگر زیپی پیوست شود (با پسوند .zip)
 *        همان دانلود می‌شود وگرنه از زیپ سورسِ خود تگ استفاده می‌شود.
 *      - ریلیزهای Draft و Pre-release نادیده گرفته می‌شوند.
 *
 * تشخیص مشکل:
 *   در صفحه‌ی افزونه‌ها، زیر توضیحات افزونه‌ی دانیچت، وضعیت آخرین بررسی
 *   گیت‌هاب نمایش داده می‌شود (زمان، موفقیت/خطا و کد HTTP).
 *   اگر همیشه «ناموفق» بود، احتمالاً هاست شما به api.github.com دسترسی
 *   ندارد یا محدودیت نرخ درخواست (rate limit) خورده است.
 *
 * نکته: نتیجه‌ی چک تا ۱۲ ساعت کش می‌شود؛ اما هر بار که ادمین صفحه‌ی
 * افزونه‌ها یا به‌روزرسانی‌ها را باز کند (اگر بیش از ۲ دقیقه از آخرین
 * چک گذشته باشد) یک چک تازه انجام می‌شود تا ریلیزهای جدید بلافاصله
 * دیده شوند.
 */
class Dunichat_GitHub_Updater
{
    /**
     * مسیر کامل فایل اصلی افزونه
     * @var string
     */
    private $file;

    /**
     * نامک افزونه به شکل پوشه/فایل.php
     * @var string
     */
    private $basename;

    /**
     * نام پوشه‌ی فعلی افزونه (همان slug)
     * @var string
     */
    private $folder;

    /**
     * نام مخزن گیت‌هاب به شکل owner/repo
     * @var string
     */
    private $repo = 'DuniChat/wp-plugin';

    /**
     * اندپوینت آخرین ریلیز
     * @var string
     */
    private $api_url;

    /**
     * کلید کش (transient)
     * @var string
     */
    private $cache_key = 'dunichat_github_latest_release';

    /**
     * کلید گزینه‌ی وضعیت آخرین بررسی (برای نمایش در ردیف افزونه)
     * @var string
     */
    private $status_key = 'dunichat_updater_status';

    /**
     * کش نسخه‌ی فعلی (برای جلوگیری از خواندن مکرر هدر فایل)
     * @var string|null
     */
    private $current_version = null;

    /**
     * آیا در این ریکوئست، ارزیابی read-filter انجام شده است؟
     * @var bool
     */
    private $read_checked = false;

    public function __construct($plugin_file)
    {
        $this->file     = $plugin_file;
        $this->basename = plugin_basename($plugin_file);
        $this->folder   = dirname($this->basename);
        $this->api_url  = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';

        // تزریق نسخه‌ی جدید به لیست به‌روزرسانی‌های وردپرس (مسیر رسمی وردپرس)
        add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update'));

        // تزریق لحظه‌ای هنگام خواندن transient تا ریلیز جدید بلافاصله در صفحه‌ی افزونه‌ها دیده شود
        add_filter('site_transient_update_plugins', array($this, 'inject_update_on_read'));

        // پر کردن مودال «مشاهده جزئیات نسخه» (توضیحات + تغییرات)
        add_filter('plugins_api', array($this, 'plugin_information'), 20, 3);

        // زیپ گیت‌هاب یا نام پوشه‌ی دیگری دارد یا اصلاً پوشه‌ی والد ندارد؛
        // این هوک نام پوشه‌ی دانلودشده را به نام فعلی افزونه برمی‌گرداند تا آپدیت خراب نشود
        add_filter('upgrader_source_selection', array($this, 'fix_source_folder'), 10, 4);

        // پاک کردن کش بعد از پایان موفق آپدیت
        add_action('upgrader_process_complete', array($this, 'clear_cache'), 10, 2);

        // ⚠ اولویت ۵ حیاتی است: باید «قبل از» wp_update_plugins هسته (اولویت ۱۰)
        // اجرا شود تا کش گیت‌هاب پاک شده و داده‌ی تازه در همان چرخه‌ی رسمی
        // وردپرس گرفته و در transient ذخیره شود
        add_action('load-plugins.php', array($this, 'clear_cache'), 5);
        add_action('load-update-core.php', array($this, 'clear_cache'), 5);

        // نمایش وضعیت آخرین بررسی گیت‌هاب در ردیف افزونه (ابزار تشخیص مشکل)
        add_filter('plugin_row_meta', array($this, 'row_meta'), 10, 2);
    }

    /**
     * تزریق اطلاعات به‌روزرسانی به transient وردپرس
     * (به pre_set_site_transient_update_plugins وصل است و نتیجه ذخیره می‌شود)
     */
    public function inject_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }

        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = array();
        }

        $entry = $this->get_update_entry();
        if (null === $entry) {
            return $transient; // مثلاً گیت‌هاب در دسترس نبود
        }

        if ($entry['available']) {
            $transient->response[$this->basename] = $entry['object'];
            unset($transient->no_update[$this->basename]);
        } else {
            $transient->no_update[$this->basename] = $entry['object'];
        }

        return $transient;
    }

    /**
     * نسخه‌ی سبک‌ترِ همان تزریق، ولی هنگام «خواندن» transient؛
     * باعث می‌شود ریلیز تازه بدون منتظر ماندن برای چرخه‌ی رسمی وردپرس دیده شود.
     *
     * ⚠ نکته‌ی مهم: ورودی‌های قبلی (به‌خصوص no_update که از بررسی‌های قبل در
     * transient ذخیره شده) ممکن است کهنه باشند؛ بنابراین هر بار که ورودی
     * معتبری برای نسخه‌ی جدید ثبت نشده، دوباره با آخرین داده‌ی گیت‌هاب
     * ارزیابی می‌کنیم.
     */
    public function inject_update_on_read($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        // فقط اگر آپدیتِ معتبرِ جدیدتر از نسخه‌ی نصب‌شده از قبل ثبت شده، کاری نکن
        if (isset($transient->response[$this->basename])
            && is_object($transient->response[$this->basename])
            && !empty($transient->response[$this->basename]->new_version)
            && version_compare($transient->response[$this->basename]->new_version, $this->get_current_version(), '>')) {
            return $transient;
        }

        // در هر ریکوئست فقط یک بار
        if ($this->read_checked) {
            return $transient;
        }
        $this->read_checked = true;

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }

        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = array();
        }

        $entry = $this->get_update_entry();

        if (null === $entry) {
            // گیت‌هاب در دسترس نبود؛ ورودی‌های فعلی دست‌نخورده می‌مانند
            return $transient;
        }

        if ($entry['available']) {
            $transient->response[$this->basename] = $entry['object'];
            unset($transient->no_update[$this->basename]);
        } else {
            $transient->no_update[$this->basename] = $entry['object'];
        }

        return $transient;
    }

    /**
     * ساخت آبجکت به‌روزرسانی برای وردپرس
     *
     * @return array|null array( 'available' => bool, 'object' => stdClass ) یا null در صورت خطا
     */
    private function get_update_entry()
    {
        $release = $this->get_release();
        if (empty($release)) {
            return null;
        }

        $new_version     = $this->normalize_version($release['tag_name']);
        $current_version = $this->get_current_version();
        $available       = version_compare($new_version, $current_version, '>');

        $object = new stdClass();
        $object->slug        = $this->folder;
        $object->plugin      = $this->basename;
        $object->new_version = $new_version;
        $object->url         = !empty($release['html_url']) ? $release['html_url'] : 'https://dunichat.ir';
        $object->package     = $this->get_package_url($release);
        $object->tested      = get_bloginfo('version');
        $object->requires    = '6.0';
        $object->requires_php = '7.4';

        if (!empty($release['published_at'])) {
            $object->last_updated = gmdate('Y-m-d g:i a', strtotime($release['published_at']));
        }

        // آیکون افزونه در ردیف به‌روزرسانی
        if (defined('AI_AGENT_URL') && defined('AI_AGENT_PATH') && file_exists(AI_AGENT_PATH . 'assets/images/logo.png')) {
            $icon = AI_AGENT_URL . 'assets/images/logo.png';
            $object->icons = array('1x' => $icon, '2x' => $icon, 'default' => $icon);
        }

        // نشان می‌دهد این افزونه از چرخه‌ی به‌روزرسانی وردپرس پشتیبانی می‌کند
        $object->{'update-supported'} = true;

        return array(
            'available' => $available,
            'object'    => $object,
        );
    }

    /**
     * مودال «مشاهده جزئیات نسخه» در صفحه‌ی افزونه‌ها را با اطلاعات ریلیز پر می‌کند
     */
    public function plugin_information($result, $action, $args)
    {
        if ('plugin_information' !== $action) {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== $this->folder) {
            return $result;
        }

        $release = $this->get_release();
        if (empty($release)) {
            return $result;
        }

        $download_link = $this->get_package_url($release);

        $info = new stdClass();
        $info->name            = 'Dunichat';
        $info->slug            = $this->folder;
        $info->version         = $this->normalize_version($release['tag_name']);
        $info->author          = '<a href="https://dunichat.ir" target="_blank" rel="noopener">Dunijet</a>';
        $info->author_profile  = 'https://dunichat.ir';
        $info->homepage        = 'https://dunichat.ir';
        $info->download_link   = $download_link;
        $info->trunk           = $download_link;
        $info->requires        = '6.0';
        $info->tested          = get_bloginfo('version');
        $info->requires_php    = '7.4';
        $info->downloaded      = 0;
        $info->active_installs = 0;
        $info->last_updated    = !empty($release['published_at'])
            ? date_i18n(get_option('date_format'), strtotime($release['published_at']))
            : '';

        $info->sections = array(
            'description' => '<p>دستیار هوشمند دانیچت محصولی از دانیجت؛ ویجت چت مبتنی بر هوش مصنوعی برای پشتیبانی آنلاین و پاسخ به سؤالات بازدیدکنندگان بر اساس محتوای واقعی سایت شما (نوشته‌ها، برگه‌ها و محصولات ووکامرس).</p>'
                . '<p><a href="https://dunichat.ir" target="_blank" rel="noopener">dunichat.ir</a></p>',
            'changelog'   => $this->format_changelog(isset($release['body']) ? $release['body'] : ''),
        );

        if (defined('AI_AGENT_URL') && defined('AI_AGENT_PATH') && file_exists(AI_AGENT_PATH . 'assets/images/logo.png')) {
            $icon = AI_AGENT_URL . 'assets/images/logo.png';
            $info->icons = array('1x' => $icon, '2x' => $icon, 'default' => $icon);
        }

        return $info;
    }

    /**
     * گیت‌هاب سورس را یا با پوشه‌ی والدی مثل wp-plugin-v1.0.1 می‌دهد
     * یا (در حالت asset سفارشی) فایل‌ها را بدون پوشه‌ی والد زیپ می‌کند؛
     * در هر دو حالت وردپرس پیش‌فرض پوشه را با نام درست (نام فعلی افزونه) جابه‌جا نمی‌کند
     * و آپدیت خراب می‌شود. این متد نام پوشه را قبل از جابه‌جایی اصلاح می‌کند.
     */
    public function fix_source_folder($source, $remote_source, $upgrader, $hook_extra = array())
    {
        // فقط هنگام آپدیت شدنِ همین افزونه
        if (empty($hook_extra['type']) || 'plugin' !== $hook_extra['type']
            || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }

        // فقط در حالت به‌روزرسانی (نه نصب جدید)
        if (empty($hook_extra['action']) || 'update' !== $hook_extra['action']) {
            return $source;
        }

        global $wp_filesystem;

        $current_folder = basename($source);

        // اگر نام پوشه درست است کاری نکن
        if ($current_folder === $this->folder) {
            return $source;
        }

        $corrected = trailingslashit(dirname($source)) . $this->folder;

        // اگر پوشه‌ای با همین نام وجود دارد (ممکن نیست عادی باشد) حذفش کن
        if ($wp_filesystem->exists($corrected)) {
            $wp_filesystem->delete($corrected, true);
        }

        if (!$wp_filesystem->move($source, $corrected, true)) {
            return new WP_Error(
                'dunichat_rename_failed',
                'به‌روزرسانی دانیچت: تغییر نام پوشه‌ی افزونه پس از دانلود ممکن نشد.'
            );
        }

        return $corrected;
    }

    /**
     * پاک کردن کش؛ دو حالت دارد:
     *  ۱) بعد از پایان موفق آپدیت (upgrader_process_complete) → پاک کردن کامل
     *  ۲) باز شدن صفحه‌ی افزونه‌ها/به‌روزرسانی‌ها → فقط اگر بیش از ۲ دقیقه از آخرین چک گذشته باشد
     *     (تا هر رفرشِ پشت‌سرهم، درخواست اضافه به گیت‌هاب نزند)
     */
    public function clear_cache($upgrader_object = null, $options = null)
    {
        // حالت ۱: بعد از آپدیت افزونه
        if (is_array($options)) {
            if (empty($options['type']) || 'plugin' !== $options['type']) {
                return;
            }

            $plugins = array();
            if (!empty($options['plugins']) && is_array($options['plugins'])) {
                $plugins = $options['plugins'];
            } elseif (!empty($options['plugin'])) {
                $plugins = array($options['plugin']);
            }

            // فقط اگر همین افزونه آپدیت شده بود
            if (in_array($this->basename, $plugins, true)) {
                delete_transient($this->cache_key);
            }

            return;
        }

        // حالت ۲: باز شدن صفحه‌ی افزونه‌ها (با محدودیت زمانی ۲ دقیقه)
        $cache = get_transient($this->cache_key);

        if (!is_array($cache) || !isset($cache['fetched_at']) || (time() - (int) $cache['fetched_at']) > (2 * MINUTE_IN_SECONDS)) {
            delete_transient($this->cache_key);
        }
    }

    /**
     * گرفتن آخرین ریلیز (با احترام به کش)
     *
     * @return array ریلیز یا آرایه‌ی خالی در صورت خطا
     */
    private function get_release()
    {
        $cache = get_transient($this->cache_key);

        if (is_array($cache) && isset($cache['fetched_at'])) {
            // کش مثبت: ریلیز معتبر از قبل ذخیره شده
            if (!empty($cache['release'])) {
                return $cache['release'];
            }
            // کش منفی: خطای اخیر؛ تا ۱۰ دقیقه دوباره تلاش نکن
            if ((time() - (int) $cache['fetched_at']) < (10 * MINUTE_IN_SECONDS)) {
                return array();
            }
        }

        return $this->fetch_release();
    }

    /**
     * درخواست تازه به API گیت‌هاب و ذخیره در کش
     */
    private function fetch_release()
    {
        $headers = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Dunichat-WordPress-Plugin',
        );

        // اختیاری: برای رفع محدودیت نرخ درخواست (rate limit) هاست‌های اشتراکی،
        // می‌توانید توکن گیت‌هاب را در wp-config.php تعریف کنید:
        //   define('DUNICHAT_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx');
        if (defined('DUNICHAT_GITHUB_TOKEN') && DUNICHAT_GITHUB_TOKEN) {
            $headers['Authorization'] = 'Bearer ' . DUNICHAT_GITHUB_TOKEN;
        }

        $response = wp_remote_get($this->api_url, array(
            'timeout' => 15,
            'headers' => $headers,
        ));

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $this->store_status(false, $code, array());
            // کش منفی کوتاه تا در صورت خطا، هر ریکوئست به گیت‌هاب کوبیده نشود
            set_transient($this->cache_key, array('fetched_at' => time(), 'release' => null), 10 * MINUTE_IN_SECONDS);
            return array();
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($release) || empty($release['tag_name'])) {
            $this->store_status(false, 200, array());
            set_transient($this->cache_key, array('fetched_at' => time(), 'release' => null), 10 * MINUTE_IN_SECONDS);
            return array();
        }

        $this->store_status(true, 200, $release);
        set_transient($this->cache_key, array('fetched_at' => time(), 'release' => $release), 12 * HOUR_IN_SECONDS);

        return $release;
    }

    /**
     * آدرس فایل زیپ برای دانلود:
     * اولویت ۱: asset زیپی که خودت به ریلیز پیوست کرده‌ای (هر فایل .zip)
     * اولویت ۲: زیپ سورس خود تگ (zipball) که گیت‌هاب خودکار می‌سازد
     */
    private function get_package_url($release)
    {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && !empty($asset['name'])
                    && '.zip' === strtolower(substr($asset['name'], -4))) {
                    return $asset['browser_download_url'];
                }
            }
        }

        return !empty($release['zipball_url']) ? $release['zipball_url'] : '';
    }

    /**
     * نسخه‌ی فعلی نصب‌شده از هدر فایل اصلی افزونه (با کش)
     */
    private function get_current_version()
    {
        if (null === $this->current_version) {
            $data = get_file_data($this->file, array('Version' => 'Version'), 'plugin');
            $this->current_version = !empty($data['Version']) ? $data['Version'] : '0.0.0';
        }

        return $this->current_version;
    }

    /**
     * تبدیل تگ به نسخه‌ی قابل مقایسه: v1.0.1 و 1.0.1 هر دو قابل قبول‌اند
     */
    private function normalize_version($tag)
    {
        $version = ltrim((string) $tag, 'vV');

        return '' !== $version ? $version : '0.0.0';
    }

    /**
     * ذخیره‌ی وضعیت آخرین بررسی (برای نمایش در ردیف افزونه)
     */
    private function store_status($ok, $code, $release)
    {
        update_option($this->status_key, array(
            'time' => time(),
            'ok'   => (bool) $ok,
            'code' => (int) $code,
            'tag'  => !empty($release['tag_name']) ? $release['tag_name'] : '',
        ), false);
    }

    /**
     * نمایش وضعیت آخرین بررسی گیت‌هاب زیر توضیحات افزونه در صفحه‌ی افزونه‌ها
     * (ابزار تشخیص: اگر «ناموفق» بود یعنی درخواست به api.github.com انجام نمی‌شود)
     */
    public function row_meta($links, $file)
    {
        if ($file !== $this->basename) {
            return $links;
        }

        $status = get_option($this->status_key);

        if (!is_array($status) || empty($status['time'])) {
            $links[] = 'بررسی آپدیت گیت‌هاب: هنوز بررسی نشده (یک بار صفحه را رفرش کنید)';

            return $links;
        }

        $ago = human_time_diff((int) $status['time'], current_time('timestamp'));

        if (!empty($status['ok'])) {
            $text = 'بررسی آپدیت گیت‌هاب: ' . $ago . ' پیش — موفق';
            if (!empty($status['tag'])) {
                $text .= ' (آخرین ریلیز: ' . $status['tag'] . ')';
            }
        } else {
            $text = 'بررسی آپدیت گیت‌هاب: ' . $ago . ' پیش — ناموفق (کد HTTP: ' . $status['code'] . ')';
        }

        $links[] = $text;

        return $links;
    }

    /**
     * تبدیل متن ریلیز (مارک‌داون ساده) به HTML برای بخش «تغییرات» مودال
     */
    private function format_changelog($body)
    {
        if (empty($body)) {
            return '<p>تغییرات این نسخه ثبت نشده است.</p>';
        }

        $html = esc_html($body);
        $html = str_replace(array("\r\n", "\r"), "\n", $html);

        $lines = explode("\n", $html);
        $out   = array();

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            // حذف هش‌های مارک‌داون از ابتدای عنوان‌ها
            $line = preg_replace('/^#{1,6}\s*/', '', $line);

            // بولت‌های مارک‌داون به لیست
            if (preg_match('/^[-*]\s+(.*)$/', $line, $matches)) {
                $out[] = '<li>' . $matches[1] . '</li>';
                continue;
            }

            $out[] = '<p>' . $line . '</p>';
        }

        $html = implode('', $out);

        // پیوسته کردن آیتم‌های لیست متوالی داخل یک <ul>
        $html = preg_replace('/(<li>.*?<\/li>)+/s', '<ul>$0</ul>', $html);

        return $html;
    }

    /**
     * اختیاری: آپدیت کاملاً خودکار (بدون کلیک کاربر).
     * برای فعال‌سازی، هوک زیر را در __construct از کامنت خارج کن:
     *   add_filter('auto_update_plugin', array($this, 'maybe_auto_update'), 10, 2);
     */
    public function maybe_auto_update($update, $item)
    {
        if (isset($item->plugin) && $item->plugin === $this->basename) {
            return true;
        }

        return $update;
    }
}
