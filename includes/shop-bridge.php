<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
============================================
پل فروشگاه: دستیار به حساب ووکامرسِ کاربرِ واردشده وصل می‌شود

دستیار می‌تواند به «سفارش‌های قبلی من چی بود؟» و «تو سبد خریدم چیه؟»
جواب بدهد و سبد را هم تغییر بدهد — افزودن، حذف، تغییر تعداد، خالی‌کردن.
همه‌ی این کارها همین‌جا و با API خودِ ووکامرس انجام می‌شود، نه با
دست‌کاری مستقیم دیتابیس: سبد خرید حالتِ خودِ ووکامرس است و تنها راه
درستِ تغییرش، همان توابعی است که خود ووکامرس برای این کار دارد. هر
پیاده‌سازی موازی، خیلی زود می‌شود یک سبد دومِ ناهماهنگ.

امنیت — دو چیزِ کاملاً جدا:

۱. «رمز پل» ثابت می‌کند که تماس‌گیرنده واقعاً سرور دانی‌چت است. این
   رمز را همین افزونه می‌سازد و یک‌بار با تنظیمات به سرور می‌فرستد؛
   سرور هیچ‌وقت آن را برنمی‌گرداند.

۲. «توکن خرید» مشخص می‌کند کدام کاربر. توکن را همین‌جا از روی نشستِ
   واقعیِ وردپرس می‌سازیم و فقط همین‌جا هم به کاربر ترجمه می‌شود.
   یعنی شناسه‌ی کاربر وردپرس هرگز از این سایت بیرون نمی‌رود، و کسی
   که رمز پل را داشته باشد هم نمی‌تواند سفارش‌های یک کاربر دلخواه را
   بخواند — فقط همان کاربری که توکنش را دارد.

توکن هرگز از مرورگر گرفته نمی‌شود. در هر درخواست چت، خودِ PHP با
get_current_user_id() کاربر را تشخیص می‌دهد؛ اگر مرورگر می‌توانست
شناسه بفرستد، هر کسی می‌توانست سفارش‌های دیگری را ببیند.
============================================
*/

// طول عمر توکن. کوتاه است چون تنها کاری که می‌کند، همراهی‌کردن یک
// گفت‌وگوی در جریان است؛ چیزی نیست که لازم باشد فردا هم کار کند.
if (!defined('AI_AGENT_SHOP_TOKEN_TTL')) {
    define('AI_AGENT_SHOP_TOKEN_TTL', HOUR_IN_SECONDS);
}

// بیشترین تعداد سفارشی که به دستیار داده می‌شود. کسی که می‌پرسد
// «قبلاً چی خریدم» منظورش خریدهای اخیرش است، نه تاریخچه‌ی پنج ساله؛
// و فرستادن صد سفارش فقط پرامپت را پر می‌کند.
if (!defined('AI_AGENT_SHOP_MAX_ORDERS')) {
    define('AI_AGENT_SHOP_MAX_ORDERS', 5);
}


/** آیا ووکامرس روی این سایت فعال است؟ */
function ai_agent_woocommerce_active() {
    return class_exists('WooCommerce') && function_exists('WC');
}


/*
--------------------------------------------
رمز پل
--------------------------------------------
*/

/**
 * رمز پل این سایت؛ اگر نبود، ساخته و ذخیره می‌شود.
 *
 * جدا از کلید API نگه داشته شده و نه مشتق‌شده از آن: کلید API در
 * دیتابیس رمزنگاری‌شده است و نقشش تأیید این سایت نزد سرور است، در حالی
 * که این رمز دقیقاً برعکس عمل می‌کند — سرور را نزد این سایت تأیید
 * می‌کند. یکی‌کردنشان یعنی یک نشتی، هر دو جهت را باز می‌کند.
 */
function ai_agent_shop_bridge_secret() {
    $secret = get_option('ai_agent_shop_bridge_secret', '');
    if (!is_string($secret) || strlen($secret) < 32) {
        $secret = wp_generate_password(48, false);
        update_option('ai_agent_shop_bridge_secret', $secret, false);
    }
    return $secret;
}


/*
--------------------------------------------
توکن خرید
--------------------------------------------
*/

