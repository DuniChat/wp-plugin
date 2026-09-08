<?php

if (!defined('ABSPATH')) exit;

/**
 * =========================================================
 *  به‌روزرسان خودکار دانیچت (از روی سایت دانیچت)
 * =========================================================
 *
 * این کلاس آخرین نسخه‌ی منتشرشده در دانیچت را چک می‌کند و در صورت وجود
 * نسخه‌ی جدید، اعلان به‌روزرسانی را — دقیقاً مثل افزونه‌های مخزن وردپرس —
 * در صفحه‌ی افزونه‌ها نمایش می‌دهد و دانلود و نصب را خود وردپرس انجام
 * می‌دهد.
 *
 * قبلاً منبع، ریلیزهای گیت‌هاب بود. مشکلش این نبود که کار نمی‌کرد؛ مشکل
 * این بود که منتشرکننده‌ی واقعی، گیت‌هاب نبود: فایل از گیت‌هاب برداشته و
 * دستی در پنل دانیچت آپلود می‌شد، پس بین لحظه‌ای که تگ در گیت‌هاب ساخته
 * می‌شد و لحظه‌ای که همان بیلد در دانیچت قرار می‌گرفت، هزاران سایت
 * آپدیتی را پیشنهاد می‌گرفتند که هنوز منتشر نشده بود. حالا همان جایی که
 * نسخه در آن منتشر می‌شود، همان جایی است که سایت‌ها از آن می‌پرسند.
 *
 * ضمناً میزبان‌های داخل ایران معمولاً به api.github.com دسترسی ندارند، پس
 * برای بسیاری از سایت‌ها این چک اصلاً موفق نمی‌شد؛ سرور دانیچت همان جایی
 * است که افزونه برای هر کار دیگری هم با آن حرف می‌زند.
 *
 * فرآیند انتشار نسخه‌ی جدید (چک‌لیست):
 *   ۱) مقدار Version در هدر فایل ai-agent.php را افزایش بده
 *   ۲) زیپ افزونه را در پنل ادمین دانیچت آپلود کن و همان شماره‌ی نسخه و
 *      متن تغییرات را وارد کن
 *   ۳) تمام. سایت‌ها حداکثر ۱۲ ساعت بعد (یا با باز کردن صفحه‌ی افزونه‌ها،
 *      بلافاصله) آپدیت را می‌بینند.
 *
 * تشخیص مشکل:
 *   در صفحه‌ی افزونه‌ها، زیر توضیحات افزونه‌ی دانیچت، وضعیت آخرین بررسی
 *   نمایش داده می‌شود (زمان، موفقیت/خطا و کد HTTP).
 *
 * نکته: نتیجه‌ی چک تا ۱۲ ساعت کش می‌شود؛ اما هر بار که ادمین صفحه‌ی
 * افزونه‌ها یا به‌روزرسانی‌ها را باز کند یک چک تازه انجام می‌شود تا
 * نسخه‌های جدید بلافاصله دیده شوند.
 */