/**
 * یک توکن کوتاه‌عمر برای کاربرِ واردشده‌ی فعلی، یا '' اگر وارد نشده باشد.
 *
 * توکن در یک transient به شناسه‌ی کاربر نگاشت می‌شود. همان توکن تا وقتی
 * معتبر است دوباره استفاده می‌شود، وگرنه هر پیامِ چت یک ردیف تازه در
 * جدول options می‌ساخت.
 */
function ai_agent_shop_token_for_current_user() {

    $user_id = get_current_user_id();
    if (!$user_id) {
        return '';
    }

    $existing = get_user_meta($user_id, '_ai_agent_shop_token', true);
    if (is_string($existing) && $existing !== '') {
        // اگر transient هنوز زنده است، همان توکن دوباره فرستاده می‌شود.
        if (get_transient('ai_agent_shop_token_' . $existing)) {
            return $existing;
        }
    }

    $token = wp_generate_password(32, false);
    set_transient('ai_agent_shop_token_' . $token, $user_id, AI_AGENT_SHOP_TOKEN_TTL);
    update_user_meta($user_id, '_ai_agent_shop_token', $token);

    return $token;
}

/** شناسه‌ی کاربرِ یک توکن، یا 0 اگر توکن نامعتبر یا منقضی باشد. */
function ai_agent_user_id_for_shop_token($token) {
    if (!is_string($token) || $token === '') {
        return 0;
    }
    $user_id = get_transient('ai_agent_shop_token_' . $token);
    return $user_id ? intval($user_id) : 0;
}


/*
--------------------------------------------
ثبت مسیر REST
--------------------------------------------
*/

add_action('rest_api_init', 'ai_agent_register_shop_bridge_route');
function ai_agent_register_shop_bridge_route() {
    register_rest_route('dunichat/v1', '/shop', array(
        'methods'             => 'POST',
        'callback'            => 'ai_agent_shop_bridge_handler',
        // اجازه‌ی دسترسی با رمز پل بررسی می‌شود، نه با کاربر وردپرس:
        // تماس‌گیرنده یک سرور است و هیچ نشستی ندارد.
        'permission_callback' => 'ai_agent_shop_bridge_permission',
    ));
}

function ai_agent_shop_bridge_permission(WP_REST_Request $request) {

    $provided = $request->get_header('x_dunichat_bridge_secret');
    $expected = get_option('ai_agent_shop_bridge_secret', '');

    if (!is_string($provided) || $provided === '' || !is_string($expected) || $expected === '') {
        return new WP_Error('ai_agent_forbidden', 'دسترسی مجاز نیست.', array('status' => 403));
    }

    // مقایسه‌ی زمان‌ثابت، تا مقایسه‌ی خودش رمز را حرف‌به‌حرف لو ندهد.
    if (!hash_equals($expected, $provided)) {
        return new WP_Error('ai_agent_forbidden', 'دسترسی مجاز نیست.', array('status' => 403));
    }

    return true;
}


/*
--------------------------------------------
هندلر
--------------------------------------------
*/

function ai_agent_shop_bridge_handler(WP_REST_Request $request) {

    if (!ai_agent_woocommerce_active()) {
        return rest_ensure_response(array(
            'logged_in' => false,
            'error'     => 'ووکامرس روی این سایت فعال نیست.',
        ));
    }

    $action  = sanitize_text_field((string) $request->get_param('action'));
    $token   = (string) $request->get_param('shop_token');
    $params  = $request->get_param('params');
    $params  = is_array($params) ? $params : array();

    $user_id = ai_agent_user_id_for_shop_token($token);
    if (!$user_id) {
        // نه خطا: کاربر یا وارد نشده یا نشستش تمام شده. سرور از همین
        // پاسخ می‌فهمد که باید از کاربر بخواهد وارد شود.
        return rest_ensure_response(array('logged_in' => false));
    }

    /*
    از این‌جا به بعد، کدِ ووکامرس باید فکر کند همین کاربر پای کار است:
    سبد خرید، مالیات و قیمت‌ها همه به کاربر جاری وابسته‌اند. بدون این،
    ووکامرس سبدِ مهمان را برمی‌داشت و «سبد خرید من» همیشه خالی بود.
    */
    wp_set_current_user($user_id);
    ai_agent_boot_woocommerce_for_user();

    switch ($action) {
        case 'account_summary':
            return rest_ensure_response(ai_agent_shop_account_summary($user_id, $params));

        case 'cart_modify':
            return rest_ensure_response(ai_agent_shop_cart_modify($params));

        default:
            return new WP_Error('ai_agent_bad_action', 'عملیات ناشناخته.', array('status' => 400));
    }
}

/**
 * جلسه و سبد ووکامرس را برای کاربر جاری بالا می‌آورد.
 *
 * در یک درخواست REST، ووکامرس این‌ها را خودش نمی‌سازد. بدون
 * initialize_session، سبد خریدِ ذخیره‌شده‌ی کاربر اصلاً خوانده نمی‌شود.
 */
function ai_agent_boot_woocommerce_for_user() {

    if (is_null(WC()->session)) {
        WC()->initialize_session();
    }
    if (is_null(WC()->customer)) {
        WC()->initialize_cart();
    }
    if (is_null(WC()->cart)) {
        WC()->initialize_cart();
    }

    // خواندن سبد از نشست/سبدِ ماندگارِ همین کاربر.
    if (WC()->cart) {
        WC()->cart->get_cart();
    }
}


/*
--------------------------------------------
خواندن حساب
--------------------------------------------
*/

function ai_agent_shop_account_summary($user_id, $params) {

    $include = isset($params['include']) ? sanitize_text_field($params['include']) : 'both';
    $user    = get_userdata($user_id);

    $response = array(
        'logged_in'    => true,
        'display_name' => $user ? $user->display_name : null,
        // نماد واحد پول، بدون تگ HTML: این متن مستقیم به مدل می‌رود و
        // بعد داخل یک حباب چت خوانده می‌شود، نه رندر.
        'currency'     => html_entity_decode(wp_strip_all_tags(get_woocommerce_currency_symbol()), ENT_QUOTES, 'UTF-8'),
    );

    if ($include === 'cart' || $include === 'both') {
        $response['cart'] = ai_agent_shop_cart_snapshot();
    }
    if ($include === 'orders' || $include === 'both') {
        $response['orders'] = ai_agent_shop_recent_orders($user_id);
    }

    return $response;
}

/** وضعیت فعلی سبد خرید، به شکلی که سرور می‌فهمد. */
function ai_agent_shop_cart_snapshot() {

    $cart = WC()->cart;
    if (!$cart) {
        return array('items' => array(), 'total' => 0, 'item_count' => 0);
    }

    $cart->calculate_totals();

    $items = array();
    foreach ($cart->get_cart() as $item) {
        $product = isset($item['data']) ? $item['data'] : null;
        if (!$product) {
            continue;
        }
        $items[] = array(
            // شناسه‌ای که دستیار برای حذف یا تغییر تعداد لازم دارد.
            'product_id' => intval($item['product_id']),
            'name'       => wp_strip_all_tags($product->get_name()),
            'quantity'   => intval($item['quantity']),
            'line_total' => floatval($item['line_total']),
            'permalink'  => get_permalink($item['product_id']),
        );
    }

    return array(
        'items'        => $items,
        'total'        => floatval($cart->get_total('edit')),
        'item_count'   => intval($cart->get_cart_contents_count()),
        'cart_url'     => wc_get_cart_url(),
        'checkout_url' => wc_get_checkout_url(),
    );
}

/** چند سفارش اخیر کاربر. */
function ai_agent_shop_recent_orders($user_id) {

    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'limit'       => AI_AGENT_SHOP_MAX_ORDERS,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ));

    $result = array();
    foreach ($orders as $order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = array(
                'name'     => wp_strip_all_tags($item->get_name()),
                'quantity' => intval($item->get_quantity()),
            );
        }

        $result[] = array(
            'id'           => $order->get_id(),
            'number'       => $order->get_order_number(),
            'status'       => $order->get_status(),
            // برچسب فارسیِ خودِ ووکامرس، نه ترجمه‌ی دوباره‌ی ما: هر
            // ترجمه‌ی موازی، با فروشگاهی که وضعیت سفارشی سفارشی دارد
            // نمی‌خواند.
            'status_label' => wc_get_order_status_name($order->get_status()),
            'date'         => $order->get_date_created()
                ? wp_date('Y/m/d', $order->get_date_created()->getTimestamp())
                : null,
            'total'        => floatval($order->get_total()),
            'items'        => $items,
            'view_url'     => $order->get_view_order_url(),
        );
    }

    return $result;
}


/*
--------------------------------------------
تغییر سبد خرید
--------------------------------------------
*/