class Dunichat_Updater
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
     * اندپوینت اطلاعات آخرین نسخه در دانیچت
     * @var string
     */
    private $api_url;

    /**
     * کلید کش (transient)
     *
     * نامش عوض شده تا کشِ به‌جا مانده از نسخه‌های قبلی — که ساختار دیگری
     * دارد — بعد از آپدیت خوانده نشود.
     * @var string
     */
    private $cache_key = 'dunichat_latest_release';

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

    public function __construct($plugin_file)
    {
        $this->file     = $plugin_file;
        $this->basename = plugin_basename($plugin_file);
        $this->folder   = dirname($this->basename);
        // همان ریشه‌ای که بقیه‌ی افزونه با آن حرف می‌زند، تا آدرس API فقط
        // در یک جا تعریف شده باشد.
        $base = function_exists('ai_agent_api_base')
            ? ai_agent_api_base()
            : 'https://api.dunichat.ir/api/v1';
        $this->api_url = $base . '/plugin/info';

        // تزریق نسخه‌ی جدید به لیست به‌روزرسانی‌های وردپرس (مسیر رسمی وردپرس)
        add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update'));

        // تزریق لحظه‌ای هنگام خواندن transient تا ریلیز جدید بلافاصله در صفحه‌ی افزونه‌ها دیده شود
        add_filter('site_transient_update_plugins', array($this, 'inject_update_on_read'));

        // پر کردن مودال «مشاهده جزئیات نسخه» (توضیحات + تغییرات)
        add_filter('plugins_api', array($this, 'plugin_information'), 20, 3);

        // زیپ آپلودشده ممکن است نام پوشه‌ی دیگری داشته باشد یا اصلاً پوشه‌ی والد نداشته باشد؛
        // این هوک نام پوشه‌ی دانلودشده را به نام فعلی افزونه برمی‌گرداند تا آپدیت خراب نشود
        add_filter('upgrader_source_selection', array($this, 'fix_source_folder'), 10, 4);

        // پاک کردن کش بعد از پایان موفق آپدیت
        add_action('upgrader_process_complete', array($this, 'clear_cache'), 10, 2);

        // ⚠ اولویت ۵ حیاتی است: باید «قبل از» wp_update_plugins هسته (اولویت ۱۰)
        // اجرا شود تا کش پاک شده و داده‌ی تازه در همان چرخه‌ی رسمی
        // وردپرس گرفته و در transient ذخیره شود
        add_action('load-plugins.php', array($this, 'clear_cache'), 5);
        add_action('load-update-core.php', array($this, 'clear_cache'), 5);

        // نمایش وضعیت آخرین بررسی در ردیف افزونه (ابزار تشخیص مشکل)
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
            return $transient; // مثلاً سرور دانیچت در دسترس نبود
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
     * ⚠ نکته‌ی مهم (باگ نسخه‌ی 1.0.4 و قبل از آن): وردپرس در «هر ریکوئست» چندین
     * بار این transient را می‌خواند و «اولین خواندن» داخل wp_update_plugins()
     * انجام می‌شود؛ اگر کمتر از یک ساعت از آخرین چک گذشته باشد (throttle صفحه‌ی
     * افزونه‌ها)، آن متد همان‌جا return می‌کند و نتیجه‌ی تزریقِ همان خواندنِ اول
     * دور ریخته می‌شود. اگر گاردِ «فقط یک بار در هر ریکوئست» داشته باشیم،
     * خواندن‌های بعدی (رندر جدول افزونه‌ها و نمایش اعلان آپدیت) دیگر تزریق
     * نمی‌کنند و اعلان به‌روزرسانی نمایش داده نمی‌شود؛ در حالی که ردیف وضعیت،
     * چکِ موفق را نشان می‌دهد! بنابراین عمداً هیچ گارد per-request وجود ندارد
     * و هر خواندن مستقلاً تزریق می‌کند. هزینه‌ی این کار ناچیز است چون
     * get_release() به کش transient متکی است و در هر ریکوئست حداکثر یک
     * درخواست HTTP به سرور دانیچت زده می‌شود.
     *
     * همچنین ورودی‌های قبلی ذخیره‌شده در transient (no_update کهنه یا response
     * قدیمی) ممکن است کهنه باشند؛ بنابراین مگر این‌که ورودیِ معتبرِ جدیدتری از
     * نسخه‌ی نصب‌شده ثبت شده باشد، همیشه دوباره ارزیابی می‌کنیم.
     */
    public function inject_update_on_read($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        // فقط اگر آپدیتِ معتبرِ جدیدتری از نسخه‌ی نصب‌شده از قبل ثبت شده، کاری نکن
        if (isset($transient->response)
            && is_array($transient->response)
            && isset($transient->response[$this->basename])
            && is_object($transient->response[$this->basename])
            && !empty($transient->response[$this->basename]->new_version)
            && version_compare($transient->response[$this->basename]->new_version, $this->get_current_version(), '>')) {
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
            // سرور دانیچت در دسترس نبود؛ ورودی‌های فعلی دست‌نخورده می‌مانند
            return $transient;
        }

        if ($entry['available']) {
            $transient->response[$this->basename] = $entry['object'];
            unset($transient->no_update[$this->basename]);
        } else {
            $transient->no_update[$this->basename] = $entry['object'];
            unset($transient->response[$this->basename]);
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

        $new_version     = $release['version'];
        $current_version = $this->get_current_version();
        $available       = version_compare($new_version, $current_version, '>');

        $object = new stdClass();
        $object->slug        = $this->folder;
        $object->plugin      = $this->basename;
        $object->new_version = $new_version;
        $object->url         = 'https://dunichat.ir';
        $object->package     = $release['package'];
        $object->tested      = get_bloginfo('version');
        $object->requires    = '6.0';
        $object->requires_php = '7.4';

        if (!empty($release['released_at'])) {
            $object->last_updated = gmdate('Y-m-d g:i a', strtotime($release['released_at']));
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

        $download_link = $release['package'];

        $info = new stdClass();
        $info->name            = 'Dunichat';
        $info->slug            = $this->folder;
        $info->version         = $release['version'];
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
        $info->last_updated    = !empty($release['released_at'])
            ? date_i18n(get_option('date_format'), strtotime($release['released_at']))
            : '';

        $info->sections = array(
            'description' => '<p>دستیار هوشمند دانیچت محصولی از دانیجت؛ ویجت چت مبتنی بر هوش مصنوعی برای پشتیبانی آنلاین و پاسخ به سؤالات بازدیدکنندگان بر اساس محتوای واقعی سایت شما (نوشته‌ها، برگه‌ها و محصولات ووکامرس).</p>'
                . '<p><a href="https://dunichat.ir" target="_blank" rel="noopener">dunichat.ir</a></p>',
            'changelog'   => $this->format_changelog(isset($release['changelog']) ? $release['changelog'] : ''),
        );

        if (defined('AI_AGENT_URL') && defined('AI_AGENT_PATH') && file_exists(AI_AGENT_PATH . 'assets/images/logo.png')) {
            $icon = AI_AGENT_URL . 'assets/images/logo.png';
            $info->icons = array('1x' => $icon, '2x' => $icon, 'default' => $icon);
        }

        return $info;
    }

    /**
     * زیپی که آپلود شده ممکن است پوشه‌ی والدی با نام دیگری داشته باشد
     * (مثل wp-plugin-1.9.0) یا اصلاً پوشه‌ی والد نداشته باشد؛ در هر دو حالت
     * وردپرس پوشه را با نام درست (نام فعلی افزونه) جابه‌جا نمی‌کند و آپدیت
     * خراب می‌شود. این متد نام پوشه را قبل از جابه‌جایی اصلاح می‌کند.
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
     *     (تا هر رفرشِ پشت‌سرهم، درخواست اضافه به سرور نزند)
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
     * درخواست تازه به دانیچت و ذخیره در کش
     *
     * پاسخ به شکل داخلیِ ثابتی نرمال می‌شود تا بقیه‌ی کلاس به شکل دقیق
     * پاسخ سرور وابسته نباشد.
     */
    private function fetch_release()
    {
        $response = wp_remote_get($this->api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept'     => 'application/json',
                'User-Agent' => 'Dunichat-WordPress-Plugin',
            ),
        ));

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $this->store_status(false, $code, array());
            // کش منفی کوتاه تا در صورت خطا، هر ریکوئست به سرور کوبیده نشود
            set_transient($this->cache_key, array('fetched_at' => time(), 'release' => null), 10 * MINUTE_IN_SECONDS);
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        /*
        دو حالت که هر دو یعنی «چیزی برای پیشنهاددادن نیست»، نه «خطا»:
        نبودِ شماره‌ی نسخه، و available=false که یعنی هنوز هیچ زیپی در
        دانیچت آپلود نشده. در هر دو حالت باید ساکت بمانیم؛ پیشنهاد آپدیت
        به نسخه‌ای که فایلش وجود ندارد، آپدیت را وسط کار می‌شکند.
        */
        if (!is_array($body) || empty($body['version']) || empty($body['available'])) {
            $this->store_status(false, 200, array());
            set_transient($this->cache_key, array('fetched_at' => time(), 'release' => null), 10 * MINUTE_IN_SECONDS);
            return array();
        }

        $package = '';
        if (!empty($body['package_url'])) {
            $package = (string) $body['package_url'];
        } elseif (!empty($body['download_url'])) {
            $package = (string) $body['download_url'];
        }

        // آدرس نسبی یعنی سرور آدرس عمومی خودش را نمی‌داند. دانلود با چنین
        // آدرسی روی دامنه‌ی خودِ مشتری حل می‌شود و به هیچ فایلی نمی‌رسد،
        // پس به‌جای پیشنهاد آپدیتِ خراب، همان‌جا می‌ایستیم.
        if ('' === $package || 0 !== strpos($package, 'http')) {
            $this->store_status(false, 200, array());
            set_transient($this->cache_key, array('fetched_at' => time(), 'release' => null), 10 * MINUTE_IN_SECONDS);
            return array();
        }

        $release = array(
            'version'     => $this->normalize_version($body['version']),
            'package'     => $package,
            'changelog'   => isset($body['changelog']) ? (string) $body['changelog'] : '',
            'released_at' => isset($body['released_at']) ? (string) $body['released_at'] : '',
        );

        $this->store_status(true, 200, $release);
        set_transient($this->cache_key, array('fetched_at' => time(), 'release' => $release), 12 * HOUR_IN_SECONDS);

        return $release;
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
            'version' => !empty($release['version']) ? $release['version'] : '',
        ), false);
    }

    /**
     * نمایش وضعیت آخرین بررسی زیر توضیحات افزونه در صفحه‌ی افزونه‌ها
     * (ابزار تشخیص: اگر «ناموفق» بود یعنی درخواست به سرور دانیچت انجام نمی‌شود)
     */
    public function row_meta($links, $file)
    {
        if ($file !== $this->basename) {
            return $links;
        }

        $status = get_option($this->status_key);

        if (!is_array($status) || empty($status['time'])) {
            $links[] = 'بررسی آپدیت : هنوز بررسی نشده (یک بار صفحه را رفرش کنید)';

            return $links;
        }

        $ago = human_time_diff((int) $status['time'], current_time('timestamp'));

        if (!empty($status['ok'])) {
            $text = 'بررسی آپدیت : ' . $ago . ' پیش — موفق';
            if (!empty($status['version'])) {
                $text .= ' (آخرین نسخه: ' . $status['version'] . ')';
            }
        } else {
            $text = 'بررسی آپدیت : ' . $ago . ' پیش — ناموفق (کد HTTP: ' . $status['code'] . ')';
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