function ai_agent_shop_cart_modify($params) {

    $cart = WC()->cart;
    if (!$cart) {
        return array('logged_in' => true, 'ok' => false, 'error' => 'سبد خرید در دسترس نیست.');
    }

    $operation  = isset($params['operation']) ? sanitize_text_field($params['operation']) : '';
    $product_id = isset($params['product_id']) ? absint($params['product_id']) : 0;
    $quantity   = isset($params['quantity']) ? absint($params['quantity']) : 1;

    /*
    خطاهای ووکامرس (ناموجود بودن، سقف خرید، محصول متغیری که باید
    ویژگی‌اش انتخاب شود) از طریق wc_add_notice ثبت می‌شوند، نه به‌صورت
    مقدار برگشتی. پس نوتیس‌ها را پاک می‌کنیم تا بعد از عملیات بدانیم
    این خطاها مالِ همین کار بوده‌اند و از درخواست قبلی جا نمانده‌اند.
    */
    if (function_exists('wc_clear_notices')) {
        wc_clear_notices();
    }

    $ok    = false;
    $error = '';

    switch ($operation) {
        case 'add':
            $product = wc_get_product($product_id);
            if (!$product) {
                $error = 'چنین محصولی در فروشگاه پیدا نشد.';
                break;
            }
            if ($product->is_type('variable')) {
                // افزودنِ خودسرانه‌ی یک نوع از محصول متغیر یعنی انتخاب
                // رنگ و سایز به‌جای کاربر.
                $error = sprintf(
                    'محصول «%s» چند نوع دارد و باید از صفحه‌ی محصول انتخاب شود: %s',
                    wp_strip_all_tags($product->get_name()),
                    get_permalink($product_id)
                );
                break;
            }
            $added = $cart->add_to_cart($product_id, max(1, $quantity));
            $ok    = (bool) $added;
            break;

        case 'remove':
            $key = ai_agent_cart_key_for_product($cart, $product_id);
            if (!$key) {
                $error = 'این محصول در سبد خرید نبود.';
                break;
            }
            $ok = (bool) $cart->remove_cart_item($key);
            break;

        case 'set_quantity':
            $key = ai_agent_cart_key_for_product($cart, $product_id);
            if (!$key) {
                // تعیین تعداد برای چیزی که در سبد نیست، در عمل یعنی
                // «اضافه‌اش کن».
                $added = $cart->add_to_cart($product_id, max(1, $quantity));
                $ok    = (bool) $added;
                break;
            }
            $ok = (bool) $cart->set_quantity($key, max(0, $quantity), true);
            break;

        case 'clear':
            $cart->empty_cart();
            $ok = true;
            break;

        default:
            $error = 'عملیات نامعتبر روی سبد خرید.';
            break;
    }

    if (!$ok && $error === '') {
        $error = ai_agent_first_wc_error();
    }

    $cart->calculate_totals();

    return array(
        'logged_in' => true,
        'ok'        => $ok,
        'error'     => $ok ? null : ($error ?: 'این تغییر در سبد خرید انجام نشد.'),
        'currency'  => html_entity_decode(wp_strip_all_tags(get_woocommerce_currency_symbol()), ENT_QUOTES, 'UTF-8'),
        'cart'      => ai_agent_shop_cart_snapshot(),
    );
}

/** کلید یک محصول در سبد خرید، یا '' اگر نبود. */
function ai_agent_cart_key_for_product($cart, $product_id) {
    foreach ($cart->get_cart() as $key => $item) {
        if (intval($item['product_id']) === intval($product_id)) {
            return $key;
        }
    }
    return '';
}

/**
 * اولین خطایی که ووکامرس ثبت کرده.
 *
 * پیامِ خودِ ووکامرس («موجودی کافی نیست»، «سقف خرید این کالا ۲ عدد
 * است») برای کاربر بی‌نهایت مفیدتر از «افزودن به سبد انجام نشد» است.
 */
function ai_agent_first_wc_error() {
    if (!function_exists('wc_get_notices')) {
        return '';
    }
    $notices = wc_get_notices('error');
    if (empty($notices)) {
        return '';
    }
    $first = is_array($notices[0]) && isset($notices[0]['notice']) ? $notices[0]['notice'] : $notices[0];
    return wp_strip_all_tags((string) $first);
}
