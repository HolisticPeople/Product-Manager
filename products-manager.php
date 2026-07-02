<?php
/**
 * Plugin Name: Products Manager
 * Description: Adds a persistent blue Products shortcut after the Inventory button in the admin top actions.
 * Author: Holistic People Dev Team
 * Version: 2.1.6
 * Requires at least: 6.0
 * Requires PHP: 8.5
 * Text Domain: hp-products-manager
 * WC requires at least: 8.0
 * WC tested up to:   10.5
 *
 * @package HP_Products_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}
if (PHP_VERSION_ID < 80500) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html(sprintf('Products Manager requires PHP 8.5 or higher. Current PHP version: %s.', PHP_VERSION)) . '</p></div>';
    });
    return;
}

// Note: WP_Query, WP_Post, WP_REST_Request, WP_REST_Server, WC_Product are global classes
// No 'use' statements needed - they were causing PHP warnings

// Declare WooCommerce feature compatibility (HPOS)
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Bootstrap class for the Products Manager plugin.
 */
final class HP_Products_Manager {
    private const REST_NAMESPACE = 'hp-products-manager/v1';

    const VERSION = '2.1.6';
    const HANDLE  = 'hp-products-manager';
    private const OLD2NEW_PACKET_CPT = 'hp_old2new_packet';
    private const OLD2NEW_LEGACY_FIELD = 'old2new_product_pairs';
    private const OLD2NEW_DEFAULT_IMPORT_OPTION = 'hp_pm_old2new_default_packets_imported';
    private const OLD2NEW_LEGACY_MIGRATION_OPTION = 'hp_pm_old2new_legacy_pairs_migrated';
    private const OLD2NEW_STATUS_MIGRATION_OPTION = 'hp_pm_old2new_statuses_migrated';
    private const OLD2NEW_BANNER_WINDOW_DAYS = 180;
    // Query param carried by Old2New links (301 redirect target, banner card
    // clicks) so the new-product page can tell referred visitors from organic
    // ones and only show the replacement banner to the former.
    private const OLD2NEW_REFERRAL_PARAM = 'o2n';
    private const ALL_LOAD_THRESHOLD = 2500; // safety fallback if too many products
    private const METRICS_CACHE_KEY = 'metrics';
    private const CACHE_GROUP       = 'hp_products_manager';
    private const METRICS_TTL       = 60; // 1 minute for fresher stats
    // Definitive cost meta key (locked)
    private const COST_META_KEY     = '_cogs_total_value';
    // ERP feature flag (enabled by default now)
    private const ERP_ENABLED       = true;
    private const ERP_SCHEMA_VERSION = '1';

    private function is_hp_inventory_erp_migrated(): bool {
        $migrated = get_option('hp_inventory_product_manager_erp_migrated') === 'yes';
        return (bool) apply_filters('hp_pm_erp_retired_by_hp_inventory', $migrated);
    }

    private function is_erp_enabled(): bool {
        // Allow enabling via filter without editing plugin
        return !$this->is_hp_inventory_erp_migrated() && (bool) apply_filters('hp_pm_erp_enabled', self::ERP_ENABLED);
    }

    private function is_erp_persist_enabled(): bool {
        if ($this->is_hp_inventory_erp_migrated()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Product-Manager ERP persistence is retired because HP Inventory owns demand and movement history.');
            }
            return false;
        }

        // Separate flag for DB writes to movements/state (now ON by default, overrideable)
        return (bool) apply_filters('hp_pm_erp_persist_enabled', true);
    }

    /**
     * Cached map of reserved quantities per product for this request.
     * @var array<int,int>
     */
    private $reserved_quantities_map = [];

    /**
     * Per-request index of Old2New old SKUs (sku => packet ID). The commerce
     * filters fire for every product render, so they must not run a query per
     * product.
     * @var array<string,int>|null
     */
    private $old2new_old_sku_index = null;

    /**
     * Retrieve the singleton instance.
     */
    public static function instance(): self {
        static $instance = null;

        if ($instance === null) {
            $instance = new self();
        }

        return $instance;
    }

    /**
     * Register admin hooks.
     */
    private function __construct() {
        add_action('init', [$this, 'register_old2new_packet_cpt']);
        add_action('init', [$this, 'register_old2new_shortcode'], 30);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_old2new_frontend_assets']);
        add_action('template_redirect', [$this, 'maybe_redirect_old2new_product'], 1);
        add_action('woocommerce_after_shop_loop_item', [$this, 'render_old2new_loop_badge'], 6);
        add_filter('get_canonical_url', [$this, 'filter_old2new_canonical_url'], 10, 2);
        add_filter('wpseo_canonical', [$this, 'filter_old2new_canonical_url'], 10, 1);
        add_filter('rank_math/frontend/canonical', [$this, 'filter_old2new_canonical_url'], 10, 1);
        add_filter('aioseo_canonical_url', [$this, 'filter_old2new_canonical_url'], 10, 1);
        // Old2New commerce policy: discontinued old products sell remaining
        // stock but never backorder; once sold out they lose price and
        // add-to-cart everywhere (single page, loops, cart re-adds).
        add_filter('woocommerce_product_get_backorders', [$this, 'filter_old2new_backorders'], 10, 2);
        add_filter('woocommerce_product_variation_get_backorders', [$this, 'filter_old2new_backorders'], 10, 2);
        add_filter('woocommerce_is_purchasable', [$this, 'filter_old2new_is_purchasable'], 10, 2);
        add_filter('woocommerce_get_price_html', [$this, 'filter_old2new_price_html'], 10, 2);
        add_filter('woocommerce_loop_add_to_cart_link', [$this, 'filter_old2new_loop_add_to_cart_link'], 10, 2);

        if (!is_admin()) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this, 'maybe_add_toolbar_button'], 80);
        add_action('admin_head', [$this, 'maybe_suppress_notices'], 0);
        add_action('in_admin_header', [$this, 'maybe_suppress_notices'], 0);
        add_action('admin_menu', [$this, 'register_admin_page'], 30);
        add_action('admin_menu', [$this, 'register_product_detail_page'], 30);
        add_filter('admin_body_class', [$this, 'maybe_flag_body_class']);
        add_action('save_post_product', [$this, 'flush_metrics_cache'], 10, 1);
        add_action('deleted_post', [$this, 'maybe_flush_deleted_product_cache'], 10, 2);
        add_action('woocommerce_update_product', [$this, 'flush_metrics_cache'], 10, 1);
        // Ensure legacy ERP tables once per schema version.
        add_action('admin_init', [$this, 'maybe_install_tables']);
        add_action('admin_init', [$this, 'maybe_import_default_old2new_packets']);
        add_action('admin_init', [$this, 'maybe_migrate_legacy_old2new_pairs']);
        add_action('admin_init', [$this, 'maybe_migrate_old2new_statuses']);

        // ERP minimal: register a single logging hook behind a feature flag
        if ($this->is_erp_enabled()) {
            add_action('woocommerce_product_set_stock', [$this, 'erp_on_product_set_stock'], 10, 1);
            add_action('woocommerce_reduce_order_stock', [$this, 'erp_on_reduce_order_stock'], 10, 1);
            add_action('woocommerce_restore_order_stock', [$this, 'erp_on_restore_order_stock'], 10, 1);
        }
    }

    /**
     * Load admin assets.
     */
    public function enqueue_admin_assets($hook_suffix): void {
        if (!current_user_can('edit_products')) {
            return;
        }

        $asset_base = plugin_dir_url(__FILE__) . 'assets/';

        wp_enqueue_style(
            self::HANDLE,
            $asset_base . 'css/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            self::HANDLE . '-script',
            $asset_base . 'js/admin.js',
            [],
            self::VERSION,
            true
        );

        $is_products_page = $hook_suffix === 'woocommerce_page_hp-products-manager';
        $is_product_detail_page = $hook_suffix === 'woocommerce_page_hp-products-manager-product'
            || (isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'hp-products-manager-product');

        if ($is_products_page || $is_product_detail_page) {
            do_action('hp_zen_enqueue_admin_surface', 'hp-products-manager');
        }

        if (!$is_products_page) {
            return;
        }

        wp_enqueue_style(
            'tabulator-css',
            'https://unpkg.com/tabulator-tables@5.5.2/dist/css/tabulator.min.css',
            [],
            '5.5.2'
        );

        wp_enqueue_style(
            self::HANDLE . '-products-css',
            $asset_base . 'css/products-page.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'tabulator-js',
            'https://unpkg.com/tabulator-tables@5.5.2/dist/js/tabulator.min.js',
            [],
            '5.5.2',
            true
        );

        wp_enqueue_script(
            self::HANDLE . '-products-js',
            $asset_base . 'js/products-page.js',
            ['tabulator-js'],
            self::VERSION,
            true
        );

        wp_localize_script(
            self::HANDLE . '-products-js',
            'HPProductsManagerData',
            [
                'restUrl' => rest_url(self::REST_NAMESPACE . '/products'),
                'nonce'   => wp_create_nonce('wp_rest'),
                'perPage' => 50,
                'currency'=> get_woocommerce_currency(),
                'locale'  => get_locale(),
                'brands'  => $this->get_brand_options(),
                'metrics' => $this->get_metrics_data(),
                'productUrlBase' => admin_url('admin.php?page=hp-products-manager-product&product_id='),
                'i18n'    => [
                    'loading'   => __('Loading products...', 'hp-products-manager'),
                    'loadError' => __('Unable to load products. Please try again.', 'hp-products-manager'),
                    'allBrands' => __('All brands', 'hp-products-manager'),
                ],
            ]
        );

        wp_enqueue_style(
            self::HANDLE . '-old2new-admin',
            $asset_base . 'css/old2new-admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            self::HANDLE . '-old2new-admin',
            $asset_base . 'js/old2new-admin.js',
            [],
            self::VERSION,
            true
        );

        wp_localize_script(
            self::HANDLE . '-old2new-admin',
            'HPOld2NewAdminData',
            [
                'packetsUrl' => rest_url(self::REST_NAMESPACE . '/old2new-packets'),
                'searchUrl'  => rest_url(self::REST_NAMESPACE . '/old2new-products/search'),
                'nonce'      => wp_create_nonce('wp_rest'),
                'productUrlBase' => admin_url('admin.php?page=hp-products-manager-product&product_id='),
                'i18n'       => [
                    'loading' => __('Loading Old2New packets...', 'hp-products-manager'),
                    'empty'   => __('No Old2New packets yet.', 'hp-products-manager'),
                    'deleteConfirm' => __('Delete this Old2New packet?', 'hp-products-manager'),
                    'defaultBadge' => __('See new product', 'hp-products-manager'),
                ],
            ]
        );
    }

    /**
     * Inject Products shortcut in admin toolbar.
     */
    public function maybe_add_toolbar_button(\WP_Admin_Bar $admin_bar): void {
        if (!is_admin_bar_showing() || !current_user_can('edit_products')) {
            return;
        }

        if ($admin_bar->get_node('hp-products-manager')) {
            return;
        }

        $admin_bar->add_node([
            'id'     => 'hp-products-manager',
            'title'  => esc_html__('Products', 'hp-products-manager'),
            'href'   => admin_url('admin.php?page=hp-products-manager'),
            'parent' => 'root-default',
            'meta'   => [
                'class'    => 'hp-products-toolbar-node hp-products-hidden',
                'title'    => esc_attr__('Go to Products', 'hp-products-manager'),
                'position' => 40,
            ],
        ]);
    }

    /**
     * Suppress global admin notices on the Products Manager page.
     */
    public function maybe_suppress_notices(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->id !== 'woocommerce_page_hp-products-manager') {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('user_admin_notices');
        remove_all_actions('network_admin_notices');
        remove_all_actions('woocommerce_admin_notices');
    }

    /**
     * Flag the admin body element so CSS can hide residual notices added later.
     */
    public function maybe_flag_body_class(string $classes): string {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && $screen->id === 'woocommerce_page_hp-products-manager') {
            $classes .= ' hp-pm-hide-notices';
        }

        return $classes;
    }

    /**
     * Register Product Manager-owned Old2New packet records.
     */
    public function register_old2new_packet_cpt(): void {
        register_post_type('hp_old2new_packet', [
            'labels' => [
                'name' => __('Old2New Packets', 'hp-products-manager'),
                'singular_name' => __('Old2New Packet', 'hp-products-manager'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'capability_type' => 'product',
            'supports' => ['title'],
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    /**
     * Register the frontend shortcode in Product Manager.
     */
    public function register_old2new_shortcode(): void {
        if (function_exists('HP_Core\register_shortcode')) {
            \HP_Core\register_shortcode('old2new_product_block', [
                'label' => 'Old2New Product Block',
                'description' => 'Displays Product Manager Old2New replacement packets.',
                'callback' => [$this, 'render_old2new_product_block'],
                'usage' => '[old2new_product_block]',
            ]);
        }

        add_shortcode('old2new_product_block', [$this, 'render_old2new_product_block']);
    }

    /**
     * Keep lifecycle status values bounded.
     */
    private function sanitize_old2new_status($status): string {
        $status = sanitize_key((string) $status);
        $legacy = [
            'replace' => 'basic_discontinue',
            'discontinue' => 'canonical',
        ];
        if (isset($legacy[$status])) {
            return $legacy[$status];
        }

        $allowed = ['basic_discontinue', 'canonical', 'hard_redirect'];

        return in_array($status, $allowed, true) ? $status : 'basic_discontinue';
    }

    /**
     * Convert status into the redirect type shown in admin/API responses.
     */
    private function old2new_redirect_type(string $status): string {
        if ($status === 'canonical') {
            return 'canonical';
        }

        if ($status === 'hard_redirect') {
            return '301';
        }

        return 'none';
    }

    private function normalize_old2new_sku(string $sku): string {
        return trim($sku);
    }

    private function old2new_product_by_sku(string $sku) {
        if ($sku === '' || !function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product')) {
            return null;
        }

        $product_id = (int) wc_get_product_id_by_sku($sku);
        if ($product_id <= 0) {
            return null;
        }

        $product = wc_get_product($product_id);

        return $product instanceof WC_Product ? $product : null;
    }

    private function old2new_product_stock_label(WC_Product $product): string {
        if ($product->managing_stock()) {
            $quantity = $product->get_stock_quantity();
            return $quantity === null ? __('Stock: managed', 'hp-products-manager') : sprintf(__('Stock: %s', 'hp-products-manager'), (string) (int) $quantity);
        }

        $status = function_exists('wc_get_stock_status_name')
            ? wc_get_stock_status_name($product->get_stock_status())
            : ucfirst((string) $product->get_stock_status());

        return sprintf(__('Stock: %s', 'hp-products-manager'), $status);
    }

    /**
     * Build Product Manager product-card payloads.
     */
    private function old2new_product_summary(WC_Product $product): array {
        $product_id = (int) $product->get_id();
        $sku = $this->normalize_old2new_sku((string) $product->get_sku());
        $image_id = (int) $product->get_image_id();

        return [
            'id' => $product_id,
            'sku' => $sku,
            // Decode stored entities (e.g. "&amp;") so JS-side escaping in the
            // admin table doesn't double-encode them into visible "&amp;".
            'name' => wp_specialchars_decode(wp_strip_all_tags((string) $product->get_name()), ENT_QUOTES),
            'image' => (string) ($this->product_image_url($product, 'woocommerce_thumbnail') ?: ''),
            'image_id' => $image_id,
            'permalink' => $product_id > 0 ? (string) get_permalink($product_id) : '',
            'admin_url' => admin_url('admin.php?page=hp-products-manager-product&product_id=' . $product_id),
            'stock' => $product->managing_stock() ? $product->get_stock_quantity() : null,
            'stock_status' => (string) $product->get_stock_status(),
            'stock_label' => $this->old2new_product_stock_label($product),
            'total_sales' => (int) get_post_meta($product_id, 'total_sales', true),
        ];
    }

    private function old2new_default_badge_text(): string {
        return __('See new product', 'hp-products-manager');
    }

    private function old2new_default_old_message(int $new_count): string {
        return $new_count > 1
            ? __('This product is no longer available. Follow Dr. Cousens\' recommendations for these {new_products}.', 'hp-products-manager')
            : __('This product is no longer available. Follow Dr. Cousens\' recommendation for this {new_product}.', 'hp-products-manager');
    }

    private function old2new_default_new_message(int $old_count): string {
        return $old_count > 1
            ? __('This product is now replacing these previous products.', 'hp-products-manager')
            : __('This product is now replacing the previous product.', 'hp-products-manager');
    }

    private function old2new_render_message_text(string $template, array $old_products, array $new_products): string {
        $old_names = array_values(array_filter(array_map(static function ($product): string {
            return is_array($product) ? (string) ($product['name'] ?? '') : '';
        }, $old_products)));
        $new_names = array_values(array_filter(array_map(static function ($product): string {
            return is_array($product) ? (string) ($product['name'] ?? '') : '';
        }, $new_products)));

        return strtr($template, [
            '{old_product}' => implode(', ', $old_names),
            '{new_product}' => $new_names[0] ?? '',
            '{new_products}' => implode(', ', $new_names),
            '{new_product_count}' => (string) count($new_products),
        ]);
    }

    /**
     * Clamp an admin-supplied banner window to sane bounds; anything invalid
     * fails closed to the 180-day default.
     */
    private function old2new_sanitize_banner_window($days): int {
        $days = absint($days);
        if ($days < 1 || $days > 3650) {
            return self::OLD2NEW_BANNER_WINDOW_DAYS;
        }

        return $days;
    }

    private function old2new_banner_expiry(string $started_at, int $window_days = self::OLD2NEW_BANNER_WINDOW_DAYS): array {
        if ($started_at === '') {
            return [
                'banner_expires_at' => '',
                'banner_expired' => false,
            ];
        }

        $timestamp = strtotime($started_at . ' +' . $window_days . ' days');
        if (!$timestamp) {
            return [
                'banner_expires_at' => '',
                'banner_expired' => false,
            ];
        }

        $expires_at = gmdate('Y-m-d', $timestamp);

        return [
            'banner_expires_at' => $expires_at,
            'banner_expired' => time() >= $timestamp,
        ];
    }

    /**
     * The canonical/301 target. An admin-selected target (stored per packet)
     * wins; otherwise auto-pick by highest total_sales, tie-break by packet
     * order.
     */
    private function old2new_select_target_product(array $new_products, int $explicit_target_id = 0): ?array {
        if ($explicit_target_id > 0) {
            foreach ($new_products as $product) {
                if (is_array($product) && (int) ($product['id'] ?? 0) === $explicit_target_id) {
                    return $product;
                }
            }
        }

        $target = null;
        foreach ($new_products as $product) {
            if (!is_array($product) || empty($product['id'])) {
                continue;
            }

            if (!$target || (int) ($product['total_sales'] ?? 0) > (int) ($target['total_sales'] ?? 0)) {
                $target = $product;
            }
        }

        return $target;
    }

    private function old2new_target_reason(?array $target, bool $admin_selected = false): string {
        if (!$target) {
            return __('No valid target product.', 'hp-products-manager');
        }

        if ($admin_selected) {
            return __('Selected by admin.', 'hp-products-manager');
        }

        return sprintf(
            __('Selected by highest total_sales (%s), tie-break by packet order.', 'hp-products-manager'),
            (string) (int) ($target['total_sales'] ?? 0)
        );
    }

    /**
     * Create a packet response from a packet post.
     */
    private function old2new_packet_response(int $packet_id): ?array {
        $post = get_post($packet_id);
        if (!$post || $post->post_type !== self::OLD2NEW_PACKET_CPT) {
            return null;
        }

        $old_product_id = (int) get_post_meta($packet_id, '_hp_old2new_old_product_id', true);
        $new_product_ids = get_post_meta($packet_id, '_hp_old2new_new_product_ids', true);
        $new_product_ids = is_array($new_product_ids) ? array_values(array_filter(array_map('absint', $new_product_ids))) : [];
        $status = $this->sanitize_old2new_status(get_post_meta($packet_id, '_hp_old2new_status', true));
        $old_product = $old_product_id > 0 ? wc_get_product($old_product_id) : null;
        $old_product_summary = $old_product instanceof WC_Product ? $this->old2new_product_summary($old_product) : null;
        $new_products = [];
        $health_warnings = [];

        if (!$old_product instanceof WC_Product) {
            $health_warnings[] = __('Missing old product.', 'hp-products-manager');
        }

        foreach ($new_product_ids as $new_product_id) {
            $new_product = wc_get_product($new_product_id);
            if ($new_product instanceof WC_Product) {
                $new_products[] = $this->old2new_product_summary($new_product);
            } else {
                $health_warnings[] = sprintf(__('Missing new product ID %s.', 'hp-products-manager'), (string) $new_product_id);
            }
        }

        if (empty($new_products)) {
            $health_warnings[] = __('No valid new products.', 'hp-products-manager');
        }

        $explicit_target_id = absint(get_post_meta($packet_id, '_hp_old2new_target_product_id', true));
        $target_product = $this->old2new_select_target_product($new_products, $explicit_target_id);
        $target_admin_selected = $explicit_target_id > 0 && $target_product && (int) ($target_product['id'] ?? 0) === $explicit_target_id;
        $banner_window_days = $this->old2new_sanitize_banner_window(get_post_meta($packet_id, '_hp_old2new_banner_window_days', true) ?: self::OLD2NEW_BANNER_WINDOW_DAYS);
        $started_at = (string) get_post_meta($packet_id, '_hp_old2new_hard_redirect_started_at', true);
        $expiry = $this->old2new_banner_expiry($started_at, $banner_window_days);
        if ($status === 'hard_redirect' && !empty($expiry['banner_expired'])) {
            $health_warnings[] = sprintf(__('Hard redirect banner window is older than %s days.', 'hp-products-manager'), (string) $banner_window_days);
        }

        // Stock-aware lifecycle guidance: promoting a packet past
        // basic_discontinue while sellable stock remains strands that stock.
        if ($old_product_summary) {
            $old_in_stock = $this->old2new_summary_in_stock($old_product_summary);
            $old_stock_label = (string) ($old_product_summary['stock'] ?? $old_product_summary['stock_label']);
            if ($old_in_stock && $status === 'hard_redirect') {
                $health_warnings[] = sprintf(__('Old product still has sellable stock (%s) but the 301 redirect makes its page unreachable.', 'hp-products-manager'), $old_stock_label);
            } elseif ($old_in_stock && $status === 'canonical') {
                $health_warnings[] = sprintf(__('Old product still has sellable stock (%s); canonical steers search traffic to the new product.', 'hp-products-manager'), $old_stock_label);
            } elseif (!$old_in_stock && $status === 'basic_discontinue') {
                $health_warnings[] = __('Old product is sold out. Consider promoting to Canonical or Hard Redirect.', 'hp-products-manager');
            }
        }

        $custom_old_message = (string) get_post_meta($packet_id, '_hp_old2new_custom_old_message', true);
        $custom_new_message = (string) get_post_meta($packet_id, '_hp_old2new_custom_new_message', true);
        $badge_text = (string) get_post_meta($packet_id, '_hp_old2new_badge_text', true);
        $badge_text = $badge_text !== '' ? $badge_text : $this->old2new_default_badge_text();
        $old_products_for_message = $old_product_summary ? [$old_product_summary] : [];
        $default_old_message = $this->old2new_default_old_message(count($new_products));
        $default_new_message = $this->old2new_default_new_message(count($old_products_for_message));

        return [
            'id' => $packet_id,
            'title' => get_the_title($packet_id),
            'old_product' => $old_product_summary,
            'new_products' => $new_products,
            'status' => $status,
            'redirect_type' => $this->old2new_redirect_type($status),
            'target_product' => $target_product,
            'target_product_id' => $explicit_target_id,
            'target_admin_selected' => $target_admin_selected,
            'target_reason' => $this->old2new_target_reason($target_product, $target_admin_selected),
            'banner_window_days' => $banner_window_days,
            'health_warnings' => array_values(array_unique($health_warnings)),
            'custom_old_message' => $custom_old_message,
            'custom_new_message' => $custom_new_message,
            'badge_text' => $badge_text,
            'default_old_message' => $default_old_message,
            'default_new_message' => $default_new_message,
            'effective_old_message' => $this->old2new_render_message_text($custom_old_message !== '' ? $custom_old_message : $default_old_message, $old_products_for_message, $new_products),
            'effective_new_message' => $this->old2new_render_message_text($custom_new_message !== '' ? $custom_new_message : $default_new_message, $old_products_for_message, $new_products),
            'hard_redirect_started_at' => $started_at,
            'banner_expires_at' => (string) $expiry['banner_expires_at'],
            'banner_expired' => (bool) $expiry['banner_expired'],
            'edit_url' => get_edit_post_link($packet_id, ''),
        ];
    }

    private function find_old2new_packet_by_old_sku(string $old_sku, int $exclude_id = 0): int {
        $old_sku = $this->normalize_old2new_sku($old_sku);
        if ($old_sku === '') {
            return 0;
        }

        $query = new WP_Query([
            'post_type' => self::OLD2NEW_PACKET_CPT,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => '_hp_old2new_old_sku',
                    'value' => $old_sku,
                    'compare' => '=',
                ],
            ],
            'post__not_in' => $exclude_id > 0 ? [$exclude_id] : [],
        ]);

        return !empty($query->posts) ? (int) $query->posts[0] : 0;
    }

    private function save_old2new_packet(array $payload, int $packet_id = 0) {
        $old_product_id = absint($payload['old_product_id'] ?? 0);
        $new_product_ids = isset($payload['new_product_ids']) && is_array($payload['new_product_ids'])
            ? array_values(array_filter(array_map('absint', $payload['new_product_ids'])))
            : [];

        $old_product = $old_product_id > 0 ? wc_get_product($old_product_id) : null;
        if (!$old_product instanceof WC_Product) {
            return new \WP_Error('old2new_invalid_old_product', __('Old product is required.', 'hp-products-manager'), ['status' => 400]);
        }

        $new_products = [];
        foreach ($new_product_ids as $new_product_id) {
            $new_product = wc_get_product($new_product_id);
            if ($new_product instanceof WC_Product) {
                $new_products[] = $new_product;
            }
        }

        if (empty($new_products)) {
            return new \WP_Error('old2new_invalid_new_products', __('At least one new product is required.', 'hp-products-manager'), ['status' => 400]);
        }

        $old_sku = $this->normalize_old2new_sku((string) $old_product->get_sku());
        if ($old_sku === '') {
            return new \WP_Error('old2new_missing_old_sku', __('Old product must have an SKU.', 'hp-products-manager'), ['status' => 400]);
        }

        $duplicate_id = $this->find_old2new_packet_by_old_sku($old_sku, $packet_id);
        if ($duplicate_id > 0) {
            return new \WP_Error('old2new_duplicate_old_sku', __('An Old2New packet already exists for this old SKU.', 'hp-products-manager'), ['status' => 409]);
        }

        $new_skus = [];
        foreach ($new_products as $new_product) {
            $new_sku = $this->normalize_old2new_sku((string) $new_product->get_sku());
            if ($new_sku !== '') {
                $new_skus[] = $new_sku;
            }
        }

        $status = $this->sanitize_old2new_status($payload['status'] ?? 'basic_discontinue');
        $started_at = sanitize_text_field((string) ($payload['hard_redirect_started_at'] ?? ''));
        if ($status === 'hard_redirect' && $started_at === '') {
            $started_at = gmdate('Y-m-d');
        }

        // Admin-selected target must be one of the packet's new products;
        // anything else falls back to auto (0).
        $target_product_id = absint($payload['target_product_id'] ?? 0);
        if ($target_product_id > 0 && !in_array($target_product_id, $new_product_ids, true)) {
            $target_product_id = 0;
        }

        $banner_window_days = $this->old2new_sanitize_banner_window($payload['banner_window_days'] ?? self::OLD2NEW_BANNER_WINDOW_DAYS);
        $post_title = sprintf('%s -> %s', $old_sku, implode(', ', $new_skus));
        $post_data = [
            'post_type' => self::OLD2NEW_PACKET_CPT,
            'post_status' => 'publish',
            'post_title' => $post_title,
        ];

        if ($packet_id > 0) {
            $post_data['ID'] = $packet_id;
            $saved_id = wp_update_post(wp_slash($post_data), true);
        } else {
            $saved_id = wp_insert_post(wp_slash($post_data), true);
        }

        if (is_wp_error($saved_id)) {
            return $saved_id;
        }

        $saved_id = (int) $saved_id;
        update_post_meta($saved_id, '_hp_old2new_old_product_id', $old_product_id);
        update_post_meta($saved_id, '_hp_old2new_old_sku', $old_sku);
        update_post_meta($saved_id, '_hp_old2new_new_product_ids', array_map('intval', $new_product_ids));
        update_post_meta($saved_id, '_hp_old2new_new_skus', $new_skus);
        update_post_meta($saved_id, '_hp_old2new_status', $status);
        update_post_meta($saved_id, '_hp_old2new_hard_redirect_started_at', $started_at);
        update_post_meta($saved_id, '_hp_old2new_custom_old_message', sanitize_textarea_field((string) ($payload['custom_old_message'] ?? '')));
        update_post_meta($saved_id, '_hp_old2new_custom_new_message', sanitize_textarea_field((string) ($payload['custom_new_message'] ?? '')));
        update_post_meta($saved_id, '_hp_old2new_badge_text', sanitize_text_field((string) ($payload['badge_text'] ?? '')));
        update_post_meta($saved_id, '_hp_old2new_target_product_id', $target_product_id);
        update_post_meta($saved_id, '_hp_old2new_banner_window_days', $banner_window_days);

        $this->old2new_old_sku_index = null;

        return $this->old2new_packet_response($saved_id);
    }

    /**
     * Register the Products Manager admin page.
     */
    public function register_admin_page(): void {
        add_submenu_page(
            'woocommerce',
            __('Products Manager', 'hp-products-manager'),
            __('Products Manager', 'hp-products-manager'),
            'manage_woocommerce',
            'hp-products-manager',
            [$this, 'render_products_page'],
            30
        );
    }

    /**
     * Register the single product detail page (hidden entry-only page).
     */
    public function register_product_detail_page(): void {
        add_submenu_page(
            'woocommerce',
            __('PM Product', 'hp-products-manager'),
            __('PM Product', 'hp-products-manager'),
            'manage_woocommerce',
            'hp-products-manager-product',
            [$this, 'render_product_detail_page'],
            31
        );
    }

    /**
     * Render the Products Manager interface.
     */
    public function render_products_page(): void {
        ?>
        <div class="wrap hp-products-manager-page">
            <header class="hp-pm-header">
                <div>
                    <h1><?php esc_html_e('Products Manager', 'hp-products-manager'); ?></h1>
                    <p class="hp-pm-version">
                        <?php
                        printf(
                            esc_html__('Version %s', 'hp-products-manager'),
                            esc_html(self::VERSION)
                        );
                        ?>
                    </p>
                </div>
                <div class="hp-pm-header-actions" style="display:none;">
                    <button class="button button-primary"><?php esc_html_e('Add Product', 'hp-products-manager'); ?></button>
                    <button class="button"><?php esc_html_e('Bulk Update', 'hp-products-manager'); ?></button>
                    <button class="button"><?php esc_html_e('Export CSV', 'hp-products-manager'); ?></button>
                </div>
            </header>

            <nav class="nav-tab-wrapper hp-pm-tabs" aria-label="<?php esc_attr_e('Products Manager sections', 'hp-products-manager'); ?>">
                <button type="button" class="nav-tab nav-tab-active" data-hp-pm-tab="products"><?php esc_html_e('Products', 'hp-products-manager'); ?></button>
                <button type="button" class="nav-tab" data-hp-pm-tab="old2new"><?php esc_html_e('Old2New', 'hp-products-manager'); ?></button>
            </nav>

            <div data-hp-pm-panel="products">
            <form id="hp-products-filters" class="hp-pm-filters">
                <div class="hp-pm-filter-group">
                    <label for="hp-pm-filter-search">
                        <?php esc_html_e('Search', 'hp-products-manager'); ?>
                        <input id="hp-pm-filter-search" type="search" placeholder="<?php esc_attr_e('Name or SKU?', 'hp-products-manager'); ?>">
                    </label>
                </div>
                <div class="hp-pm-filter-group">
                    <label for="hp-pm-filter-brand">
                        <?php esc_html_e('Brand', 'hp-products-manager'); ?>
                        <select id="hp-pm-filter-brand">
                            <option value=""><?php esc_html_e('All brands', 'hp-products-manager'); ?></option>
                        </select>
                    </label>
                </div>
                <div class="hp-pm-filter-group">
                    <label for="hp-pm-filter-status">
                        <?php esc_html_e('Status', 'hp-products-manager'); ?>
                        <select id="hp-pm-filter-status">
                            <option value=""><?php esc_html_e('Any status', 'hp-products-manager'); ?></option>
                            <option value="enabled"><?php esc_html_e('Enabled', 'hp-products-manager'); ?></option>
                            <option value="disabled"><?php esc_html_e('Disabled', 'hp-products-manager'); ?></option>
                        </select>
                    </label>
                </div>
                <div class="hp-pm-filter-group">
                    <label for="hp-pm-filter-visibility">
                        <?php esc_html_e('Visibility', 'hp-products-manager'); ?>
                        <select id="hp-pm-filter-visibility">
                            <option value=""><?php esc_html_e('Any visibility', 'hp-products-manager'); ?></option>
                            <option value="visible"><?php esc_html_e('Catalog & Search', 'hp-products-manager'); ?></option>
                            <option value="catalog"><?php esc_html_e('Catalog Only', 'hp-products-manager'); ?></option>
                            <option value="search"><?php esc_html_e('Search Only', 'hp-products-manager'); ?></option>
                            <option value="hidden"><?php esc_html_e('Hidden', 'hp-products-manager'); ?></option>
                        </select>
                    </label>
                </div>
                <div class="hp-pm-filter-group">
                    <label>
                        <?php esc_html_e('Stock Filters', 'hp-products-manager'); ?>
                        <div class="hp-pm-stock-range">
                            <label style="display:flex; align-items:center; gap:6px;">
                                <input id="hp-pm-filter-qoh-gt0" type="checkbox"> <?php esc_html_e('QOH > 0', 'hp-products-manager'); ?>
                            </label>
                            <span class="hp-pm-sep">|</span>
                            <label style="display:flex; align-items:center; gap:6px;">
                                <input id="hp-pm-filter-reserved-gt0" type="checkbox"> <?php esc_html_e('Reserved > 0', 'hp-products-manager'); ?>
                            </label>
                            <span class="hp-pm-sep">|</span>
                            <label style="display:flex; align-items:center; gap:6px;">
                                <input id="hp-pm-filter-available-lt0" type="checkbox"> <?php esc_html_e('Available < 0', 'hp-products-manager'); ?>
                            </label>
                        </div>
                    </label>
                </div>
                <div class="hp-pm-filter-actions">
                    <button type="button" class="button" id="hp-pm-filters-reset"><?php esc_html_e('Reset Filters', 'hp-products-manager'); ?></button>
                </div>
            </form>

            <section class="hp-pm-metrics">
                <div class="hp-pm-metric">
                    <span class="hp-pm-metric-label"><?php esc_html_e('Total Products', 'hp-products-manager'); ?></span>
                    <span class="hp-pm-metric-value" id="hp-pm-metric-total">--</span>
                </div>
                <div class="hp-pm-metric">
                    <span class="hp-pm-metric-label"><?php esc_html_e('Enabled', 'hp-products-manager'); ?></span>
                    <span class="hp-pm-metric-value hp-pm-metric-value--success" id="hp-pm-metric-enabled">--</span>
                </div>
                <div class="hp-pm-metric">
                    <span class="hp-pm-metric-label"><?php esc_html_e('Stock Cost', 'hp-products-manager'); ?></span>
                    <span class="hp-pm-metric-value" id="hp-pm-metric-stock-cost">--</span>
                </div>
                <div class="hp-pm-metric">
                    <span class="hp-pm-metric-label"><?php esc_html_e('Reserved (Excluding on-hold orders)', 'hp-products-manager'); ?></span>
                    <span class="hp-pm-metric-value hp-pm-metric-value--muted" id="hp-pm-metric-reserved">--</span>
                </div>
            </section>

            <section class="hp-pm-table">
                <div id="hp-pm-table-count" class="hp-pm-table-count">&nbsp;</div>
                <div id="hp-products-table"></div>
            </section>
            </div>

            <section class="hp-old2new-admin" data-hp-pm-panel="old2new" hidden>
                <div class="hp-old2new-toolbar">
                    <div>
                        <h2><?php esc_html_e('Old2New Packets', 'hp-products-manager'); ?></h2>
                        <p><?php esc_html_e('Manage old product replacement packets, lifecycle status, and redirect readiness.', 'hp-products-manager'); ?></p>
                    </div>
                    <button type="button" class="button button-primary" id="hp-old2new-add"><?php esc_html_e('Add Old2New Packet', 'hp-products-manager'); ?></button>
                </div>
                <div class="hp-old2new-guidelines" aria-label="<?php esc_attr_e('Old2New guidelines', 'hp-products-manager'); ?>">
                    <p><strong><?php esc_html_e('Statuses:', 'hp-products-manager'); ?></strong> <?php esc_html_e('Basic Discontinue shows banners and badges; Canonical adds SEO canonical; Hard Redirect sends old product URLs to the selected new product.', 'hp-products-manager'); ?></p>
                    <p><strong><?php esc_html_e('Visibility:', 'hp-products-manager'); ?></strong> <?php esc_html_e('Full banners appear on product pages; compact badges appear only on old products in search, category, shop, grid, and list cards.', 'hp-products-manager'); ?></p>
                    <p><strong><?php esc_html_e('Redirects:', 'hp-products-manager'); ?></strong> <?php esc_html_e('Canonical keeps old and new pages accessible; 301 redirect takes the old page down and sends traffic to the selected target.', 'hp-products-manager'); ?></p>
                    <p><strong><?php esc_html_e('Target:', 'hp-products-manager'); ?></strong> <?php esc_html_e('You can pick the canonical/redirect target per packet; on Auto, Product Manager chooses highest Woo total_sales with packet order as the tie-break. The target card is flagged "Recommended" in multi-product banners.', 'hp-products-manager'); ?></p>
                    <p><strong><?php esc_html_e('Banner window:', 'hp-products-manager'); ?></strong> <?php esc_html_e('Hard Redirect keeps the new-product banner for the packet\'s banner window (default 180 days) after the start date, then hides the banner while the 301 remains active.', 'hp-products-manager'); ?></p>
                    <p><strong><?php esc_html_e('Custom text:', 'hp-products-manager'); ?></strong> <?php esc_html_e('Packet messages can use {old_product}, {new_product}, {new_products}, and {new_product_count}; output is escaped as plain text.', 'hp-products-manager'); ?></p>
                </div>
                <div id="hp-old2new-status" class="hp-old2new-status" role="status" aria-live="polite"></div>
                <div id="hp-old2new-table" class="hp-old2new-table"></div>

                <form id="hp-old2new-form" class="hp-old2new-form" hidden>
                    <input type="hidden" id="hp-old2new-packet-id" value="">
                    <h3 id="hp-old2new-form-title"><?php esc_html_e('Old2New Packet', 'hp-products-manager'); ?></h3>
                    <label for="hp-old2new-old-product">
                        <?php esc_html_e('Old Product', 'hp-products-manager'); ?>
                        <input id="hp-old2new-old-product" type="search" list="hp-old2new-products-list" placeholder="<?php esc_attr_e('Search old product by name or SKU', 'hp-products-manager'); ?>">
                    </label>
                    <label for="hp-old2new-new-products">
                        <?php esc_html_e('New Products', 'hp-products-manager'); ?>
                        <input id="hp-old2new-new-products" type="search" list="hp-old2new-products-list" placeholder="<?php esc_attr_e('Search replacement product by name or SKU', 'hp-products-manager'); ?>">
                    </label>
                    <div id="hp-old2new-selected-new-products" class="hp-old2new-selected-products"></div>
                    <label for="hp-old2new-status-select">
                        <?php esc_html_e('Status', 'hp-products-manager'); ?>
                        <select id="hp-old2new-status-select">
                            <option value="basic_discontinue"><?php esc_html_e('Basic Discontinue', 'hp-products-manager'); ?></option>
                            <option value="canonical"><?php esc_html_e('Canonical', 'hp-products-manager'); ?></option>
                            <option value="hard_redirect"><?php esc_html_e('Hard Redirect', 'hp-products-manager'); ?></option>
                        </select>
                    </label>
                    <label for="hp-old2new-target-select">
                        <?php esc_html_e('Canonical / redirect target', 'hp-products-manager'); ?>
                        <select id="hp-old2new-target-select">
                            <option value="0"><?php esc_html_e('Auto — highest total sales', 'hp-products-manager'); ?></option>
                        </select>
                    </label>
                    <label for="hp-old2new-hard-redirect-started-at">
                        <?php esc_html_e('Hard redirect started at', 'hp-products-manager'); ?>
                        <input id="hp-old2new-hard-redirect-started-at" type="date">
                    </label>
                    <label for="hp-old2new-banner-window">
                        <?php esc_html_e('Banner window (days after hard redirect start)', 'hp-products-manager'); ?>
                        <input id="hp-old2new-banner-window" type="number" min="1" max="3650" step="1" placeholder="180">
                    </label>
                    <p class="hp-old2new-derived"><?php esc_html_e('Redirect type:', 'hp-products-manager'); ?> <strong id="hp-old2new-redirect-type">none</strong></p>
                    <label for="hp-old2new-custom-old-message">
                        <?php esc_html_e('Old-product banner message override', 'hp-products-manager'); ?>
                        <textarea id="hp-old2new-custom-old-message" rows="3" placeholder="<?php esc_attr_e('Use default message', 'hp-products-manager'); ?>"></textarea>
                    </label>
                    <label for="hp-old2new-custom-new-message">
                        <?php esc_html_e('New-product banner message override', 'hp-products-manager'); ?>
                        <textarea id="hp-old2new-custom-new-message" rows="3" placeholder="<?php esc_attr_e('Use default message', 'hp-products-manager'); ?>"></textarea>
                    </label>
                    <label for="hp-old2new-badge-text">
                        <?php esc_html_e('Compact badge text', 'hp-products-manager'); ?>
                        <input id="hp-old2new-badge-text" type="text" placeholder="<?php esc_attr_e('See new product', 'hp-products-manager'); ?>">
                    </label>
                    <div class="hp-old2new-preview" id="hp-old2new-message-preview" aria-live="polite"></div>
                    <datalist id="hp-old2new-products-list"></datalist>
                    <div class="hp-old2new-actions">
                        <button type="submit" class="button button-primary" id="hp-old2new-save"><?php esc_html_e('Save Packet', 'hp-products-manager'); ?></button>
                        <button type="button" class="button" id="hp-old2new-cancel"><?php esc_html_e('Cancel', 'hp-products-manager'); ?></button>
                    </div>
                </form>
            </section>
        </div>
        <?php
    }

    /**
     * Get ACF field choices dynamically
     */
    private function get_acf_choices($field_name) {
        if (!function_exists('acf_get_field')) return null;
        $field = acf_get_field($field_name);
        return (isset($field['choices']) && is_array($field['choices'])) ? $field['choices'] : null;
    }

    /**
     * Helper to render an ACF field based on its type
     */
    private function render_acf_field($field_name, $label, $type = 'text', $choices = null, $multiple = false, $current_value = null) {
        if ($choices === null) {
            $choices = $this->get_acf_choices($field_name);
        }
        
        $id = 'hp-pm-pd-' . $field_name;
        $html = '<tr><th>' . esc_html($label) . '</th><td>';
        
        if ($type === 'textarea') {
            $class = in_array($field_name, ['description_long', 'video_transcription', 'ingredients', 'how_to_use', 'cautions', 'recommended_use', 'community_tips', 'traditional_function', 'expert_article']) ? 'large-text hp-pm-full-width auto-expand' : 'large-text';
            $html .= '<textarea id="' . esc_attr($id) . '" rows="3" class="' . esc_attr($class) . '"></textarea>';
        } elseif ($type === 'select' || $choices !== null) {
            $html .= '<select id="' . esc_attr($id) . '"' . ($multiple ? ' multiple style="height:120px;"' : '') . ' class="regular-text">';
            if (!$multiple) $html .= '<option value="">' . esc_html__('— Select —', 'hp-products-manager') . '</option>';
            
            $final_choices = is_array($choices) ? $choices : [];
            if ($current_value) {
                $vals = is_array($current_value) ? $current_value : explode(',', (string)$current_value);
                foreach ($vals as $v) {
                    $v = trim((string)$v);
                    if ($v === '') continue;
                    $found = false;
                    foreach ($final_choices as $ckey => $cval) {
                        if ((string)$ckey === $v || (string)$cval === $v) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $final_choices[$v] = $v;
                    }
                }
            }

            foreach ($final_choices as $val => $text) {
                $html .= '<option value="' . esc_attr($val) . '">' . esc_html($text) . '</option>';
            }
            $html .= '</select>';
            if ($multiple) $html .= '<p class="description">' . esc_html__('Hold Ctrl/Cmd to select multiple', 'hp-products-manager') . '</p>';
        } elseif ($type === 'number') {
            $html .= '<input id="' . esc_attr($id) . '" type="number" step="any" class="regular-text">';
        } else {
            $html .= '<input id="' . esc_attr($id) . '" type="text" class="regular-text">';
        }
        
        $html .= '</td></tr>';
        return $html;
    }

    /**
     * Render the product detail mockup page with tabs and staging placeholder.
     */
    public function render_product_detail_page(): void {
        $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
        $product = $product_id ? wc_get_product($product_id) : null;

        if (!$product instanceof WC_Product) {
            echo '<div class="wrap"><h1>' . esc_html__('PM Product', 'hp-products-manager') . '</h1><p>' . esc_html__('Invalid or missing product.', 'hp-products-manager') . '</p></div>';
            return;
        }

        $title = sprintf(__('Product: %s', 'hp-products-manager'), $product->get_name());

        // Enqueue product detail assets and bootstrap data for JS
        $asset_base = plugin_dir_url(__FILE__) . 'assets/';
        // Chart.js for sales graph
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            '4.4.1',
            true
        );
        // Time scale adapter for date axes
        wp_enqueue_script(
            'chartjs-adapter-date-fns',
            'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns',
            ['chartjs'],
            '3.0.0',
            true
        );
        wp_enqueue_style(
            self::HANDLE . '-product-css',
            $asset_base . 'css/product-detail.css',
            [],
            self::VERSION
        );
        // Media for image selection
        if (function_exists('wp_enqueue_media')) { wp_enqueue_media(); }
        wp_enqueue_script(
            self::HANDLE . '-product-js',
            $asset_base . 'js/product-detail.js',
            ['chartjs'],
            self::VERSION,
            true
        );
        $brands = $this->get_brand_options();
        wp_localize_script(
            self::HANDLE . '-product-js',
            'HPProductDetailData',
            [
                'restBase' => rest_url(self::REST_NAMESPACE . '/product/' . $product_id),
                'nonce'    => wp_create_nonce('wp_rest'),
                'product'  => [
                    'id'         => $product->get_id(),
                    'name'       => $product->get_name(),
                    'sku'        => $product->get_sku(),
                    'price'      => ($product->get_price('edit') !== '' ? (float) $product->get_price('edit') : null),
                    'sale_price' => ($product->get_sale_price('edit') !== '' ? (float) $product->get_sale_price('edit') : null),
                    'status'     => $product->get_status(),
                    'visibility' => $product->get_catalog_visibility(),
                    'short_description' => $product->get_short_description('edit'),
                    'tax_status' => $product->get_tax_status('edit'),
                    'tax_class'  => $product->get_tax_class('edit'),
                    'sold_individually' => $product->get_sold_individually('edit'),
                    'upsell_ids' => $product->get_upsell_ids('edit'),
                    'crosssell_ids' => $product->get_cross_sell_ids('edit'),
                    'upsell_labels' => (object) $this->get_product_labels($product->get_upsell_ids('edit')),
                    'crosssell_labels' => (object) $this->get_product_labels($product->get_cross_sell_ids('edit')),
                    'yoast_focuskw' => get_post_meta($product_id, '_yoast_wpseo_focuskw', true),
                    'yoast_title' => get_post_meta($product_id, '_yoast_wpseo_title', true),
                    'yoast_metadesc' => get_post_meta($product_id, '_yoast_wpseo_metadesc', true),
                    'brands'     => $this->extract_term_slugs($this->get_product_brand_terms($product_id)),
                    'categories' => $this->extract_term_slugs(wc_get_product_terms($product_id, 'product_cat', ['fields' => 'all'])),
                    'tags'       => $this->extract_term_slugs(wc_get_product_terms($product_id, 'product_tag', ['fields' => 'all'])),
                    'shipping_class' => (function() use ($product_id) {
                        $terms = wc_get_product_terms($product_id, 'product_shipping_class', ['fields' => 'all']);
                        if (is_wp_error($terms) || empty($terms) || !is_object($terms[0])) {
                            return '';
                        }
                        return $terms[0]->slug;
                    })(),
                    'weight'     => $product->get_weight('edit'),
                    'length'     => method_exists($product, 'get_length') ? $product->get_length('edit') : '',
                    'width'      => method_exists($product, 'get_width') ? $product->get_width('edit') : '',
                    'height'     => method_exists($product, 'get_height') ? $product->get_height('edit') : '',
                    'cost'       => $this->get_strict_cost($product_id),
                    'manage_stock' => $product->get_manage_stock(),
                    'stock_quantity' => $product->get_stock_quantity(),
                    'backorders' => $product->get_backorders(),
                    // Dosing & Servings
                    'serving_size'           => get_post_meta($product_id, 'serving_size', true),
                    'servings_per_container' => get_post_meta($product_id, 'servings_per_container', true),
                    'serving_form_unit'      => get_post_meta($product_id, 'serving_form_unit', true),
                    'supplement_form'        => get_post_meta($product_id, 'supplement_form', true),
                    'bottle_size_eu'         => get_post_meta($product_id, 'bottle_size_eu', true),
                    'bottle_size_units_eu'   => get_post_meta($product_id, 'bottle_size_units_eu', true),
                    'bottle_size_usa'        => get_post_meta($product_id, 'bottle_size_usa', true),
                    'bottle_size_units_usa'  => get_post_meta($product_id, 'bottle_size_units_usa', true),
                    // Ingredients & Mfg
                    'ingredients'             => get_post_meta($product_id, 'ingredients', true),
                    'ingredients_other'       => get_post_meta($product_id, 'ingredients_other', true),
                    'potency'                 => get_post_meta($product_id, 'potency', true),
                    'potency_units'           => get_post_meta($product_id, 'potency_units', true),
                    'sku_mfr'                 => get_post_meta($product_id, 'sku_mfr', true),
                    'manufacturer_acf'        => get_post_meta($product_id, 'manufacturer_acf', true),
                    'country_of_manufacturer' => get_post_meta($product_id, 'country_of_manufacturer', true),
                    // Instructions & Safety
                    'how_to_use'      => get_post_meta($product_id, 'how_to_use', true),
                    'cautions'        => get_post_meta($product_id, 'cautions', true),
                    'recommended_use' => get_post_meta($product_id, 'recommended_use', true),
                    'community_tips'  => get_post_meta($product_id, 'community_tips', true),
                    // Expert Info
                    'body_systems_organs' => (array) get_post_meta($product_id, 'body_systems_organs', true),
                    'traditional_function'=> get_post_meta($product_id, 'traditional_function', true),
                    'chinese_energy'      => get_post_meta($product_id, 'chinese_energy', true),
                    'ayurvedic_energy'    => get_post_meta($product_id, 'ayurvedic_energy', true),
                    'supplement_type'     => get_post_meta($product_id, 'supplement_type', true),
                    'expert_article'      => get_post_meta($product_id, 'expert_article', true),
                    'video'               => get_post_meta($product_id, 'video', true),
                    'video_transcription' => get_post_meta($product_id, 'video_transcription', true),
                    'slogan'              => get_post_meta($product_id, 'slogan', true),
                    'aka_product_name'    => get_post_meta($product_id, 'aka_product_name', true),
                    'description_long'    => get_post_meta($product_id, 'description_long', true),
                    // Admin
                    'product_type_hp' => get_post_meta($product_id, 'product_type_hp', true),
                    'site_catalog'    => (array) get_post_meta($product_id, 'site_catalog', true),
                    'image'      => $this->product_image_url($product),
                    'image_id'   => $product->get_image_id() ?: null,
                    'gallery_ids'=> method_exists($product, 'get_gallery_image_ids') ? $product->get_gallery_image_ids() : [],
                    'gallery'    => (function() use ($product){ $out=[]; if (method_exists($product, 'get_gallery_image_ids')) { foreach ($product->get_gallery_image_ids() as $gid) { $out[] = ['id'=>$gid,'url'=> wp_get_attachment_image_url($gid,'thumbnail')]; } } return $out; })(),
                    'editLink'   => admin_url('post.php?post=' . $product_id . '&action=edit'),
                    'viewLink'   => get_permalink($product_id),
                ],
                'erp' => (function() use ($product_id, $product) {
                    $qoh = (int) ($product->get_stock_quantity() !== null ? $product->get_stock_quantity() : 0);
                    $map = $this->get_reserved_quantities([$product_id]);
                    $reserved = isset($map[$product_id]) ? (int) $map[$product_id] : 0;
                    return ['qoh' => $qoh, 'reserved' => $reserved, 'available' => ($qoh - $reserved)];
                })(),
                'brands'   => $brands,
                'categories' => (function(){
                    $out = [];
                    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                    if (!is_wp_error($terms)) {
                        foreach ($terms as $t) { $out[] = ['slug' => $t->slug, 'name' => $t->name]; }
                    }
                    return $out;
                })(),
                'tags' => (function(){
                    $out = [];
                    $terms = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
                    if (!is_wp_error($terms)) {
                        foreach ($terms as $t) { $out[] = ['slug' => $t->slug, 'name' => $t->name]; }
                    }
                    return $out;
                })(),
                'shippingClasses' => (function(){
                    $out = [['slug' => '', 'name' => __('No shipping class', 'hp-products-manager')]];
                    $terms = get_terms(['taxonomy' => 'product_shipping_class', 'hide_empty' => false]);
                    if (!is_wp_error($terms)) {
                        foreach ($terms as $t) { $out[] = ['slug' => $t->slug, 'name' => $t->name]; }
                    }
                    return $out;
                })(),
                'allProducts' => (function(){
                    global $wpdb;
                    $results = $wpdb->get_results("SELECT ID as id, post_title as name FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' LIMIT 2000");
                    $out = [];
                    foreach ($results as $r) {
                        $sku = get_post_meta($r->id, '_sku', true);
                        $out[] = ['id' => (int)$r->id, 'name' => $r->name, 'sku' => $sku];
                    }
                    return $out;
                })(),
                'taxClasses' => (function(){
                    $classes = WC_Tax::get_tax_classes();
                    $out = [['slug' => '', 'name' => __('Standard', 'hp-products-manager')]];
                    foreach ($classes as $class) {
                        $out[] = ['slug' => sanitize_title($class), 'name' => $class];
                    }
                    return $out;
                })(),
                'i18n'     => [
                    'stagedChanges' => __('Staged Changes', 'hp-products-manager'),
                    'stageBtn'      => __('Stage Changes', 'hp-products-manager'),
                    'applyAll'      => __('Apply All', 'hp-products-manager'),
                    'discardAll'    => __('Discard All', 'hp-products-manager'),
                    'applied'       => __('Changes applied.', 'hp-products-manager'),
                    'nothingToApply'=> __('No changes to apply.', 'hp-products-manager'),
                ],
            ]
        );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html($title); ?></h1>
            <p class="hp-pm-version"><?php printf(esc_html__('Version %s', 'hp-products-manager'), esc_html(self::VERSION)); ?></p>

            <div class="hp-pm-pd-container">
                <div class="card hp-pm-header-card" style="max-width: 1200px; margin-bottom: 20px;">
                    <div class="hp-pm-pd-header">
                        <div class="hp-pm-pd-image">
                            <img id="hp-pm-pd-image" src="" alt="" class="hp-pm-main-img">
                        </div>
                        <div id="hp-pm-pd-gallery" class="hp-pm-gallery"></div>
                        <div class="hp-pm-pd-links">
                            <div class="hp-pm-pd-links-top" style="display:flex; gap:8px; justify-content: flex-end; margin-bottom: 12px;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=hp-products-manager')); ?>" class="button hp-pm-back-btn"><?php esc_html_e('Back to products list', 'hp-products-manager'); ?></a>
                                <button id="hp-pm-duplicate-btn" class="button"><?php esc_html_e('Duplicate', 'hp-products-manager'); ?></button>
                                <a id="hp-pm-pd-edit" href="#" target="_blank" class="button"><?php esc_html_e('Open in WP Admin', 'hp-products-manager'); ?></a>
                                <a id="hp-pm-pd-view" href="#" target="_blank" class="button"><?php esc_html_e('View Product', 'hp-products-manager'); ?></a>
                            </div>
                            <div class="hp-pm-staging-actions" style="display:flex; gap:8px; justify-content: flex-end;">
                                <button id="hp-pm-stage-btn" class="button button-primary"><?php esc_html_e('Stage Changes', 'hp-products-manager'); ?></button>
                                <button id="hp-pm-apply-btn" class="button" disabled><?php esc_html_e('Apply All', 'hp-products-manager'); ?></button>
                                <button id="hp-pm-discard-btn" class="button" disabled><?php esc_html_e('Discard All', 'hp-products-manager'); ?></button>
                            </div>
                        </div>
                    </div>
                    <details id="hp-pm-staged-section" class="hp-pm-staged" style="display:none; margin-top: 15px; border-top: 1px solid #dcdcde; padding-top: 15px;" open>
                        <summary style="cursor: pointer; font-weight: 600; font-size: 1.1em; outline: none; margin-bottom: 10px;">
                            <span id="hp-pm-staged-title"><?php esc_html_e('Staged Changes', 'hp-products-manager'); ?></span>
                        </summary>
                        <table id="hp-pm-staged-table" class="widefat striped">
                            <thead><tr><th>Field</th><th>From</th><th>To</th><th>Action</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </details>
                </div>

                <h2 class="nav-tab-wrapper hp-pm-nav-tabs">
                    <?php
                        $tabs = [
                            'core'         => __('Core & Inventory', 'hp-products-manager'),
                            'pricing'      => __('Pricing & Shipping', 'hp-products-manager'),
                            'dosing'       => __('Dosing & Servings', 'hp-products-manager'),
                            'ingredients'  => __('Ingredients & Mfg', 'hp-products-manager'),
                            'instructions' => __('Instructions & Safety', 'hp-products-manager'),
                            'expert'       => __('Expert Info', 'hp-products-manager'),
                            'linked'       => __('Linked Products', 'hp-products-manager'),
                            'seo'          => __('SEO', 'hp-products-manager'),
                            'admin'        => __('Admin', 'hp-products-manager'),
                            'erp'          => __('Sales & ERP', 'hp-products-manager'),
                        ];
                        // Default to 'core' unless 'tab' is explicitly in URL (for legacy support or direct links)
                        $active_tab_id = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'core';
                        foreach ($tabs as $tab_id => $tab_label) {
                            echo '<a href="#tab-' . esc_attr($tab_id) . '" class="nav-tab ' . ($active_tab_id === $tab_id ? 'nav-tab-active' : '') . '" data-tab="' . esc_attr($tab_id) . '">' . esc_html($tab_label) . '</a>';
                        }
                    ?>
                </h2>

                <div class="card hp-pm-card hp-pm-tabs-card" style="max-width: 1200px; border-top: none;">
                    <div class="hp-pm-tab-content">
                        <!-- Tab: Core & Inventory -->
                        <div id="tab-core" class="hp-pm-tab-pane <?php echo $active_tab_id === 'core' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section>
                                    <h2><?php esc_html_e('Basics', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                    <tr>
                                        <th><?php esc_html_e('Name', 'hp-products-manager'); ?></th>
                                        <td><input id="hp-pm-pd-name" type="text" class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e('SKU', 'hp-products-manager'); ?></th>
                                        <td><input id="hp-pm-pd-sku" type="text" class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e('Status', 'hp-products-manager'); ?></th>
                                        <td>
                                            <select id="hp-pm-pd-status">
                                                <option value="publish">publish</option>
                                                <option value="draft">draft</option>
                                                <option value="private">private</option>
                                                <option value="pending">pending</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e('Short Description', 'hp-products-manager'); ?></th>
                                        <td><textarea id="hp-pm-pd-short-description" class="large-text hp-pm-full-width auto-expand" rows="3"></textarea></td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e('Visibility', 'hp-products-manager'); ?></th>
                                        <td>
                                            <select id="hp-pm-pd-visibility">
                                                <option value="visible"><?php esc_html_e('Catalog & Search', 'hp-products-manager'); ?></option>
                                                <option value="catalog"><?php esc_html_e('Catalog Only', 'hp-products-manager'); ?></option>
                                                <option value="search"><?php esc_html_e('Search Only', 'hp-products-manager'); ?></option>
                                                <option value="hidden"><?php esc_html_e('Hidden', 'hp-products-manager'); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    </table>
                                </section>
                                <section>
                                    <h2><?php esc_html_e('Taxonomies', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                    <tr>
                                        <th><?php esc_html_e('Brand(s)', 'hp-products-manager'); ?></th>
                                        <td>
                                            <div id="hp-pm-pd-brands-tokens" class="hp-pm-tokens"></div>
                                            <input id="hp-pm-pd-brands-input" list="hp-pm-brands-list" placeholder="<?php esc_attr_e('Search brand…', 'hp-products-manager'); ?>">
                                            <datalist id="hp-pm-brands-list"></datalist>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e('Categories', 'hp-products-manager'); ?></th>
                                        <td>
                                            <div id="hp-pm-pd-categories-tokens" class="hp-pm-tokens"></div>
                                            <input id="hp-pm-pd-categories-input" list="hp-pm-cats-list" placeholder="<?php esc_attr_e('Search category…', 'hp-products-manager'); ?>">
                                            <datalist id="hp-pm-cats-list"></datalist>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e('Tags', 'hp-products-manager'); ?></th>
                                        <td>
                                            <div id="hp-pm-pd-tags-tokens" class="hp-pm-tokens"></div>
                                            <input id="hp-pm-pd-tags-input" list="hp-pm-tags-list" placeholder="<?php esc_attr_e('Search tag…', 'hp-products-manager'); ?>">
                                            <datalist id="hp-pm-tags-list"></datalist>
                                        </td>
                                    </tr>
                                    </table>
                                </section>
                            </div>
                            <div class="hp-pm-grid" style="margin-top:20px;">
                                <section>
                                    <h2><?php esc_html_e('Inventory', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <tr>
                                            <th><?php esc_html_e('Track stock?', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-manage-stock" type="checkbox"></td>
                                        </tr>
                                        <tr class="hp-pm-stock-row">
                                            <th><?php esc_html_e('Quantity', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-stock-qty" type="number" step="1" class="regular-text"></td>
                                        </tr>
                                        <tr class="hp-pm-stock-row">
                                            <th><?php esc_html_e('Allow backorders?', 'hp-products-manager'); ?></th>
                                            <td>
                                                <label><input type="radio" name="backorders" value="no"> <?php esc_html_e('Do not allow', 'hp-products-manager'); ?></label><br>
                                                <label><input type="radio" name="backorders" value="notify"> <?php esc_html_e('Allow, but notify customer', 'hp-products-manager'); ?></label><br>
                                                <label><input type="radio" name="backorders" value="yes"> <?php esc_html_e('Allow', 'hp-products-manager'); ?></label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('Sold individually?', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-sold-individually" type="checkbox"></td>
                                        </tr>
                                    </table>
                                </section>
                            </div>
                        </div>

                        <!-- Tab: Pricing & Shipping -->
                        <div id="tab-pricing" class="hp-pm-tab-pane <?php echo $active_tab_id === 'pricing' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section>
                                    <h2><?php esc_html_e('Pricing & Cost', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <tr>
                                            <th><?php esc_html_e('Price', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-price" type="number" step="0.01" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('Sale Price', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-sale-price" type="number" step="0.01" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('Cost', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-cost" type="number" step="0.01" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('Tax Status', 'hp-products-manager'); ?></th>
                                            <td>
                                                <select id="hp-pm-pd-tax-status">
                                                    <option value="taxable"><?php esc_html_e('Taxable', 'hp-products-manager'); ?></option>
                                                    <option value="shipping"><?php esc_html_e('Shipping only', 'hp-products-manager'); ?></option>
                                                    <option value="none"><?php esc_html_e('None', 'hp-products-manager'); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('Tax Class', 'hp-products-manager'); ?></th>
                                            <td>
                                                <select id="hp-pm-pd-tax-class"></select>
                                            </td>
                                        </tr>
                                    </table>
                                </section>
                                <section>
                                    <h2><?php esc_html_e('Shipping', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <tr>
                                            <th><?php esc_html_e('Weight', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-weight" type="number" step="0.01" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('Dimensions (L × W × H)', 'hp-products-manager'); ?></th>
                                            <td>
                                                <input id="hp-pm-pd-length" type="number" step="0.01" style="width:90px;"> ×
                                                <input id="hp-pm-pd-width" type="number" step="0.01" style="width:90px;"> ×
                                                <input id="hp-pm-pd-height" type="number" step="0.01" style="width:90px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('Shipping Class', 'hp-products-manager'); ?></th>
                                            <td>
                                                <select id="hp-pm-pd-ship-class" style="min-width: 280px;"></select>
                                            </td>
                                        </tr>
                                    </table>
                                </section>
                            </div>
                        </div>

                        <!-- Tab: Dosing & Servings -->
                        <div id="tab-dosing" class="hp-pm-tab-pane <?php echo $active_tab_id === 'dosing' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section>
                                    <h2><?php esc_html_e('Dosing Information', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('serving_size', __('Serving Size', 'hp-products-manager'), 'number', null, false, get_post_meta($product_id, 'serving_size', true));
                                            echo $this->render_acf_field('serving_form_unit', __('Serving Form Unit', 'hp-products-manager'), 'select', null, false, get_post_meta($product_id, 'serving_form_unit', true));
                                            echo $this->render_acf_field('servings_per_container', __('Servings Per Container', 'hp-products-manager'), 'number', null, false, get_post_meta($product_id, 'servings_per_container', true));
                                            echo $this->render_acf_field('supplement_form', __('Supplement Form', 'hp-products-manager'), 'select', null, false, get_post_meta($product_id, 'supplement_form', true));
                                        ?>
                                    </table>
                                </section>
                                <section>
                                    <h2><?php esc_html_e('Bottle Size', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('bottle_size_eu', __('Bottle Size (EU)', 'hp-products-manager'), 'number', null, false, get_post_meta($product_id, 'bottle_size_eu', true));
                                            echo $this->render_acf_field('bottle_size_units_eu', __('Units (EU)', 'hp-products-manager'), 'select', null, false, get_post_meta($product_id, 'bottle_size_units_eu', true));
                                            echo $this->render_acf_field('bottle_size_usa', __('Bottle Size (USA)', 'hp-products-manager'), 'number', null, false, get_post_meta($product_id, 'bottle_size_usa', true));
                                            echo $this->render_acf_field('bottle_size_units_usa', __('Units (USA)', 'hp-products-manager'), 'select', null, false, get_post_meta($product_id, 'bottle_size_units_usa', true));
                                        ?>
                                    </table>
                                </section>
                            </div>
                        </div>

                        <!-- Tab: Ingredients & Mfg -->
                        <div id="tab-ingredients" class="hp-pm-tab-pane <?php echo $active_tab_id === 'ingredients' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section>
                                    <h2><?php esc_html_e('Ingredients', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('ingredients', __('Active Ingredients', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'ingredients', true));
                                            echo $this->render_acf_field('ingredients_other', __('Other Ingredients', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'ingredients_other', true));
                                            echo $this->render_acf_field('potency', __('Potency Value', 'hp-products-manager'), 'text', null, false, get_post_meta($product_id, 'potency', true));
                                            echo $this->render_acf_field('potency_units', __('Potency Units', 'hp-products-manager'), 'select', null, false, get_post_meta($product_id, 'potency_units', true));
                                        ?>
                                    </table>
                                </section>
                                <section>
                                    <h2><?php esc_html_e('Manufacturer Details', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('sku_mfr', __('Manufacturer SKU', 'hp-products-manager'), 'text', null, false, get_post_meta($product_id, 'sku_mfr', true));
                                            echo $this->render_acf_field('manufacturer_acf', __('Manufacturer Name', 'hp-products-manager'), 'select', null, false, get_post_meta($product_id, 'manufacturer_acf', true));
                                            echo $this->render_acf_field('country_of_manufacturer', __('Country of Manufacture', 'hp-products-manager'), 'select', null, false, get_post_meta($product_id, 'country_of_manufacturer', true));
                                        ?>
                                    </table>
                                </section>
                            </div>
                        </div>

                        <!-- Tab: Instructions & Safety -->
                        <div id="tab-instructions" class="hp-pm-tab-pane <?php echo $active_tab_id === 'instructions' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section class="full-width">
                                    <h2><?php esc_html_e('Usage Instructions', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('how_to_use', __('How to Use', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'how_to_use', true));
                                            echo $this->render_acf_field('recommended_use', __('Recommended Use', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'recommended_use', true));
                                        ?>
                                    </table>
                                </section>
                                <section class="full-width">
                                    <h2><?php esc_html_e('Safety & Tips', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('cautions', __('Cautions', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'cautions', true));
                                            echo $this->render_acf_field('community_tips', __('Community Tips', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'community_tips', true));
                                        ?>
                                    </table>
                                </section>
                            </div>
                        </div>

                        <!-- Tab: Expert Info -->
                        <div id="tab-expert" class="hp-pm-tab-pane <?php echo $active_tab_id === 'expert' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section>
                                    <h2><?php esc_html_e('Expert Data', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('body_systems_organs', __('Body Systems & Organs', 'hp-products-manager'), 'select', null, true, get_post_meta($product_id, 'body_systems_organs', true));
                                            echo $this->render_acf_field('traditional_function', __('Traditional Function', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'traditional_function', true));
                                            echo $this->render_acf_field('chinese_energy', __('Chinese Energy', 'hp-products-manager'), 'select', null, true, get_post_meta($product_id, 'chinese_energy', true));
                                            echo $this->render_acf_field('ayurvedic_energy', __('Ayurvedic Energy', 'hp-products-manager'), 'select', null, true, get_post_meta($product_id, 'ayurvedic_energy', true));
                                            echo $this->render_acf_field('supplement_type', __('Supplement Type', 'hp-products-manager'), 'select', null, true, get_post_meta($product_id, 'supplement_type', true));
                                        ?>
                                    </table>
                                </section>
                                <section class="full-width">
                                    <h2><?php esc_html_e('Marketing & Content', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('slogan', __('Slogan', 'hp-products-manager'), 'text', null, false, get_post_meta($product_id, 'slogan', true));
                                            echo $this->render_acf_field('aka_product_name', __('Alternative Name', 'hp-products-manager'), 'text', null, false, get_post_meta($product_id, 'aka_product_name', true));
                                            echo $this->render_acf_field('description_long', __('Long Description', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'description_long', true));
                                            echo $this->render_acf_field('expert_article', __('Expert Article URL', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'expert_article', true));
                                            echo $this->render_acf_field('video', __('Video ID/URL', 'hp-products-manager'), 'text', null, false, get_post_meta($product_id, 'video', true));
                                            echo $this->render_acf_field('video_transcription', __('Video Transcription', 'hp-products-manager'), 'textarea', null, false, get_post_meta($product_id, 'video_transcription', true));
                                        ?>
                                    </table>
                                </section>
                            </div>
                        </div>

                        <!-- Tab: Linked Products -->
                        <div id="tab-linked" class="hp-pm-tab-pane <?php echo $active_tab_id === 'linked' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section>
                                    <h2><?php esc_html_e('Upsells', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <tr>
                                            <th><?php esc_html_e('Upsells', 'hp-products-manager'); ?></th>
                                            <td>
                                                <div id="hp-pm-pd-upsells-tokens" class="hp-pm-tokens"></div>
                                                <input id="hp-pm-pd-upsells-input" list="hp-pm-all-products-list" placeholder="<?php esc_attr_e('Search products…', 'hp-products-manager'); ?>">
                                            </td>
                                        </tr>
                                    </table>
                                </section>
                                <section>
                                    <h2><?php esc_html_e('Cross-sells', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <tr>
                                            <th><?php esc_html_e('Cross-sells', 'hp-products-manager'); ?></th>
                                            <td>
                                                <div id="hp-pm-pd-crosssells-tokens" class="hp-pm-tokens"></div>
                                                <input id="hp-pm-pd-crosssells-input" list="hp-pm-all-products-list" placeholder="<?php esc_attr_e('Search products…', 'hp-products-manager'); ?>">
                                            </td>
                                        </tr>
                                    </table>
                                </section>
                                <datalist id="hp-pm-all-products-list"></datalist>
                            </div>
                        </div>

                        <!-- Tab: SEO -->
                        <div id="tab-seo" class="hp-pm-tab-pane <?php echo $active_tab_id === 'seo' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section class="full-width">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <h2><?php esc_html_e('Yoast SEO Settings', 'hp-products-manager'); ?></h2>
                                        <button id="hp-pm-seo-audit-btn" class="button"><?php esc_html_e('Run SEO Audit', 'hp-products-manager'); ?></button>
                                    </div>
                                    <table class="form-table hp-pm-form">
                                        <tr>
                                            <th><?php esc_html_e('Focus Keyword', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-yoast-focuskw" type="text" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('SEO Title', 'hp-products-manager'); ?></th>
                                            <td><input id="hp-pm-pd-yoast-title" type="text" class="large-text hp-pm-full-width"></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e('Meta Description', 'hp-products-manager'); ?></th>
                                            <td><textarea id="hp-pm-pd-yoast-metadesc" class="large-text hp-pm-full-width auto-expand" rows="3"></textarea></td>
                                        </tr>
                                    </table>
                                    <div id="hp-pm-seo-audit-results" style="margin-top:20px; display:none;">
                                        <h3><?php esc_html_e('SEO Audit Results', 'hp-products-manager'); ?></h3>
                                        <div class="hp-pm-seo-audit-list"></div>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <!-- Tab: Admin -->
                        <!-- Tab: Admin -->
                        <div id="tab-admin" class="hp-pm-tab-pane <?php echo $active_tab_id === 'admin' ? 'active' : ''; ?>">
                            <div class="hp-pm-grid">
                                <section>
                                    <h2><?php esc_html_e('Internal Settings', 'hp-products-manager'); ?></h2>
                                    <table class="form-table hp-pm-form">
                                        <?php 
                                            echo $this->render_acf_field('product_type_hp', __('Product Type HP', 'hp-products-manager'), 'select', null, false, get_post_meta($product_id, 'product_type_hp', true));
                                            echo $this->render_acf_field('site_catalog', __('Site Catalog', 'hp-products-manager'), 'select', null, true, get_post_meta($product_id, 'site_catalog', true));
                                        ?>
                                    </table>
                                </section>
                            </div>
                        </div>

                        <!-- Tab: ERP -->
                        <div id="tab-erp" class="hp-pm-tab-pane <?php echo $active_tab_id === 'erp' ? 'active' : ''; ?>">
                            <div style="margin-top:20px;">
                                <h2><?php esc_html_e('Sales History', 'hp-products-manager'); ?></h2>
                                <div style="margin:12px 0 4px 0;">
                                    <canvas id="hp-pm-erp-sales-chart" height="110"></canvas>
                                </div>
                                <?php $hp_pm_show_debug = current_user_can('manage_woocommerce'); if ($hp_pm_show_debug) : ?>
                                <div class="hp-pm-erp-toolbar" style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin:0 0 10px;">
                                    <div class="hp-pm-erp-actions" style="display:flex; gap:8px; align-items:center;">
                                        <button id="hp-pm-erp-rebuild-product" class="button button-small"><?php esc_html_e('Rebuild 90d (this product)', 'hp-products-manager'); ?></button>
                                        <button id="hp-pm-erp-rebuild-all" class="button button-small"><?php esc_html_e('Rebuild ALL', 'hp-products-manager'); ?></button>
                                        <button id="hp-pm-erp-purge-db" class="button button-small"><?php esc_html_e('Purge all plugin DB', 'hp-products-manager'); ?></button>
                                        <button id="hp-pm-erp-rebuild-abort" class="button button-small" style="display:none;"><?php esc_html_e('Abort', 'hp-products-manager'); ?></button>
                                        <div id="hp-pm-erp-rebuild-progress" style="display:none; width: 220px; height: 24px; background: #f0f0f0; border-radius: 3px; overflow: hidden;">
                                            <div style="width:0%; height:100%; background:#007cba; transition: width 0.1s linear;" id="hp-pm-erp-rebuild-progress-fill"></div>
                                        </div>
                                        <span id="hp-pm-erp-rebuild-progress-label" style="display:none;"></span>
                                    </div>
                                    <div class="hp-pm-erp-ranges" style="display:flex; gap:6px;">
                                        <button type="button" class="button button-small hp-pm-erp-range" data-days="7">7d</button>
                                        <button type="button" class="button button-small hp-pm-erp-range" data-days="30">30d</button>
                                        <button type="button" class="button button-small hp-pm-erp-range" data-days="90">90d</button>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <section class="hp-pm-metrics" id="hp-pm-erp-stats" style="display:flex; gap:24px; margin:10px 0; align-items:center;">
                                    <div class="hp-pm-metric"><span class="hp-pm-metric-label"><?php esc_html_e('Total Sales', 'hp-products-manager'); ?></span> <span class="hp-pm-metric-value" id="hp-pm-erp-total">--</span></div>
                                    <div class="hp-pm-metric"><span class="hp-pm-metric-label"><?php esc_html_e('90d', 'hp-products-manager'); ?></span> <span class="hp-pm-metric-value" id="hp-pm-erp-90">--</span></div>
                                    <div class="hp-pm-metric"><span class="hp-pm-metric-label"><?php esc_html_e('30d', 'hp-products-manager'); ?></span> <span class="hp-pm-metric-value" id="hp-pm-erp-30">--</span></div>
                                    <div class="hp-pm-metric"><span class="hp-pm-metric-label"><?php esc_html_e('7d', 'hp-products-manager'); ?></span> <span class="hp-pm-metric-value" id="hp-pm-erp-7">--</span></div>
                                    <div class="hp-pm-metric"><span class="hp-pm-metric-label"><?php esc_html_e('QOH', 'hp-products-manager'); ?></span> <span class="hp-pm-metric-value" id="hp-pm-erp-qoh">--</span></div>
                                    <div class="hp-pm-metric"><span class="hp-pm-metric-label"><?php esc_html_e('Reserved', 'hp-products-manager'); ?></span> <span class="hp-pm-metric-value" id="hp-pm-erp-reserved">--</span></div>
                                    <div class="hp-pm-metric"><span class="hp-pm-metric-label"><?php esc_html_e('Available', 'hp-products-manager'); ?></span> <span class="hp-pm-metric-value" id="hp-pm-erp-available">--</span></div>
                                    <div class="hp-pm-metric"><span class="hp-pm-metric-label"><?php esc_html_e('Δ vs WC', 'hp-products-manager'); ?></span> <span class="hp-pm-metric-value" id="hp-pm-erp-qoh-diff">--</span></div>
                                </section>
                                <h2 style="margin-top:14px;"><?php esc_html_e('Stock Movements', 'hp-products-manager'); ?></h2>
                                <table class="widefat fixed striped" id="hp-pm-erp-table" style="margin-top: 8px;">
                                    <thead>
                                    <tr>
                                        <th><?php esc_html_e('Date', 'hp-products-manager'); ?></th>
                                        <th><?php esc_html_e('Type', 'hp-products-manager'); ?></th>
                                        <th><?php esc_html_e('Qty', 'hp-products-manager'); ?></th>
                                        <th><?php esc_html_e('Order / Customer', 'hp-products-manager'); ?></th>
                                        <th><?php esc_html_e('QOH After (computed)', 'hp-products-manager'); ?></th>
                                        <th><?php esc_html_e('Source', 'hp-products-manager'); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    /**
     * Register REST routes.
     */
    public function register_rest_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/products',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_get_products'],
                'permission_callback' => function (): bool {
                    return current_user_can('edit_products');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_get_product_detail'],
                'permission_callback' => function (): bool {
                    return current_user_can('edit_products');
                },
                'args' => [
                    'id' => [
                        // WP passes (value, request, param) to validators; wrap native to avoid arg mismatch
                        'validate_callback' => function ($value, $request, $param) { return is_numeric($value); },
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\\d+)/sales/daily',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_get_product_sales_daily'],
                'permission_callback' => function (): bool {
                    return current_user_can('edit_products');
                },
                'args' => [
                    'days' => [
                        'validate_callback' => function ($value): bool { return is_numeric($value); },
                        'required' => false,
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\\d+)/apply',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_apply_product_changes'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
                'args' => [
                    'id' => [
                        // WP passes (value, request, param) to validators; wrap native to avoid arg mismatch
                        'validate_callback' => function ($value, $request, $param) { return is_numeric($value); },
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\\d+)/duplicate',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_duplicate_product'],
                'permission_callback' => function (): bool {
                    return current_user_can('edit_products');
                },
                'args' => [
                    'id' => [
                        'validate_callback' => function ($value, $request, $param) { return is_numeric($value); },
                    ],
                ],
            ]
        );

        // Debug endpoint: list cost-related meta for a product (admin only)
        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\\d+)/meta',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_get_product_meta_debug'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
                'args' => [
                    'id' => [
                        'validate_callback' => function ($value, $request, $param) { return is_numeric($value); },
                    ],
                ],
            ]
        );

        // ERP minimal: manual install of event log table (feature-flag protected)
        register_rest_route(
            self::REST_NAMESPACE,
            '/erp/install-log',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_erp_install_log'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
            ]
        );

        // ERP minimal: read event log (feature-flag protected)
        register_rest_route(
            self::REST_NAMESPACE,
            '/erp/logs',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_erp_get_logs'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
                'args' => [
                    'limit' => [],
                    'event' => [],
                    'product_id' => [],
                ],
            ]
        );

        // ERP minimal: manual install of core ERP schema (movements/state)
        register_rest_route(
            self::REST_NAMESPACE,
            '/erp/install-schema',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_erp_install_schema'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
            ]
        );

        // ERP debug: log synthetic events for testing
        register_rest_route(
            self::REST_NAMESPACE,
            '/erp/debug',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_erp_debug'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
            ]
        );

		// ERP maintenance: purge all plugin data (tables are emptied; option cleared)
		register_rest_route(
			self::REST_NAMESPACE,
			'/erp/purge',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [$this, 'rest_erp_purge'],
				'permission_callback' => function (): bool {
					return current_user_can('manage_woocommerce');
				},
			]
		);

        // Movements from logs (read-only)
        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\\d+)/movements/logs',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_product_movements_from_logs'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
                'args' => [
                    'id' => ['validate_callback' => function ($value) { return is_numeric($value); }],
                    'limit' => [],
                ],
            ]
        );

        // Movements from DB (preferred when available)
        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\\d+)/movements',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_product_movements_db'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
                'args' => [
                    'id' => ['validate_callback' => function ($value) { return is_numeric($value); }],
                    'limit' => [],
                ],
            ]
        );

        // Rebuild ALL from WC orders with progress (admin-only)
        register_rest_route(
            self::REST_NAMESPACE,
            '/movements/rebuild-all/start',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_rebuild_all_start'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
            ]
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/movements/rebuild-all/step',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_rebuild_all_step'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
            ]
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/movements/rebuild-all/status',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_rebuild_all_status'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
            ]
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/movements/rebuild-all/abort',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_rebuild_all_abort'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
            ]
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\d+)/movements/rebuild',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_rebuild_product_movements'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
                'args' => [
                    'id' => [
                        'validate_callback' => function ($value, $request, $param) { return is_numeric($value); },
                    ],
                ],
            ]
        );

        // Persist from logs into movements (manual, debug/safe)
        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\\d+)/movements/persist-from-logs',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'rest_product_persist_from_logs'],
                'permission_callback' => function (): bool {
                    return current_user_can('manage_woocommerce');
                },
                'args' => [
                    'id' => ['validate_callback' => function ($value) { return is_numeric($value); }],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/product/(?P<id>\d+)/seo-audit',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_get_product_seo_audit'],
                'permission_callback' => function (): bool {
                    return current_user_can('edit_products');
                },
                'args' => [
                    'id' => [
                        'validate_callback' => function ($value, $request, $param) { return is_numeric($value); },
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/old2new-packets',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'rest_get_old2new_packets'],
                    'permission_callback' => function (): bool {
                        return current_user_can('edit_products');
                    },
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'rest_create_old2new_packet'],
                    'permission_callback' => function (): bool {
                        return current_user_can('manage_woocommerce');
                    },
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/old2new-packets/(?P<id>\d+)',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'rest_update_old2new_packet'],
                    'permission_callback' => function (): bool {
                        return current_user_can('manage_woocommerce');
                    },
                    'args' => [
                        'id' => [
                            'validate_callback' => function ($value, $request, $param) { return is_numeric($value); },
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'rest_delete_old2new_packet'],
                    'permission_callback' => function (): bool {
                        return current_user_can('manage_woocommerce');
                    },
                    'args' => [
                        'id' => [
                            'validate_callback' => function ($value, $request, $param) { return is_numeric($value); },
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/old2new-products/search',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_search_old2new_products'],
                'permission_callback' => function (): bool {
                    return current_user_can('edit_products');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/old2new-badges',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_get_old2new_badges'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function rest_get_old2new_packets(WP_REST_Request $request) {
        $query = new WP_Query([
            'post_type' => self::OLD2NEW_PACKET_CPT,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 200,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        $packets = [];
        foreach ($query->posts as $packet_id) {
            $packet = $this->old2new_packet_response((int) $packet_id);
            if ($packet) {
                $packets[] = $packet;
            }
        }

        return rest_ensure_response(['packets' => $packets]);
    }

    public function rest_create_old2new_packet(WP_REST_Request $request) {
        $saved = $this->save_old2new_packet($request->get_json_params() ?: []);

        return is_wp_error($saved) ? $saved : rest_ensure_response($saved);
    }

    public function rest_update_old2new_packet(WP_REST_Request $request) {
        $packet_id = (int) $request['id'];
        if (get_post_type($packet_id) !== self::OLD2NEW_PACKET_CPT) {
            return new \WP_Error('old2new_not_found', __('Old2New packet not found.', 'hp-products-manager'), ['status' => 404]);
        }

        $saved = $this->save_old2new_packet($request->get_json_params() ?: [], $packet_id);

        return is_wp_error($saved) ? $saved : rest_ensure_response($saved);
    }

    public function rest_delete_old2new_packet(WP_REST_Request $request) {
        $packet_id = (int) $request['id'];
        if (get_post_type($packet_id) !== self::OLD2NEW_PACKET_CPT) {
            return new \WP_Error('old2new_not_found', __('Old2New packet not found.', 'hp-products-manager'), ['status' => 404]);
        }

        $deleted = wp_delete_post($packet_id, true);
        $this->old2new_old_sku_index = null;

        return rest_ensure_response(['deleted' => (bool) $deleted, 'id' => $packet_id]);
    }

    public function rest_search_old2new_products(WP_REST_Request $request) {
        $search = sanitize_text_field((string) $request->get_param('search'));
        $args = [
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 20,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ];

        if ($search !== '') {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $products = [];
        $seen = [];

        if ($search !== '' && function_exists('wc_get_product_id_by_sku')) {
            $sku_product_id = (int) wc_get_product_id_by_sku($search);
            if ($sku_product_id > 0) {
                $sku_product = wc_get_product($sku_product_id);
                if ($sku_product instanceof WC_Product) {
                    $products[] = $this->old2new_product_summary($sku_product);
                    $seen[$sku_product_id] = true;
                }
            }
        }

        foreach ($query->posts as $product_id) {
            if (isset($seen[(int) $product_id])) {
                continue;
            }
            $product = wc_get_product((int) $product_id);
            if ($product instanceof WC_Product) {
                $products[] = $this->old2new_product_summary($product);
            }
        }

        return rest_ensure_response(['products' => $products]);
    }

    public function rest_get_old2new_badges(WP_REST_Request $request) {
        $ids_param = (string) $request->get_param('product_ids');
        $ids = array_values(array_filter(array_map('absint', preg_split('/[,\s]+/', $ids_param) ?: [])));
        $badges = [];

        foreach (array_slice($ids, 0, 50) as $product_id) {
            $payload = $this->old2new_badge_payload_for_product_id($product_id);
            if ($payload) {
                $badges[(string) $product_id] = $payload;
            }
        }

        return rest_ensure_response(['badges' => $badges]);
    }

    /**
     * REST callback returning product rows.
     */
    public function rest_get_products(WP_REST_Request $request) {
        $t0       = microtime(true);
        $page     = max(1, (int) $request->get_param('page'));
        $per_page_param = $request->get_param('per_page');
        $per_page = min(200, max(1, (int) $per_page_param ?: 50));
        $request_all = (is_string($per_page_param) && strtolower($per_page_param) === 'all') || (string) $per_page_param === '-1';
        $search   = sanitize_text_field((string) $request->get_param('search'));
        $status   = sanitize_key((string) $request->get_param('status'));
        $brand_tax = sanitize_key((string) $request->get_param('brand_tax'));
        $brand_slug = sanitize_title((string) $request->get_param('brand_slug'));

        $stock_min = $request->get_param('stock_min');
        $stock_max = $request->get_param('stock_max');
        $stock_min = ($stock_min !== null && $stock_min !== '') ? (float) $stock_min : null;
        $stock_max = ($stock_max !== null && $stock_max !== '') ? (float) $stock_max : null;

        $args = [
            'post_type'      => ['product'],
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'suppress_filters' => false,
        ];

        // If client requests all products, decide whether to honor or fall back
        if ($request_all) {
            $counts = wp_count_posts('product');
            $total  = (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0) + (int) ($counts->pending ?? 0) + (int) ($counts->private ?? 0);

            if ($total > 0 && $total <= self::ALL_LOAD_THRESHOLD) {
                $args['posts_per_page'] = -1; // load all
                $args['paged'] = 1;
                $args['no_found_rows'] = true; // optimization when loading all
            }
        }

        if ($search !== '') {
            $args['s'] = $search;
        }

        if ($status === 'enabled') {
            $args['post_status'] = ['publish'];
        } elseif ($status === 'disabled') {
            $args['post_status'] = ['draft', 'pending', 'private'];
        }

        if ($brand_tax && $brand_slug && taxonomy_exists($brand_tax)) {
            $args['tax_query'] = [
                [
                    'taxonomy' => $brand_tax,
                    'field'    => 'slug',
                    'terms'    => $brand_slug,
                ],
            ];
        }

        $query = new WP_Query($args);

        // Prime caches for faster meta/term access
        if (!empty($query->posts)) {
            update_meta_cache('post', $query->posts);
            if (function_exists('update_object_term_cache')) {
                update_object_term_cache($query->posts, array_values(array_filter([$this->get_active_brand_taxonomy(), 'product_visibility'])));
            }
            // Compute reserved quantities for the set we are about to render
            $this->reserved_quantities_map = $this->get_reserved_quantities($query->posts);
        }

        $rows = [];

        foreach ($query->posts as $product_id) {
            $product = wc_get_product($product_id);

            if (!$product instanceof WC_Product) {
                continue;
            }

            $row = $this->format_product_row($product);

            if ($stock_min !== null && $row['stock'] !== null && $row['stock'] < $stock_min) {
                continue;
            }

            if ($stock_max !== null && $row['stock'] !== null && $row['stock'] > $stock_max) {
                continue;
            }

            $rows[] = $row;
        }

        wp_reset_postdata();

        $build_ms = (int) round((microtime(true) - $t0) * 1000);

        return rest_ensure_response([
            'products'   => $rows,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $args['posts_per_page'] === -1 ? 'all' : $per_page,
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) max(1, $query->max_num_pages),
            ],
            'metrics'    => $this->get_metrics_data(),
            'timing'     => [
                'build_ms' => $build_ms,
            ],
        ]);
    }

    /**
     * Build a response row for the product table.
     */
    private function format_product_row(WC_Product $product): array {
        $product_id    = $product->get_id();
        $stock_qty     = $product->managing_stock() ? $product->get_stock_quantity() : null;
        $stock_qty     = $stock_qty !== null ? (int) $stock_qty : null;
        $stock_status  = function_exists('wc_get_stock_status_name')
            ? wc_get_stock_status_name($product->get_stock_status())
            : ucfirst($product->get_stock_status());

        $cost  = $this->get_product_cost($product_id);
        $price = $product->get_price('edit');
        $price = $price !== '' ? (float) $price : null;
        $margin_pct = null;
        if ($cost !== null && $price !== null && $price > 0) {
            $margin_pct = round((($price - (float) $cost) / $price) * 100, 1);
        }

        $reserved = isset($this->reserved_quantities_map[$product_id]) ? (int) $this->reserved_quantities_map[$product_id] : 0;
        $available = $stock_qty !== null ? ((int) $stock_qty - $reserved) : null;

        return [
            'id'           => $product_id,
            'image'        => $this->product_image_url($product),
            'name'         => $product->get_name(),
            'sku'          => $product->get_sku(),
            'cost'         => $cost,
            'price'        => $price,
            'margin'       => $margin_pct,
            'brand'        => $this->get_product_brand_label($product),
            'stock'        => $stock_qty,
            'stock_reserved' => $reserved,
            'stock_available'=> $available,
            'stock_detail' => $stock_status,
            'status'       => $product->get_status() === 'publish' ? __('Enabled', 'hp-products-manager') : __('Disabled', 'hp-products-manager'),
            'visibility'   => $this->map_visibility_label($product->get_catalog_visibility()),
            'visibility_code' => $product->get_catalog_visibility(),
        ];
    }

    private function get_product_brand_label(WC_Product $product): string {
        $product_id = $product->get_id();
        $names = [];

        $taxonomies = array_values(array_unique(array_merge(
            ['product_brand'],
            (array) apply_filters(
                'hp_products_manager_brand_taxonomies',
                ['product_brand', 'yith_product_brand']
            )
        )));

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($product_id, $taxonomy);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $names[$term->name] = true;
            }
        }

        if (!empty($names)) {
            return implode(', ', array_keys($names));
        }

        $attribute_targets = array_map(
            [$this, 'normalise_attribute_key'],
            (array) apply_filters('hp_products_manager_brand_attribute_keys', ['brand'])
        );

        if (!empty($attribute_targets)) {
            foreach ($product->get_attributes() as $attribute) {
                $attribute_key = $this->normalise_attribute_key($attribute->get_name());

                if (!in_array($attribute_key, $attribute_targets, true)) {
                    continue;
                }

                if ($attribute->is_taxonomy()) {
                    $term_names = wc_get_product_terms($product_id, $attribute->get_name(), ['fields' => 'names']);

                    foreach ($term_names as $name) {
                        if ($name !== '') {
                            $names[$name] = true;
                        }
                    }

                    continue;
                }

                foreach ($attribute->get_options() as $option) {
                    $option = is_string($option) ? trim(wp_strip_all_tags($option)) : $option;

                    if ($option !== '') {
                        $names[(string) $option] = true;
                    }
                }
            }
        }

        if (empty($names)) {
            return '';
        }

        return implode(', ', array_keys($names));
    }

    private function map_visibility_label(string $visibility): string {
        switch ($visibility) {
            case 'visible':
                return __('Catalog & Search', 'hp-products-manager');
            case 'catalog':
                return __('Catalog Only', 'hp-products-manager');
            case 'search':
                return __('Search Only', 'hp-products-manager');
            case 'hidden':
                return __('Hidden', 'hp-products-manager');
            default:
                return ucfirst($visibility);
        }
    }

    private function get_product_cost(int $product_id): ?float {
        $key = apply_filters('hp_products_manager_cost_meta_key', self::COST_META_KEY);
        $value = get_post_meta($product_id, $key, true);
        $parsed = $this->parse_decimal_relaxed($value);
        return $parsed !== null ? $parsed : null;
    }

    // Strict cost reader for Product Detail page: definitive meta only.
    private function get_strict_cost(int $product_id): ?float {
        $value = get_post_meta($product_id, self::COST_META_KEY, true);
        $parsed = $this->parse_decimal_relaxed($value);
        return $parsed !== null ? $parsed : null;
    }

    /**
     * Helper to get product labels (Name + SKU) for a list of IDs
     */
    private function get_product_labels($ids) {
        if (empty($ids) || !is_array($ids)) return [];
        global $wpdb;
        $ids = array_map('intval', $ids);
        $ids_str = implode(',', $ids);
        $results = $wpdb->get_results("SELECT ID as id, post_title as name FROM {$wpdb->posts} WHERE ID IN ($ids_str)");
        $out = [];
        foreach ($results as $r) {
            $sku = get_post_meta($r->id, '_sku', true);
            $out[(int)$r->id] = $r->name . ($sku ? ' (' . $sku . ')' : ' (no SKU)');
        }
        return $out;
    }

    // Update cost in canonical meta and popular Cost-of-Goods keys for compatibility
    private function update_cost_meta(int $product_id, float $cost): void {
        // Write to the definitive key
        update_post_meta($product_id, self::COST_META_KEY, $cost);
        // Optional: keep common COGS keys in sync for compatibility
        update_post_meta($product_id, '_wc_cog_cost', $cost);
        update_post_meta($product_id, 'wc_cog_cost', $cost);
        update_post_meta($product_id, '_alg_wc_cog_cost', $cost);
        update_post_meta($product_id, 'product_po_cost', $cost);
    }

    private function extract_term_slugs($terms): array {
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }
        $out = [];
        foreach ((array) $terms as $t) {
            if (is_object($t) && isset($t->slug)) {
                $out[] = $t->slug;
            } elseif (is_string($t)) {
                $out[] = sanitize_title($t);
            }
        }
        return $out;
    }

    private function get_shipping_class_slug_for(int $product_id): string {
        $t = wc_get_product_terms($product_id, 'product_shipping_class', ['fields' => 'all']);
        $sl = $this->extract_term_slugs($t);
        return !empty($sl) ? $sl[0] : '';
    }

    // More tolerant parsing for legacy values (e.g., '$12.34' or '12,34')
    private function parse_decimal_relaxed($value): ?float {
        if (is_array($value)) {
            $value = array_first($value);
        }
        if ($value === '' || $value === null) {
            return null;
        }
        // Try WooCommerce helper first
        if (function_exists('wc_format_decimal')) {
            $formatted = wc_format_decimal($value);
            if ($formatted !== '' && $formatted !== null) {
                return (float) $formatted;
            }
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        // Strip all but digits, dot and sign
        $filtered = preg_replace('/[^0-9.\\-]/', '', (string) $value);
        if ($filtered === '' || $filtered === '.' || $filtered === '-') {
            return null;
        }
        return (float) $filtered;
    }

    private function get_metrics_data(): array {
        $cached = wp_cache_get(self::METRICS_CACHE_KEY, self::CACHE_GROUP);

        if (is_array($cached)) {
            return $cached;
        }

        $counts   = wp_count_posts('product');
        $enabled  = isset($counts->publish) ? (int) $counts->publish : 0;
        $total    = 0;
        foreach ((array) $counts as $key => $value) {
            if (is_numeric($value)) {
                $total += (int) $value;
            }
        }

        $inventory      = $this->calculate_inventory_metrics();
        $reserved_map   = $this->get_reserved_quantities();
        $reserved_total = 0;
        foreach ($reserved_map as $qty) { $reserved_total += (int) $qty; }

        $metrics = [
            'total'      => $total,
            'enabled'    => $enabled,
            'stock_cost' => $inventory['stock_cost'],
            'reserved'   => $reserved_total,
        ];

        wp_cache_set(self::METRICS_CACHE_KEY, $metrics, self::CACHE_GROUP, self::METRICS_TTL);

        return $metrics;
    }

    private function count_hidden_products(): int {
        if (!taxonomy_exists('product_visibility')) {
            return 0;
        }

        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'tax_query'      => [
                'relation' => 'OR',
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => ['exclude-from-catalog'],
                    'include_children' => false,
                ],
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => ['exclude-from-search'],
                    'include_children' => false,
                ],
            ],
        ]);

        wp_reset_postdata();

        return (int) $query->found_posts;
    }

    private function calculate_inventory_metrics(): array {
        $stock_cost_sum = 0.0;

        $products = wc_get_products([
            'limit'  => -1,
            'status' => ['publish'],
            'return' => 'objects',
        ]);

        if (!empty($products)) {
            foreach ($products as $product) {
                if (!$product instanceof WC_Product) {
                    continue;
                }

                $qoh = $product->managing_stock() ? $product->get_stock_quantity() : null;
                $qoh = $qoh !== null ? (int) $qoh : null;
                $cost = $this->get_product_cost($product->get_id());

                if ($qoh !== null && $cost !== null) {
                    $stock_cost_sum += ((float) $cost) * (float) $qoh;
                }
            }
        }

        return [
            'stock_cost' => round($stock_cost_sum, 2),
        ];
    }

    public function flush_metrics_cache($unused = null): void {
        wp_cache_delete(self::METRICS_CACHE_KEY, self::CACHE_GROUP);
    }

    public function maybe_flush_deleted_product_cache(int $post_id, WP_Post $post): void {
        if ($post->post_type === 'product') {
            $this->flush_metrics_cache();
        }
    }

    private function normalise_attribute_key(string $key): string {
        $key = strtolower($key);

        if (strpos($key, 'pa_') === 0) {
            $key = substr($key, 3);
        }

        return sanitize_title($key);
    }

    // --- ERP (minimal) helpers ---
    private function table_event_log(): string {
        global $wpdb;
        return $wpdb->prefix . 'hp_pm_event_log';
    }
    private function table_movements(): string {
        global $wpdb;
        return $wpdb->prefix . 'hp_pm_movements';
    }
    private function table_state(): string {
        global $wpdb;
        return $wpdb->prefix . 'hp_pm_state';
    }

    private function movements_table_exists(): bool {
        global $wpdb;
        $table = $this->table_movements();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    public function maybe_install_tables(bool $force = false): void {
        // Create/upgrade ERP tables when the schema version changes.
        if (!$force && !current_user_can('manage_woocommerce')) return;
        if ($this->is_hp_inventory_erp_migrated()) return;

        $installed_version = (string) get_option('hp_pm_erp_schema_version', '');
        if (!$force && $installed_version === self::ERP_SCHEMA_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $movements = $this->table_movements();
        $state = $this->table_state();
        $elog = $this->table_event_log();
        $sqlLog = "CREATE TABLE {$elog} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event VARCHAR(64) NOT NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event (event),
            KEY created_at (created_at)
        ) {$charset};";
        $sqlMov = "CREATE TABLE {$movements} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            movement_type VARCHAR(32) NOT NULL,
            qty INT NOT NULL,
            qoh_after INT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(191) NULL,
            source VARCHAR(64) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY created_at (created_at),
            KEY order_id (order_id)
        ) {$charset};";
        $sqlState = "CREATE TABLE {$state} (
            product_id BIGINT UNSIGNED NOT NULL,
            last_qoh INT NULL,
            last_order_id_synced BIGINT UNSIGNED NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (product_id)
        ) {$charset};";
        dbDelta($sqlLog);
        dbDelta($sqlMov);
        dbDelta($sqlState);

        $tables_installed = true;
        foreach ([$elog, $movements, $state] as $table) {
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
                $tables_installed = false;
                break;
            }
        }

        if ($tables_installed) {
            update_option('hp_pm_erp_schema_version', self::ERP_SCHEMA_VERSION, false);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('hp_pm.erp_schema.installed version=' . self::ERP_SCHEMA_VERSION);
            }
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('hp_pm.erp_schema.install_failed version=' . self::ERP_SCHEMA_VERSION);
        }
    }

    private function erp_log_event(string $event, array $payload = []): void {
        global $wpdb;
        $table = $this->table_event_log();
        // Bail if table does not exist
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        if (!$exists) {
            return;
        }
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        $wpdb->insert($table, [
            'event' => sanitize_key($event),
            'payload' => $json,
            'created_at' => current_time('mysql'),
        ], ['%s','%s','%s']);
    }

    private function write_movement_row(array $data): void {
        // data keys: product_id, order_id, movement_type, qty, qoh_after, customer_name, source, created_at
        if (!$this->movements_table_exists()) return;
        global $wpdb;
        $table = $this->table_movements();
        $wpdb->insert($table, [
            'product_id' => (int) ($data['product_id'] ?? 0),
            'order_id' => isset($data['order_id']) ? (int) $data['order_id'] : null,
            'movement_type' => sanitize_key((string) ($data['movement_type'] ?? '')),
            'qty' => (int) ($data['qty'] ?? 0),
            'qoh_after' => isset($data['qoh_after']) ? (int) $data['qoh_after'] : null,
            'customer_id' => null,
            'customer_name' => isset($data['customer_name']) ? sanitize_text_field((string) $data['customer_name']) : null,
            'source' => isset($data['source']) ? sanitize_text_field((string) $data['source']) : null,
            'created_at' => isset($data['created_at']) ? (string) $data['created_at'] : current_time('mysql'),
        ], ['%d','%d','%s','%d','%d','%d','%s','%s','%s']);
    }

    /**
     * REST: Create/ensure the event log table exists (manual action).
     */
    public function rest_erp_install_log(WP_REST_Request $request) {
        if (self::ERP_ENABLED !== false && !current_user_can('manage_woocommerce')) {
            return new \WP_Error('forbidden', __('Not allowed', 'hp-products-manager'), ['status' => 403]);
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $elog = $this->table_event_log();
        $sql = "CREATE TABLE {$elog} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event VARCHAR(64) NOT NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event (event),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta($sql);
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $elog)) === $elog;
        return rest_ensure_response(['ok' => (bool) $exists, 'table' => $elog]);
    }

    /**
     * REST: Read recent rows from event log (admin-only, minimal use for validation)
     */
    public function rest_erp_get_logs(WP_REST_Request $request) {
        if (self::ERP_ENABLED !== false && !current_user_can('manage_woocommerce')) {
            return new \WP_Error('forbidden', __('Not allowed', 'hp-products-manager'), ['status' => 403]);
        }
        global $wpdb;
        $table = $this->table_event_log();
        $limit = min(500, max(1, (int) ($request->get_param('limit') ?: 100)));
        $event = trim((string) ($request->get_param('event') ?: ''));
        $product_id = (int) ($request->get_param('product_id') ?: 0);
        $like = $product_id > 0 ? '%' . $wpdb->esc_like('"product_id":' . $product_id) . '%' : '';
        if ($event !== '' && $product_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, event, payload, created_at FROM {$table} WHERE event=%s AND payload LIKE %s ORDER BY id DESC LIMIT %d", $event, $like, $limit), ARRAY_A);
        } elseif ($event !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, event, payload, created_at FROM {$table} WHERE event=%s ORDER BY id DESC LIMIT %d", $event, $limit), ARRAY_A);
        } elseif ($product_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, event, payload, created_at FROM {$table} WHERE payload LIKE %s ORDER BY id DESC LIMIT %d", $like, $limit), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, event, payload, created_at FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
        }
        return rest_ensure_response([
            'rows' => is_array($rows) ? $rows : [],
            'count' => is_array($rows) ? count($rows) : 0,
        ]);
    }

    /**
     * REST: Create movements/state schema (manual install). Stops short of enabling hooks.
     */
    public function rest_erp_install_schema(WP_REST_Request $request) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $movements = $this->table_movements();
        $state = $this->table_state();
        $sql1 = "CREATE TABLE {$movements} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            movement_type VARCHAR(32) NOT NULL,
            qty INT NOT NULL,
            qoh_after INT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(191) NULL,
            source VARCHAR(64) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY created_at (created_at),
            KEY order_id (order_id)
        ) {$charset};";
        $sql2 = "CREATE TABLE {$state} (
            product_id BIGINT UNSIGNED NOT NULL,
            last_qoh INT NULL,
            last_order_id_synced BIGINT UNSIGNED NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (product_id)
        ) {$charset};";
        dbDelta($sql1);
        dbDelta($sql2);
        $have_mov = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $movements)) === $movements;
        $have_state = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $state)) === $state;
        return rest_ensure_response(['ok' => (bool) ($have_mov && $have_state), 'tables' => ['movements' => $have_mov, 'state' => $have_state]]);
    }

    /**
     * REST: Debug logging - synthesize events on demand (admin only)
     */
    public function rest_erp_debug(WP_REST_Request $request) {
        $action = sanitize_key((string) $request->get_param('action'));
        $product_id = (int) ($request->get_param('product_id') ?: 0);
        $qty = (int) ($request->get_param('qty') ?: 1);
        $qoh = $request->get_param('qoh');
        $qoh = is_numeric($qoh) ? (int) $qoh : null;

        if ($product_id > 0) {
            $product = wc_get_product($product_id);
            if ($product instanceof \WC_Product) {
                $sku = (string) $product->get_sku();
            } else {
                $sku = '';
            }
        } else {
            $sku = '';
        }

        switch ($action) {
            case 'set_stock':
                if ($qoh === null && isset($product) && $product instanceof \WC_Product && $product->managing_stock()) {
                    $qoh = (int) $product->get_stock_quantity();
                }
                $this->erp_log_event('product_set_stock', [
                    'product_id' => $product_id,
                    'qoh' => ($qoh === null ? 0 : (int) $qoh),
                    'source' => 'debug',
                ]);
                break;
            case 'reduce':
                $this->erp_log_event('reduce_order_stock', [
                    'order_id' => 0,
                    'status' => 'processing',
                    'customer_id' => 0,
                    'customer_name' => 'Debug Customer',
                    'items' => [ [ 'product_id' => $product_id, 'qty' => -abs((int)$qty), 'sku' => $sku ] ],
                    'source' => 'debug',
                ]);
                break;
            case 'restore':
                $this->erp_log_event('restore_order_stock', [
                    'order_id' => 0,
                    'status' => 'refunded',
                    'customer_id' => 0,
                    'customer_name' => 'Debug Customer',
                    'items' => [ [ 'product_id' => $product_id, 'qty' => abs((int)$qty), 'sku' => $sku ] ],
                    'source' => 'debug',
                ]);
                break;
            default:
                return new \WP_Error('bad_request', __('Unknown debug action', 'hp-products-manager'), ['status' => 400]);
        }

        return rest_ensure_response(['ok' => true, 'action' => $action, 'product_id' => $product_id, 'qty' => (int)$qty]);
    }

	/**
	 * REST: Purge all plugin data (truncate ERP tables and clear progress/state option).
	 */
	public function rest_erp_purge(WP_REST_Request $request) {
		if (!current_user_can('manage_woocommerce')) {
			return new \WP_Error('forbidden', __('Not allowed', 'hp-products-manager'), ['status' => 403]);
		}
		global $wpdb;
		$purged = [];
		$tables = [$this->table_event_log(), $this->table_movements(), $this->table_state()];
		foreach ($tables as $t) {
			$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t;
			if ($exists) {
				$wpdb->query("TRUNCATE {$t}");
				$purged[] = $t;
			}
		}
		delete_option('hp_pm_rebuild_all_state');
		// Clear cached metrics
		wp_cache_delete(self::METRICS_CACHE_KEY, self::CACHE_GROUP);
		return rest_ensure_response(['ok' => true, 'purged' => $purged]);
	}

    /**
     * REST: Build movement rows for a product from the event log (read-only)
     */
    public function rest_product_movements_from_logs(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $limit = min(500, max(1, (int) ($request->get_param('limit') ?: 200)));
        global $wpdb;
        $table = $this->table_event_log();
        // Fetch recent log entries referencing this product
        $like = '%' . $wpdb->esc_like('"product_id":' . $id) . '%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, event, payload, created_at FROM {$table} WHERE payload LIKE %s ORDER BY id DESC LIMIT %d", $like, $limit), ARRAY_A);

        $movements = [];
        $sales_total = 0; $now = current_time('timestamp');
        $win90 = 0; $win30 = 0; $win7 = 0;
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $payload = json_decode((string) ($row['payload'] ?? ''), true);
                $event = (string) ($row['event'] ?? '');
                $created_at = (string) ($row['created_at'] ?? '');
                $ts = $created_at ? strtotime($created_at) : 0;
                if ($event === 'product_set_stock') {
                    $movements[] = [
                        'created_at' => $created_at,
                        'movement_type' => 'set_stock',
                        'qty' => null,
                        'order_id' => null,
                        'customer_name' => null,
                        'qoh_after' => isset($payload['qoh']) ? (int) $payload['qoh'] : null,
                        'source' => isset($payload['source']) ? (string) $payload['source'] : '',
                    ];
                    continue;
                }
                if ($event === 'reduce_order_stock' || $event === 'restore_order_stock') {
                    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
                    foreach ($items as $it) {
                        if ((int) ($it['product_id'] ?? 0) !== $id) continue;
                        $qty = (int) ($it['qty'] ?? 0);
                        $movements[] = [
                            'created_at' => $created_at,
                            'movement_type' => ($event === 'reduce_order_stock' ? 'sale' : 'restore'),
                            'qty' => $qty,
                            'order_id' => isset($payload['order_id']) ? (int) $payload['order_id'] : null,
                            'customer_name' => isset($payload['customer_name']) ? (string) $payload['customer_name'] : '',
                            'qoh_after' => null,
                            'source' => isset($payload['source']) ? (string) $payload['source'] : '',
                        ];
                        if ($event === 'reduce_order_stock') {
                            $abs = abs($qty);
                            $sales_total += $abs;
                            if ($ts && ($now - $ts) <= 90 * DAY_IN_SECONDS) $win90 += $abs;
                            if ($ts && ($now - $ts) <= 30 * DAY_IN_SECONDS) $win30 += $abs;
                            if ($ts && ($now - $ts) <= 7 * DAY_IN_SECONDS) $win7 += $abs;
                        }
                    }
                }
            }
        }

        // Sort descending by date/id to match recent-first display
        usort($movements, function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        // Enrich with computed QOH/diff based on current WC stock
        $product = wc_get_product($id);
        $wc_qoh = 0;
        if ($product instanceof \WC_Product) {
            $q = $product->get_stock_quantity();
            $wc_qoh = $q !== null ? (int) $q : 0;
        }
        $rolling = (int) $wc_qoh;
        foreach ($movements as &$m) {
            if ((string) ($m['movement_type'] ?? '') === 'set_stock' && $m['qoh_after'] !== null) {
                $rolling = (int) $m['qoh_after'];
            }
            $m['computed_qoh_after'] = (int) $rolling;
            $m['wc_qoh'] = (int) $wc_qoh;
            $m['qoh_diff'] = (int) ($rolling - $wc_qoh);
            $rolling = (int) ($rolling - (int) ($m['qty'] ?? 0));
        }
        unset($m);

        return rest_ensure_response([
            'rows' => $movements,
            'stats' => [
                'total_sales' => (int) $sales_total,
                'sales_90' => (int) $win90,
                'sales_30' => (int) $win30,
                'sales_7' => (int) $win7,
                'computed_qoh' => (int) (!empty($movements) ? $movements[0]['computed_qoh_after'] : $wc_qoh),
                'wc_qoh' => (int) $wc_qoh,
                'qoh_diff' => (int) ((!empty($movements) ? $movements[0]['computed_qoh_after'] : $wc_qoh) - $wc_qoh),
            ],
        ]);
    }

    /**
     * REST: Read movements for a product from DB table.
     */
    public function rest_product_movements_db(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $limit = min(500, max(1, (int) ($request->get_param('limit') ?: 200)));
        global $wpdb;
        $mov = $this->table_movements();
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $mov)) !== $mov) {
            return rest_ensure_response(['rows' => [], 'stats' => ['total_sales' => 0, 'sales_90' => 0, 'sales_30' => 0, 'sales_7' => 0]]);
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT created_at, movement_type, qty, order_id, customer_name, qoh_after, source FROM {$mov} WHERE product_id=%d ORDER BY created_at DESC, id DESC LIMIT %d", $id, $limit), ARRAY_A);
        $sales_total = 0; $now = current_time('timestamp');
        $win90 = 0; $win30 = 0; $win7 = 0;
        $product = wc_get_product($id);
        $wc_qoh = 0;
        if ($product instanceof \WC_Product) {
            $q = $product->get_stock_quantity();
            $wc_qoh = $q !== null ? (int) $q : 0;
        }
        // Compute rolling QOH-after (computed) starting from current WC QOH and walking backwards
        $rolling = (int) $wc_qoh;
        if (!empty($rows)) {
            foreach ($rows as $idx => &$r) {
                if ((string) ($r['movement_type'] ?? '') === 'sale') {
                    $abs = abs((int) ($r['qty'] ?? 0));
                    $sales_total += $abs;
                    $ts = strtotime((string) ($r['created_at'] ?? ''));
                    if ($ts && ($now - $ts) <= 90 * DAY_IN_SECONDS) $win90 += $abs;
                    if ($ts && ($now - $ts) <= 30 * DAY_IN_SECONDS) $win30 += $abs;
                    if ($ts && ($now - $ts) <= 7 * DAY_IN_SECONDS) $win7 += $abs;
                }
                // If there is an explicit qoh_after from a set_stock, reset rolling
                if ((string) ($r['movement_type'] ?? '') === 'set_stock' && $r['qoh_after'] !== null) {
                    $rolling = (int) $r['qoh_after'];
                }
                $r['computed_qoh_after'] = (int) $rolling;
                $r['wc_qoh'] = (int) $wc_qoh;
                $r['qoh_diff'] = (int) ($rolling - $wc_qoh);
                // Walk backwards: undo this movement to get the state immediately after the previous (older) movement
                $rolling = (int) ($rolling - (int) ($r['qty'] ?? 0));
            }
            unset($r);
        }
        if (!empty($rows)) {
            // no-op; sales totals were computed above
        }
        return rest_ensure_response([
            'rows' => $rows ?: [],
            'stats' => [
                'total_sales' => (int) $sales_total,
                'sales_90' => (int) $win90,
                'sales_30' => (int) $win30,
                'sales_7' => (int) $win7,
                'computed_qoh' => (int) (!empty($rows) ? $rows[0]['computed_qoh_after'] : $wc_qoh),
                'wc_qoh' => (int) $wc_qoh,
                'qoh_diff' => (int) ((!empty($rows) ? $rows[0]['computed_qoh_after'] : $wc_qoh) - $wc_qoh),
            ],
        ]);
    }

    /**
     * REST: Daily sales aggregation for a product (continuous last-N-day series)
     */
    public function rest_get_product_sales_daily(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $days = min(365, max(1, (int) ($request->get_param('days') ?: 90)));
        global $wpdb;
        $mov = $this->table_movements();
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $mov)) !== $mov) {
            return rest_ensure_response(['labels' => [], 'values' => []]);
        }
        // Start at local midnight (days-1) days ago, include today
        $now_ts = current_time('timestamp');
        $start_ts = strtotime(date('Y-m-d 00:00:00', $now_ts - (($days - 1) * DAY_IN_SECONDS)));
        $cut = date('Y-m-d H:i:s', $start_ts);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS day, SUM(ABS(qty)) AS qty
             FROM {$mov}
             WHERE product_id=%d AND movement_type='sale' AND created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $id, $cut
        ), ARRAY_A);
        $by_day = [];
        if (is_array($rows)) {
            foreach ($rows as $r) { $by_day[(string) $r['day']] = (int) $r['qty']; }
        }
        $labels = []; $values = [];
        for ($i = 0; $i < $days; $i++) {
            $day = date('Y-m-d', $start_ts + ($i * DAY_IN_SECONDS));
            $labels[] = $day;
            $values[] = isset($by_day[$day]) ? (int) $by_day[$day] : 0;
        }
        return rest_ensure_response(['labels' => $labels, 'values' => $values]);
    }

    // --- Rebuild ALL with progress ---
    private function get_rebuild_all_state(): array {
        $state = get_option('hp_pm_rebuild_all_state', []);
        return is_array($state) ? $state : [];
    }
    private function set_rebuild_all_state(array $state): void {
        update_option('hp_pm_rebuild_all_state', $state, false);
    }

    private function rest_erp_retired_response() {
        return new \WP_Error(
            'hp_pm_erp_retired',
            'Product-Manager ERP persistence is retired because HP Inventory owns demand and movement history.',
            ['status' => 410]
        );
    }

    public function rest_rebuild_all_start(WP_REST_Request $request) {
        if ($this->is_hp_inventory_erp_migrated()) {
            return $this->rest_erp_retired_response();
        }

        global $wpdb;
        $mov = $this->table_movements();
        // Reset movements
        $wpdb->query("TRUNCATE {$mov}");
        // Count shop orders (exclude refunds) with Woo statuses in last 90d to match step() filtering
        $orders_table = $wpdb->prefix . 'wc_orders';
        $from_gmt = gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$orders_table} WHERE status LIKE %s AND type = %s AND date_created_gmt >= %s", 'wc-%', 'shop_order', $from_gmt));
        $state = array(
            'total' => $total,
            'processed' => 0,
            'last_order_id' => 0,
            'batch' => 200,
            'started_at' => current_time('mysql'),
            'from_gmt' => $from_gmt,
            'status' => 'running',
        );
        $this->set_rebuild_all_state($state);
        return rest_ensure_response($state);
    }
    public function rest_rebuild_all_step(WP_REST_Request $request) {
        if ($this->is_hp_inventory_erp_migrated()) {
            return $this->rest_erp_retired_response();
        }

        try {
            global $wpdb;
            $state = $this->get_rebuild_all_state();
            if (empty($state) || ($state['status'] ?? '') !== 'running') {
                return rest_ensure_response($state);
            }
            $orders_table = $wpdb->prefix . 'wc_orders';
            $batch = max(50, (int) ($state['batch'] ?? 200));
            $last = (int) ($state['last_order_id'] ?? 0);
            $from_gmt = isset($state['from_gmt']) ? $state['from_gmt'] : gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);
            // Only shop orders (exclude refunds) and Woo statuses within last 90d
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$orders_table} WHERE status LIKE %s AND type = %s AND date_created_gmt >= %s AND id > %d ORDER BY id ASC LIMIT %d", 'wc-%', 'shop_order', $from_gmt, $last, $batch));
            if (!empty($ids)) {
                foreach ($ids as $oid) {
                    $order = wc_get_order((int) $oid);
                    if (!$order || (method_exists($order, 'get_type') && $order->get_type() !== 'shop_order')) { continue; }
                    $created = $order->get_date_created();
                    $created_str = (is_object($created) && method_exists($created, 'getTimestamp')) ? date_i18n('Y-m-d H:i:s', $created->getTimestamp()) : current_time('mysql');
                    $by_product = [];
                    foreach ($order->get_items('line_item') as $item) {
                        $product = $item->get_product();
                        if (!$product instanceof \WC_Product) continue;
                        $pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
                        $qty = (int) $item->get_quantity();
                        $by_product[$pid] = isset($by_product[$pid]) ? ($by_product[$pid] + $qty) : $qty;
                    }
                    $status = $order->get_status();
                    if ($status === 'refunded' || $status === 'cancelled') {
                        $type = 'restore';
                    } elseif (
                        in_array($status, ['on-account'], true) ||
                        (method_exists($order, 'is_paid') ? $order->is_paid() : in_array($status, ['processing', 'completed'], true))
                    ) {
                        $type = 'sale';
                    } else {
                        $type = '';
                    }
                    foreach ($by_product as $pid => $qty_sum) {
                        if ($type === '') { continue; }
                        $this->write_movement_row([
                            'product_id' => (int) $pid,
                            'order_id' => $order->get_id(),
                            'movement_type' => $type,
                            'qty' => ($type === 'sale' ? -abs((int) $qty_sum) : abs((int) $qty_sum)),
                            'qoh_after' => null,
                            'customer_name' => trim($order->get_formatted_billing_full_name() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                            'source' => 'rebuild_all',
                            'created_at' => $created_str,
                        ]);
                    }
                    $state['last_order_id'] = (int) $oid;
                    $state['processed'] = (int) $state['processed'] + 1;
                }
            }
            if (empty($ids) || $state['processed'] >= (int) $state['total']) {
                $state['status'] = 'done';
            }
            $this->set_rebuild_all_state($state);
            return rest_ensure_response($state);
        } catch (\Throwable $e) {
            error_log('[HP PM] rebuild-all step error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return new \WP_Error('rebuild_step_failed', $e->getMessage(), ['status' => 500]);
        }
    }
    public function rest_rebuild_all_status(WP_REST_Request $request) {
        if ($this->is_hp_inventory_erp_migrated()) {
            return $this->rest_erp_retired_response();
        }

        return rest_ensure_response($this->get_rebuild_all_state());
    }

    public function rest_rebuild_all_abort(WP_REST_Request $request) {
        if ($this->is_hp_inventory_erp_migrated()) {
            return $this->rest_erp_retired_response();
        }

        $state = $this->get_rebuild_all_state();
        if (!empty($state)) {
            $state['status'] = 'aborted';
            $this->set_rebuild_all_state($state);
        }
        return rest_ensure_response($state);
    }

    /**
     * REST: Persist movements for a product by replaying its event-log rows
     */
    public function rest_product_persist_from_logs(WP_REST_Request $request) {
        if (!$this->is_erp_persist_enabled()) {
            return new \WP_Error('forbidden', __('Persistence disabled', 'hp-products-manager'), ['status' => 403]);
        }
        $id = (int) $request['id'];
        if ($id <= 0) return new \WP_Error('bad_request', __('Invalid product id', 'hp-products-manager'), ['status' => 400]);
        if (!$this->movements_table_exists()) return new \WP_Error('missing_table', __('Movements table missing', 'hp-products-manager'), ['status' => 500]);

        $payload = $this->rest_product_movements_from_logs(new WP_REST_Request('GET', ''));
        $data = $payload->get_data();
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        $created = current_time('mysql');
        foreach ($rows as $m) {
            try {
                $this->write_movement_row([
                    'product_id' => $id,
                    'order_id' => isset($m['order_id']) ? (int) $m['order_id'] : null,
                    'movement_type' => (string) ($m['movement_type'] ?? ''),
                    'qty' => isset($m['qty']) ? (int) $m['qty'] : 0,
                    'qoh_after' => isset($m['qoh_after']) ? (int) $m['qoh_after'] : null,
                    'customer_name' => isset($m['customer_name']) ? (string) $m['customer_name'] : null,
                    'source' => 'replay',
                    'created_at' => (string) ($m['created_at'] ?? $created),
                ]);
            } catch (\Throwable $e) { /* ignore individual failures */ }
        }
        return rest_ensure_response(['ok' => true, 'written' => count($rows)]);
    }

    /**
     * REST: Rebuild last 90 days of movements for a single product
     */
    public function rest_rebuild_product_movements(WP_REST_Request $request) {
        if (!$this->is_erp_persist_enabled()) {
            return new \WP_Error('forbidden', __('Persistence disabled', 'hp-products-manager'), ['status' => 403]);
        }
        global $wpdb;
        $id = (int) $request['id'];
        if ($id <= 0) return new \WP_Error('bad_request', __('Invalid product id', 'hp-products-manager'), ['status' => 400]);
        if (!$this->movements_table_exists()) return new \WP_Error('missing_table', __('Movements table missing', 'hp-products-manager'), ['status' => 500]);
        $orders_table = $wpdb->prefix . 'wc_orders';
        $from_gmt = gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);
        $cut_local = date('Y-m-d H:i:s', current_time('timestamp') - 90 * DAY_IN_SECONDS);
        // Clear current window for the product
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_movements()} WHERE product_id=%d AND created_at >= %s", $id, $cut_local));
        // Fetch candidate orders
        $oids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$orders_table} WHERE status LIKE %s AND type = %s AND date_created_gmt >= %s ORDER BY id ASC", 'wc-%', 'shop_order', $from_gmt));
        $written = 0;
        if (!empty($oids)) {
            foreach ($oids as $oid) {
                $order = wc_get_order((int) $oid);
                if (!$order || (method_exists($order, 'get_type') && $order->get_type() !== 'shop_order')) { continue; }
                $created = $order->get_date_created();
                $created_str = (is_object($created) && method_exists($created, 'getTimestamp')) ? date_i18n('Y-m-d H:i:s', $created->getTimestamp()) : current_time('mysql');
                $qty_sum = 0;
                foreach ($order->get_items('line_item') as $item) {
                    $product = $item->get_product();
                    if (!$product instanceof \WC_Product) continue;
                    $pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
                    if ((int) $pid !== $id) continue;
                    $qty_sum += (int) $item->get_quantity();
                }
                if ($qty_sum === 0) continue;
                $status = $order->get_status();
                if ($status === 'refunded' || $status === 'cancelled') {
                    $type = 'restore';
                } elseif (
                    in_array($status, ['on-account'], true) ||
                    (method_exists($order, 'is_paid') ? $order->is_paid() : in_array($status, ['processing', 'completed'], true))
                ) {
                    $type = 'sale';
                } else {
                    continue;
                }
                $this->write_movement_row([
                    'product_id' => $id,
                    'order_id' => $order->get_id(),
                    'movement_type' => $type,
                    'qty' => ($type === 'sale' ? -abs((int) $qty_sum) : abs((int) $qty_sum)),
                    'qoh_after' => null,
                    'customer_name' => trim($order->get_formatted_billing_full_name() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'source' => 'rebuild_product',
                    'created_at' => $created_str,
                ]);
                $written++;
            }
        }
        return rest_ensure_response(['ok' => true, 'written' => (int) $written]);
    }

    // Hook: log product stock set (minimal)
    public function erp_on_product_set_stock($product): void {
        if (!$this->is_erp_enabled()) return;
        if (!$product instanceof \WC_Product) return;
        if (!$product->managing_stock()) return;
        $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $new_qoh = (int) $product->get_stock_quantity();
        $this->erp_log_event('product_set_stock', [
            'product_id' => $product_id,
            'qoh' => $new_qoh,
            'source' => 'hook',
        ]);
        // Optional: persist to movements when allowed
        if ($this->is_erp_persist_enabled()) {
            try {
                $created = current_time('mysql');
                $this->write_movement_row([
                    'product_id' => $product_id,
                    'order_id' => null,
                    'movement_type' => 'set_stock',
                    'qty' => 0,
                    'qoh_after' => $new_qoh,
                    'customer_name' => null,
                    'source' => 'set_stock',
                    'created_at' => $created,
                ]);
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    // Hook: log order stock reduction (sales) as one consolidated event
    public function erp_on_reduce_order_stock($order): void {
        if (!$this->is_erp_enabled()) return;
        if (!$order instanceof \WC_Order) return;
        $items_payload = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) continue;
            $pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $qty = (int) $item->get_quantity();
            $items_payload[] = [
                'product_id' => (int) $pid,
                'qty' => -abs($qty),
                'sku' => (string) $product->get_sku(),
            ];
        }
        $this->erp_log_event('reduce_order_stock', [
            'order_id' => $order->get_id(),
            'status' => $order->get_status(),
            'customer_id' => $order->get_customer_id(),
            'customer_name' => trim($order->get_formatted_billing_full_name() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'items' => $items_payload,
            'source' => 'hook',
        ]);
        if ($this->is_erp_persist_enabled()) {
            try {
                $created = $order->get_date_created();
                $created_str = (is_object($created) && method_exists($created, 'getTimestamp')) ? date_i18n('Y-m-d H:i:s', $created->getTimestamp()) : current_time('mysql');
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if (!$product instanceof \WC_Product) continue;
                    $pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
                    $qty = -abs((int) $item->get_quantity());
                    $this->write_movement_row([
                        'product_id' => $pid,
                        'order_id' => $order->get_id(),
                        'movement_type' => 'sale',
                        'qty' => $qty,
                        'qoh_after' => null,
                        'customer_name' => trim($order->get_formatted_billing_full_name() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                        'source' => 'order',
                        'created_at' => $created_str,
                    ]);
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    // Hook: log order stock restoration (refund/cancel) as one consolidated event
    public function erp_on_restore_order_stock($order): void {
        if (!$this->is_erp_enabled()) return;
        if (!$order instanceof \WC_Order) return;
        $items_payload = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) continue;
            $pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $qty = (int) $item->get_quantity();
            $items_payload[] = [
                'product_id' => (int) $pid,
                'qty' => abs($qty),
                'sku' => (string) $product->get_sku(),
            ];
        }
        $this->erp_log_event('restore_order_stock', [
            'order_id' => $order->get_id(),
            'status' => $order->get_status(),
            'customer_id' => $order->get_customer_id(),
            'customer_name' => trim($order->get_formatted_billing_full_name() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'items' => $items_payload,
            'source' => 'hook',
        ]);
        if ($this->is_erp_persist_enabled()) {
            try {
                $created = $order->get_date_created();
                $created_str = (is_object($created) && method_exists($created, 'getTimestamp')) ? date_i18n('Y-m-d H:i:s', $created->getTimestamp()) : current_time('mysql');
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if (!$product instanceof \WC_Product) continue;
                    $pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
                    $qty = abs((int) $item->get_quantity());
                    $this->write_movement_row([
                        'product_id' => $pid,
                        'order_id' => $order->get_id(),
                        'movement_type' => 'restore',
                        'qty' => $qty,
                        'qoh_after' => null,
                        'customer_name' => trim($order->get_formatted_billing_full_name() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                        'source' => 'order',
                        'created_at' => $created_str,
                    ]);
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    private function parse_decimal($value): ?float {
        if (is_array($value)) {
            $value = array_first($value);
        }

        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        }

        if (function_exists('wc_format_decimal')) {
            $formatted = wc_format_decimal($value);

            if ($formatted === '' || $formatted === null) {
                return null;
            }

            return (float) $formatted;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $filtered = preg_replace('/[^0-9.\-]/', '', (string) $value);

        if ($filtered === '' || $filtered === '.' || $filtered === '-'
        ) {
            return null;
        }

        return (float) $filtered;
    }

    private function normalize_product_status($value): ?string {
        $status = sanitize_key((string) $value);

        return in_array($status, ['publish', 'draft', 'pending', 'private'], true) ? $status : null;
    }

    private function normalize_catalog_visibility($value): ?string {
        $visibility = sanitize_key((string) $value);

        return in_array($visibility, ['visible', 'catalog', 'search', 'hidden'], true) ? $visibility : null;
    }

    private function normalize_backorders($value): ?string {
        $backorders = sanitize_key((string) $value);

        return in_array($backorders, ['no', 'notify', 'yes'], true) ? $backorders : null;
    }

    private function normalize_tax_status($value): ?string {
        $tax_status = sanitize_key((string) $value);

        return in_array($tax_status, ['taxable', 'shipping', 'none'], true) ? $tax_status : null;
    }

    private function get_brand_options(): array {
        $taxonomies = array_filter([$this->get_active_brand_taxonomy()], 'taxonomy_exists');

        if (empty($taxonomies)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomies,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $options = [];

        foreach ($terms as $term) {
            $options[] = [
                'taxonomy' => $term->taxonomy,
                'slug'     => $term->slug,
                'name'     => $term->name,
            ];
        }

        return $options;
    }

    private function get_active_brand_taxonomy(): string {
        $taxonomies = array_values(array_unique(array_merge(
            ['product_brand'],
            (array) apply_filters(
                'hp_products_manager_brand_taxonomies',
                ['product_brand', 'yith_product_brand']
            )
        )));

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'fields'     => 'ids',
                'number'     => 1,
            ]);

            if (!is_wp_error($terms) && !empty($terms)) {
                return $taxonomy;
            }
        }

        foreach ($taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                return $taxonomy;
            }
        }

        return '';
    }

    private function product_image_url(WC_Product $product, string $size = 'thumbnail'): ?string {
        $image_id = (int) $product->get_image_id();
        if ($image_id > 0) {
            $image_url = wp_get_attachment_image_url($image_id, $size);
            if ($image_url) {
                return (string) $image_url;
            }
        }

        return $this->special_order_image_url($product);
    }

    private function special_order_image_url(WC_Product $product): ?string {
        $sku = strtoupper(trim((string) $product->get_sku()));
        if ($sku === 'SO-1') {
            return plugins_url('assets/images/special-order.svg', __FILE__);
        }
        if ($sku === 'SO-2') {
            return plugins_url('assets/images/special-order-shipping.svg', __FILE__);
        }

        return null;
    }

    private function get_product_brand_terms(int $product_id): array {
        $taxonomy = $this->get_active_brand_taxonomy();

        if (!$taxonomy) {
            return [];
        }

        $terms = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'all']);

        return is_wp_error($terms) ? [] : (array) $terms;
    }

    /**
     * REST: Get single product detail
     */
    public function rest_get_product_detail(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product) {
            return new \WP_Error('not_found', __('Product not found', 'hp-products-manager'), ['status' => 404]);
        }

        $terms = $this->get_product_brand_terms($id);
        return rest_ensure_response([
            'id'         => $product->get_id(),
            'name'       => $product->get_name(),
            'sku'        => $product->get_sku(),
            'price'      => ($product->get_price('edit') !== '' ? (float) $product->get_price('edit') : null),
            'sale_price' => ($product->get_sale_price('edit') !== '' ? (float) $product->get_sale_price('edit') : null),
            'status'     => $product->get_status(),
            'visibility' => $product->get_catalog_visibility(),
            'brands'     => $this->extract_term_slugs($terms),
            'categories' => $this->extract_term_slugs(wc_get_product_terms($id, 'product_cat', ['fields' => 'all'])),
            'tags'       => $this->extract_term_slugs(wc_get_product_terms($id, 'product_tag', ['fields' => 'all'])),
            'shipping_class' => $this->get_shipping_class_slug_for($id),
            'weight'     => $product->get_weight('edit'),
            'length'     => method_exists($product, 'get_length') ? $product->get_length('edit') : '',
            'width'      => method_exists($product, 'get_width') ? $product->get_width('edit') : '',
            'height'     => method_exists($product, 'get_height') ? $product->get_height('edit') : '',
            'cost'       => $this->get_strict_cost($id),
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'backorders' => $product->get_backorders(),
            // Dosing & Servings
            'serving_size'           => get_post_meta($id, 'serving_size', true),
            'servings_per_container' => get_post_meta($id, 'servings_per_container', true),
            'serving_form_unit'      => get_post_meta($id, 'serving_form_unit', true),
            'supplement_form'        => get_post_meta($id, 'supplement_form', true),
            'bottle_size_eu'         => get_post_meta($id, 'bottle_size_eu', true),
            'bottle_size_units_eu'   => get_post_meta($id, 'bottle_size_units_eu', true),
            'bottle_size_usa'        => get_post_meta($id, 'bottle_size_usa', true),
            'bottle_size_units_usa'  => get_post_meta($id, 'bottle_size_units_usa', true),
            // Ingredients & Mfg
            'ingredients'             => get_post_meta($id, 'ingredients', true),
            'ingredients_other'       => get_post_meta($id, 'ingredients_other', true),
            'potency'                 => get_post_meta($id, 'potency', true),
            'potency_units'           => get_post_meta($id, 'potency_units', true),
            'sku_mfr'                 => get_post_meta($id, 'sku_mfr', true),
            'manufacturer_acf'        => get_post_meta($id, 'manufacturer_acf', true),
            'country_of_manufacturer' => get_post_meta($id, 'country_of_manufacturer', true),
            // Instructions & Safety
            'how_to_use'      => get_post_meta($id, 'how_to_use', true),
            'cautions'        => get_post_meta($id, 'cautions', true),
            'recommended_use' => get_post_meta($id, 'recommended_use', true),
            'community_tips'  => get_post_meta($id, 'community_tips', true),
            // Expert Info
            'body_systems_organs' => (array) get_post_meta($id, 'body_systems_organs', true),
            'traditional_function'=> get_post_meta($id, 'traditional_function', true),
            'chinese_energy'      => get_post_meta($id, 'chinese_energy', true),
            'callback_ayurvedic_energy' => get_post_meta($id, 'ayurvedic_energy', true), // Renamed to avoid confusion if needed
            'ayurvedic_energy'    => get_post_meta($id, 'ayurvedic_energy', true),
            'supplement_type'     => get_post_meta($id, 'supplement_type', true),
            'expert_article'      => get_post_meta($id, 'expert_article', true),
            'video'               => get_post_meta($id, 'video', true),
            'video_transcription' => get_post_meta($id, 'video_transcription', true),
            'slogan'              => get_post_meta($id, 'slogan', true),
            'aka_product_name'    => get_post_meta($id, 'aka_product_name', true),
            'description_long'    => get_post_meta($id, 'description_long', true),
            // Admin
            'product_type_hp' => get_post_meta($id, 'product_type_hp', true),
            'site_catalog'    => (array) get_post_meta($id, 'site_catalog', true),
            'image'      => $this->product_image_url($product),
            'image_id'   => $product->get_image_id() ?: null,
            'gallery_ids'=> method_exists($product, 'get_gallery_image_ids') ? $product->get_gallery_image_ids() : [],
            'gallery'    => (function() use ($product){ $out=[]; if (method_exists($product, 'get_gallery_image_ids')) { foreach ($product->get_gallery_image_ids() as $gid) { $out[] = ['id'=>$gid,'url'=> wp_get_attachment_image_url($gid,'thumbnail')]; } } return $out; })(),
            'editLink'   => admin_url('post.php?post=' . $id . '&action=edit'),
            'viewLink'   => get_permalink($id),
        ]);
    }

    /**
     * REST: Debug - return cost-related meta keys/values for a product
     */
    public function rest_get_product_meta_debug(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $all = get_post_meta($id);
        $filtered = [];
        foreach ($all as $k => $vals) {
            if (stripos($k, 'cog') !== false || stripos($k, 'cogs') !== false || stripos($k, 'cost') !== false || stripos($k, 'purchase') !== false) {
                $v = is_array($vals) ? array_last($vals) : $vals;
                $filtered[$k] = $v;
            }
        }
        return rest_ensure_response(['id' => $id, 'meta' => $filtered]);
    }

    /**
     * REST: Apply staged changes to a product
     */
    public function rest_apply_product_changes(WP_REST_Request $request) {
        try {
        $id = (int) $request['id'];
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product) {
            return new \WP_Error('not_found', __('Product not found', 'hp-products-manager'), ['status' => 404]);
        }

        $payload = json_decode($request->get_body(), true);
        $changes = is_array($payload) && isset($payload['changes']) && is_array($payload['changes']) ? $payload['changes'] : [];

        // Allowed fields only (explicit list to avoid accidental updates)
        $allowed = [
            'name','sku','price','sale_price','status','visibility',
            'short_description', 'tax_status', 'tax_class', 'sold_individually',
            'upsell_ids', 'crosssell_ids',
            'yoast_focuskw', 'yoast_title', 'yoast_metadesc',
            'brands','categories','tags','shipping_class',
            'weight','length','width','height','cost',
            'image_id','gallery_ids',
            'manage_stock', 'stock_quantity', 'backorders',
            // Dosing & Servings
            'serving_size', 'servings_per_container', 'serving_form_unit', 'supplement_form',
            'bottle_size_eu', 'bottle_size_units_eu', 'bottle_size_usa', 'bottle_size_units_usa',
            // Ingredients & Mfg
            'ingredients', 'ingredients_other', 'potency', 'potency_units', 'sku_mfr', 'manufacturer_acf', 'country_of_manufacturer',
            // Instructions & Safety
            'how_to_use', 'cautions', 'recommended_use', 'community_tips',
            // Expert Info
            'body_systems_organs', 'traditional_function', 'chinese_energy', 'ayurvedic_energy', 
            'supplement_type', 'expert_article', 'video', 'video_transcription', 'slogan', 'aka_product_name', 'description_long',
            // Admin
            'product_type_hp', 'site_catalog',
        ];
        $apply = array_intersect_key($changes, array_flip($allowed));

        if (empty($apply)) {
            return rest_ensure_response(['updated' => false, 'product' => []]);
        }

        if (isset($apply['name'])) {
            $product->set_name(wp_strip_all_tags((string) $apply['name']));
        }
        if (isset($apply['sku'])) {
            $sku = wc_clean((string) $apply['sku']);
            $product->set_sku($sku);
        }
        if (isset($apply['price'])) {
            $price = $this->parse_decimal($apply['price']);
            if ($price !== null) {
                $product->set_regular_price((string) $price);
                $product->set_price((string) $price);
            }
        }

        $pending_cost = null;
        if (isset($apply['cost'])) {
            $pending_cost = $this->parse_decimal($apply['cost']);
        }

        if (isset($apply['manage_stock'])) {
            $product->set_manage_stock(rest_sanitize_boolean($apply['manage_stock']));
        }
        if (isset($apply['stock_quantity'])) {
            $product->set_stock_quantity($apply['stock_quantity'] === '' ? null : (int) $apply['stock_quantity']);
        }
        if (isset($apply['backorders'])) {
            $backorders = $this->normalize_backorders($apply['backorders']);
            if ($backorders !== null) {
                $product->set_backorders($backorders);
            }
        }

        if (isset($apply['sale_price'])) {
            $sp = $this->parse_decimal($apply['sale_price']);
            $product->set_sale_price($sp !== null ? (string) $sp : '');
        }
        if (isset($apply['weight'])) {
            $w = $this->parse_decimal($apply['weight']);
            $product->set_weight($w !== null ? (string) $w : '');
        }
        if (isset($apply['length'])) {
            $v = $this->parse_decimal($apply['length']);
            if (method_exists($product, 'set_length')) $product->set_length($v !== null ? (string) $v : '');
        }
        if (isset($apply['width'])) {
            $v = $this->parse_decimal($apply['width']);
            if (method_exists($product, 'set_width')) $product->set_width($v !== null ? (string) $v : '');
        }
        if (isset($apply['height'])) {
            $v = $this->parse_decimal($apply['height']);
            if (method_exists($product, 'set_height')) $product->set_height($v !== null ? (string) $v : '');
        }
        if (isset($apply['status'])) {
            $status = $this->normalize_product_status($apply['status']);
            if ($status !== null) {
                $product->set_status($status);
            }
        }
        if (isset($apply['visibility'])) {
            $visibility = $this->normalize_catalog_visibility($apply['visibility']);
            if ($visibility !== null) {
                $product->set_catalog_visibility($visibility);
            }
        }
        if (isset($apply['short_description'])) {
            $product->set_short_description(wp_kses_post($apply['short_description']));
        }
        if (isset($apply['tax_status'])) {
            $tax_status = $this->normalize_tax_status($apply['tax_status']);
            if ($tax_status !== null) {
                $product->set_tax_status($tax_status);
            }
        }
        if (isset($apply['tax_class'])) {
            $product->set_tax_class(sanitize_key($apply['tax_class']));
        }
        if (isset($apply['sold_individually'])) {
            $product->set_sold_individually(rest_sanitize_boolean($apply['sold_individually']));
        }
        if (isset($apply['upsell_ids']) && is_array($apply['upsell_ids'])) {
            $product->set_upsell_ids(array_map('intval', $apply['upsell_ids']));
        }
        if (isset($apply['crosssell_ids']) && is_array($apply['crosssell_ids'])) {
            $product->set_cross_sell_ids(array_map('intval', $apply['crosssell_ids']));
        }
        if (isset($apply['yoast_focuskw'])) {
            update_post_meta($id, '_yoast_wpseo_focuskw', sanitize_text_field($apply['yoast_focuskw']));
        }
        if (isset($apply['yoast_title'])) {
            update_post_meta($id, '_yoast_wpseo_title', sanitize_text_field($apply['yoast_title']));
        }
        if (isset($apply['yoast_metadesc'])) {
            update_post_meta($id, '_yoast_wpseo_metadesc', sanitize_text_field($apply['yoast_metadesc']));
        }
        if (isset($apply['brands']) && is_array($apply['brands'])) {
            $slugs = array_values(array_filter(array_map('sanitize_title', $apply['brands'])));
            $brand_taxonomy = $this->get_active_brand_taxonomy();
            if ($brand_taxonomy) {
                wp_set_object_terms($id, $slugs, $brand_taxonomy, false);
            }
        }
        if (isset($apply['categories']) && is_array($apply['categories'])) {
            $slugs = array_values(array_filter(array_map('sanitize_title', $apply['categories'])));
            wp_set_object_terms($id, $slugs, 'product_cat', false);
        }
        if (isset($apply['tags']) && is_array($apply['tags'])) {
            $slugs = array_values(array_filter(array_map('sanitize_title', $apply['tags'])));
            wp_set_object_terms($id, $slugs, 'product_tag', false);
        }
        if (isset($apply['shipping_class'])) {
            $sc = sanitize_title((string) $apply['shipping_class']);
            wp_set_object_terms($id, $sc ? [$sc] : [], 'product_shipping_class', false);
        }
        if (isset($apply['image_id'])) {
            // Accept string or int; normalize
            $img = is_array($apply['image_id']) ? array_first($apply['image_id']) : $apply['image_id'];
            $img = (int) $img;
            if ($img > 0) { $product->set_image_id($img); } else { $product->set_image_id(''); }
        }
        if (isset($apply['gallery_ids'])) {
            $ids_in = $apply['gallery_ids'];
            if (is_string($ids_in)) {
                $ids_in = preg_split('/[,\s]+/', $ids_in, -1, PREG_SPLIT_NO_EMPTY);
            }
            if (!is_array($ids_in)) { $ids_in = []; }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids_in))));
            if (method_exists($product, 'set_gallery_image_ids')) { $product->set_gallery_image_ids($ids); }
            // Ensure legacy meta is consistent as some installs still read this directly
            update_post_meta($id, '_product_image_gallery', implode(',', $ids));
        }

        // ACF / Meta Fields handling
        $meta_fields = [
            'serving_size', 'servings_per_container', 'serving_form_unit', 'supplement_form',
            'bottle_size_eu', 'bottle_size_units_eu', 'bottle_size_usa', 'bottle_size_units_usa',
            'ingredients', 'ingredients_other', 'potency', 'potency_units', 'sku_mfr', 'manufacturer_acf', 'country_of_manufacturer',
            'how_to_use', 'cautions', 'recommended_use', 'community_tips',
            'traditional_function', 'chinese_energy', 'ayurvedic_energy', 
            'supplement_type', 'expert_article', 'video', 'video_transcription', 'slogan', 'aka_product_name', 'description_long',
            'product_type_hp',
        ];
        foreach ($meta_fields as $meta_key) {
            if (isset($apply[$meta_key])) {
                update_post_meta($id, $meta_key, wp_kses_post($apply[$meta_key]));
            }
        }
        // Serialized fields
        if (isset($apply['body_systems_organs'])) {
            $val = is_array($apply['body_systems_organs']) ? $apply['body_systems_organs'] : [];
            update_post_meta($id, 'body_systems_organs', $val);
        }
        if (isset($apply['site_catalog'])) {
            $val = is_array($apply['site_catalog']) ? $apply['site_catalog'] : [];
            update_post_meta($id, 'site_catalog', $val);
        }

        $product->save();

        if ($pending_cost !== null) {
            $this->update_cost_meta($id, $pending_cost);
        }

        $this->flush_metrics_cache();

        // Build fresh snapshot inline to avoid any WP_REST_Request quirks
        $terms_brand = $this->get_product_brand_terms($id);
        $snapshot = [
            'id'         => $product->get_id(),
            'name'       => $product->get_name(),
            'sku'        => $product->get_sku(),
            'price'      => ($product->get_price('edit') !== '' ? (float) $product->get_price('edit') : null),
            'sale_price' => ($product->get_sale_price('edit') !== '' ? (float) $product->get_sale_price('edit') : null),
            'status'     => $product->get_status(),
            'visibility' => $product->get_catalog_visibility(),
            'short_description' => $product->get_short_description('edit'),
            'tax_status' => $product->get_tax_status('edit'),
            'tax_class'  => $product->get_tax_class('edit'),
            'sold_individually' => $product->get_sold_individually('edit'),
            'upsell_ids' => $product->get_upsell_ids('edit'),
            'crosssell_ids' => $product->get_cross_sell_ids('edit'),
            'upsell_labels' => (object) $this->get_product_labels($product->get_upsell_ids('edit')),
            'crosssell_labels' => (object) $this->get_product_labels($product->get_cross_sell_ids('edit')),
            'yoast_focuskw' => get_post_meta($id, '_yoast_wpseo_focuskw', true),
            'yoast_title' => get_post_meta($id, '_yoast_wpseo_title', true),
            'yoast_metadesc' => get_post_meta($id, '_yoast_wpseo_metadesc', true),
            'brands'     => $this->extract_term_slugs($terms_brand),
            'categories' => $this->extract_term_slugs(wc_get_product_terms($id, 'product_cat', ['fields' => 'all'])),
            'tags'       => $this->extract_term_slugs(wc_get_product_terms($id, 'product_tag', ['fields' => 'all'])),
            'shipping_class' => $this->get_shipping_class_slug_for($id),
            'weight'     => $product->get_weight('edit'),
            'length'     => method_exists($product, 'get_length') ? $product->get_length('edit') : '',
            'width'      => method_exists($product, 'get_width') ? $product->get_width('edit') : '',
            'height'     => method_exists($product, 'get_height') ? $product->get_height('edit') : '',
            'cost'       => $this->get_strict_cost($id),
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'backorders' => $product->get_backorders(),
            // Dosing & Servings
            'serving_size'           => get_post_meta($id, 'serving_size', true),
            'servings_per_container' => get_post_meta($id, 'servings_per_container', true),
            'serving_form_unit'      => get_post_meta($id, 'serving_form_unit', true),
            'supplement_form'        => get_post_meta($id, 'supplement_form', true),
            'bottle_size_eu'         => get_post_meta($id, 'bottle_size_eu', true),
            'bottle_size_units_eu'   => get_post_meta($id, 'bottle_size_units_eu', true),
            'bottle_size_usa'        => get_post_meta($id, 'bottle_size_usa', true),
            'bottle_size_units_usa'  => get_post_meta($id, 'bottle_size_units_usa', true),
            // Ingredients & Mfg
            'ingredients'             => get_post_meta($id, 'ingredients', true),
            'ingredients_other'       => get_post_meta($id, 'ingredients_other', true),
            'potency'                 => get_post_meta($id, 'potency', true),
            'potency_units'           => get_post_meta($id, 'potency_units', true),
            'sku_mfr'                 => get_post_meta($id, 'sku_mfr', true),
            'manufacturer_acf'        => get_post_meta($id, 'manufacturer_acf', true),
            'country_of_manufacturer' => get_post_meta($id, 'country_of_manufacturer', true),
            // Instructions & Safety
            'how_to_use'      => get_post_meta($id, 'how_to_use', true),
            'cautions'        => get_post_meta($id, 'cautions', true),
            'recommended_use' => get_post_meta($id, 'recommended_use', true),
            'community_tips'  => get_post_meta($id, 'community_tips', true),
            // Expert Info
            'body_systems_organs' => (array) get_post_meta($id, 'body_systems_organs', true),
            'traditional_function'=> get_post_meta($id, 'traditional_function', true),
            'chinese_energy'      => get_post_meta($id, 'chinese_energy', true),
            'callback_ayurvedic_energy' => get_post_meta($id, 'ayurvedic_energy', true), // Renamed to avoid confusion if needed
            'ayurvedic_energy'    => get_post_meta($id, 'ayurvedic_energy', true),
            'supplement_type'     => get_post_meta($id, 'supplement_type', true),
            'expert_article'      => get_post_meta($id, 'expert_article', true),
            'video'               => get_post_meta($id, 'video', true),
            'video_transcription' => get_post_meta($id, 'video_transcription', true),
            'slogan'              => get_post_meta($id, 'slogan', true),
            'aka_product_name'    => get_post_meta($id, 'aka_product_name', true),
            'description_long'    => get_post_meta($id, 'description_long', true),
            // Admin
            'product_type_hp' => get_post_meta($id, 'product_type_hp', true),
            'site_catalog'    => (array) get_post_meta($id, 'site_catalog', true),
            'image'      => $this->product_image_url($product),
            'image_id'   => $product->get_image_id() ?: null,
            'gallery_ids'=> method_exists($product, 'get_gallery_image_ids') ? $product->get_gallery_image_ids() : [],
            'gallery'    => (function() use ($product){ $out=[]; if (method_exists($product, 'get_gallery_image_ids')) { foreach ($product->get_gallery_image_ids() as $gid) { $out[] = ['id'=>$gid,'url'=> wp_get_attachment_image_url($gid,'thumbnail')]; } } return $out; })(),
            'editLink'   => admin_url('post.php?post=' . $id . '&action=edit'),
            'viewLink'   => get_permalink($id),
        ];
        return rest_ensure_response($snapshot);
        } catch (\Throwable $e) {
            // Log rich context for debugging on staging
            $context = [
                'product_id' => isset($id) ? (int) $id : 0,
                'payload'    => isset($payload) ? $payload : null,
                'apply_keys' => isset($apply) ? array_keys($apply) : [],
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ];
            if (function_exists('wp_json_encode')) {
                error_log('[HP PM] apply_failed: ' . $e->getMessage() . ' | ctx=' . wp_json_encode($context));
            } else {
                error_log('[HP PM] apply_failed: ' . $e->getMessage());
            }
            // Surface failure as REST error instead of fatal 500 without message
            return new \WP_Error('apply_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * REST: Duplicate a product
     */
    public function rest_duplicate_product(WP_REST_Request $request) {
        try {
            $id = (int) $request['id'];
            $product = wc_get_product($id);
            if (!$product instanceof WC_Product) {
                return new \WP_Error('not_found', __('Product not found', 'hp-products-manager'), ['status' => 404]);
            }

            // Simple clone for now; handles meta and core data for simple products well
            $duplicate = clone $product;
            $duplicate->set_id(0);
            $duplicate->set_name($product->get_name() . ' (copy)');
            if ($product->get_sku()) {
                $duplicate->set_sku($product->get_sku() . '-1');
            }
            $duplicate->set_status('draft');
            $new_id = $duplicate->save();

            if (!$new_id) {
                throw new \Exception(__('Failed to save duplicated product', 'hp-products-manager'));
            }

            // Sync terms (taxonomies) which are not automatically copied by clone for a new ID
            $taxonomies = array_values(array_unique(array_filter([$this->get_active_brand_taxonomy(), 'product_cat', 'product_tag', 'product_shipping_class'])));
            foreach ($taxonomies as $tax) {
                // Use wp_get_object_terms directly on the ID
                $terms = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    // Force cast to integers to be safe
                    $term_ids = array_map('intval', $terms);
                    wp_set_object_terms($new_id, $term_ids, $tax);
                }
            }

            // Also ensure the product object is refreshed or meta is synced
            $duplicate = wc_get_product($new_id);
            if ($duplicate) {
                $duplicate->save(); // Trigger WC internal hooks
            }

            // Sync cost if present
            $cost = $this->get_strict_cost($id);
            if ($cost !== null) {
                $this->update_cost_meta($new_id, $cost);
            }

            return rest_ensure_response([
                'id' => $new_id,
                'url' => admin_url('admin.php?page=hp-products-manager-product&product_id=' . $new_id),
            ]);
        } catch (\Throwable $e) {
            return new \WP_Error('duplicate_failed', $e->getMessage(), ['status' => 500]);
        }
    }
    /**
     * Build a map of reserved quantities per product based on Processing orders.
     * If $limit_ids is provided, only those product IDs are considered (useful for response rows).
     *
     * @param array<int>|null $limit_ids
     * @return array<int,int>
     */
    private function get_reserved_quantities(?array $limit_ids = null): array {
        $map = [];

        $orders = wc_get_orders([
            'status' => ['processing'],
            'limit'  => 500, // Safety limit to avoid OOM on production
            'orderby'=> 'date',
            'order'  => 'DESC',
            'return' => 'objects',
        ]);

        $limit_lookup = null;
        if (is_array($limit_ids) && !empty($limit_ids)) {
            $limit_lookup = array_fill_keys(array_map('intval', $limit_ids), true);
        }

        if (!empty($orders)) {
            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();

                    if (!$product instanceof WC_Product) {
                        continue;
                    }

                    $product_id = $product->get_id();

                    // Map variations to parent product so the table (which lists parents) reflects total reserved
                    if ($product->is_type('variation')) {
                        $parent_id = $product->get_parent_id();
                        if ($parent_id) {
                            $product_id = $parent_id;
                        }
                    }

                    if ($limit_lookup !== null && !isset($limit_lookup[$product_id])) {
                        continue;
                    }

                    $qty = (int) $item->get_quantity();
                    $map[$product_id] = isset($map[$product_id]) ? $map[$product_id] + $qty : $qty;
                }
            }
        }

        return $map;
    }

    /**
     * Import known real Old2New QA packets once products exist on the site.
     */
    public function maybe_import_default_old2new_packets(): void {
        if (get_option(self::OLD2NEW_DEFAULT_IMPORT_OPTION) === 'yes') {
            return;
        }

        $defaults = [
            'NTI-O-Mega-Zen' => ['B22185'],
            'NTI-O-Mega-Zen-EPA' => ['B22185'],
            'WA-1650' => ['HD-NCMC30', 'HD-NCMC60', 'HD-NXMC2'],
        ];

        $created = 0;
        $complete = true;
        foreach ($defaults as $old_sku => $new_skus) {
            if ($this->find_old2new_packet_by_old_sku($old_sku) > 0) {
                continue;
            }

            $created_packet = $this->create_old2new_packet_from_skus($old_sku, $new_skus);
            $created += $created_packet ? 1 : 0;
            if (!$created_packet) {
                $complete = false;
            }
        }

        if ($complete || !function_exists('wc_get_product_id_by_sku')) {
            update_option(self::OLD2NEW_DEFAULT_IMPORT_OPTION, 'yes', false);
        }
    }

    /**
     * Migrate legacy product-level old2new_product_pairs into packet records once.
     */
    public function maybe_migrate_legacy_old2new_pairs(): void {
        if (get_option(self::OLD2NEW_LEGACY_MIGRATION_OPTION) === 'yes') {
            return;
        }

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => self::OLD2NEW_LEGACY_FIELD,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $groups = [];
        foreach ($query->posts as $product_id) {
            $rows = get_post_meta((int) $product_id, self::OLD2NEW_LEGACY_FIELD, true);
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $old_sku = $this->normalize_old2new_sku((string) ($row['old_sku'] ?? ''));
                $new_sku = $this->normalize_old2new_sku((string) ($row['new_sku'] ?? ''));
                if ($old_sku === '' || $new_sku === '') {
                    continue;
                }

                if (!isset($groups[$old_sku])) {
                    $groups[$old_sku] = [];
                }
                $groups[$old_sku][$new_sku] = $new_sku;
            }
        }

        foreach ($groups as $old_sku => $new_skus) {
            $this->create_old2new_packet_from_skus($old_sku, array_values($new_skus));
        }

        update_option(self::OLD2NEW_LEGACY_MIGRATION_OPTION, 'yes', false);
    }

    public function maybe_migrate_old2new_statuses(): void {
        if (get_option(self::OLD2NEW_STATUS_MIGRATION_OPTION) === 'yes') {
            return;
        }

        $query = new WP_Query([
            'post_type' => self::OLD2NEW_PACKET_CPT,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 500,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => '_hp_old2new_status',
                    'value' => ['replace', 'discontinue'],
                    'compare' => 'IN',
                ],
            ],
        ]);

        foreach ($query->posts as $packet_id) {
            $status = $this->sanitize_old2new_status(get_post_meta((int) $packet_id, '_hp_old2new_status', true));
            update_post_meta((int) $packet_id, '_hp_old2new_status', $status);
        }

        update_option(self::OLD2NEW_STATUS_MIGRATION_OPTION, 'yes', false);
    }

    private function create_old2new_packet_from_skus(string $old_sku, array $new_skus): bool {
        $old_product = $this->old2new_product_by_sku($old_sku);
        if (!$old_product instanceof WC_Product) {
            return false;
        }

        $old_sku = $this->normalize_old2new_sku((string) $old_product->get_sku());
        if ($old_sku === '' || $this->find_old2new_packet_by_old_sku($old_sku) > 0) {
            return false;
        }

        $new_ids = [];
        foreach ($new_skus as $new_sku) {
            $new_product = $this->old2new_product_by_sku((string) $new_sku);
            if ($new_product instanceof WC_Product) {
                $new_ids[] = (int) $new_product->get_id();
            }
        }

        if (empty($new_ids)) {
            return false;
        }

        $saved = $this->save_old2new_packet([
            'old_product_id' => (int) $old_product->get_id(),
            'new_product_ids' => $new_ids,
            'status' => 'basic_discontinue',
        ]);

        return is_array($saved);
    }

    private function old2new_old_sku_index(): array {
        if ($this->old2new_old_sku_index !== null) {
            return $this->old2new_old_sku_index;
        }

        $this->old2new_old_sku_index = [];
        $query = new WP_Query([
            'post_type' => self::OLD2NEW_PACKET_CPT,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 500,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($query->posts as $packet_id) {
            $sku = $this->normalize_old2new_sku((string) get_post_meta((int) $packet_id, '_hp_old2new_old_sku', true));
            if ($sku !== '') {
                $this->old2new_old_sku_index[$sku] = (int) $packet_id;
            }
        }

        return $this->old2new_old_sku_index;
    }

    private function old2new_is_old_product(WC_Product $product): bool {
        $sku = $this->normalize_old2new_sku((string) $product->get_sku());

        return $sku !== '' && isset($this->old2new_old_sku_index()[$sku]);
    }

    /**
     * Sold-out check for discontinued old products. Backorders are forced off
     * for them, so a managed product with zero quantity is sold out even if a
     * stale "onbackorder" stock status is still stored.
     */
    private function old2new_old_product_sold_out(WC_Product $product): bool {
        if ($product->managing_stock()) {
            return (int) $product->get_stock_quantity() <= 0;
        }

        return $product->get_stock_status() !== 'instock';
    }

    /**
     * In-stock check for a product summary array (mirrors
     * old2new_old_product_sold_out for contexts that only have the summary).
     */
    private function old2new_summary_in_stock(array $summary): bool {
        if (isset($summary['stock']) && $summary['stock'] !== null) {
            return (int) $summary['stock'] > 0;
        }

        return (string) ($summary['stock_status'] ?? '') === 'instock';
    }

    public function filter_old2new_backorders($backorders, $product = null) {
        if ($product instanceof WC_Product && $this->old2new_is_old_product($product)) {
            return 'no';
        }

        return $backorders;
    }

    public function filter_old2new_is_purchasable($purchasable, $product = null) {
        if (
            $purchasable
            && $product instanceof WC_Product
            && $this->old2new_is_old_product($product)
            && $this->old2new_old_product_sold_out($product)
        ) {
            return false;
        }

        return $purchasable;
    }

    public function filter_old2new_price_html($price_html, $product = null) {
        if (
            !is_admin()
            && $product instanceof WC_Product
            && $this->old2new_is_old_product($product)
            && $this->old2new_old_product_sold_out($product)
        ) {
            return '';
        }

        return $price_html;
    }

    public function filter_old2new_loop_add_to_cart_link($link, $product = null) {
        if (
            !is_admin()
            && $product instanceof WC_Product
            && $this->old2new_is_old_product($product)
            && $this->old2new_old_product_sold_out($product)
        ) {
            return '';
        }

        return $link;
    }

    private function old2new_current_product_id(int $explicit_id = 0): int {
        if ($explicit_id > 0) {
            return $explicit_id;
        }

        global $product;
        if ($product instanceof WC_Product) {
            return (int) $product->get_id();
        }

        $queried = function_exists('get_queried_object') ? get_queried_object() : null;
        if (is_object($queried) && isset($queried->ID) && ($queried->post_type ?? '') === 'product') {
            return (int) $queried->ID;
        }

        return function_exists('get_the_ID') ? (int) get_the_ID() : 0;
    }

    private function resolve_old2new_for_product_id(int $product_id): ?array {
        if ($product_id <= 0 || !function_exists('wc_get_product')) {
            return null;
        }

        $current_product = wc_get_product($product_id);
        if (!$current_product instanceof WC_Product) {
            return null;
        }

        $current_sku = $this->normalize_old2new_sku((string) $current_product->get_sku());
        if ($current_sku === '') {
            return null;
        }

        $old_match = $this->resolve_old2new_packet_for_old_sku($current_sku);
        if ($old_match) {
            return $old_match;
        }

        $new_match = $this->resolve_old2new_packet_for_new_sku($current_sku, $current_product);
        if ($new_match) {
            return $new_match;
        }

        return $this->resolve_legacy_old2new_for_product($current_product);
    }

    private function resolve_old2new_packet_for_old_sku(string $old_sku): ?array {
        $packet_id = $this->find_old2new_packet_by_old_sku($old_sku);
        $packet = $packet_id > 0 ? $this->old2new_packet_response($packet_id) : null;
        if (!$packet || empty($packet['old_product']) || empty($packet['new_products'])) {
            return null;
        }

        return [
            'state' => 'old',
            'oldProducts' => [$packet['old_product']],
            'newProducts' => $packet['new_products'],
            'packet' => $packet,
        ];
    }

    private function old2new_old_packet_for_product_id(int $product_id): ?array {
        if ($product_id <= 0 || !function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product) {
            return null;
        }

        $sku = $this->normalize_old2new_sku((string) $product->get_sku());
        if ($sku === '') {
            return null;
        }

        $packet_id = $this->find_old2new_packet_by_old_sku($sku);

        return $packet_id > 0 ? $this->old2new_packet_response($packet_id) : null;
    }

    private function old2new_badge_payload_for_product_id(int $product_id): ?array {
        $packet = $this->old2new_old_packet_for_product_id($product_id);
        if (!$packet || empty($packet['old_product']) || empty($packet['new_products'])) {
            return null;
        }

        return [
            'packet_id' => (int) $packet['id'],
            'text' => (string) ($packet['badge_text'] ?: $this->old2new_default_badge_text()),
            'url' => (string) ($packet['old_product']['permalink'] ?? get_permalink($product_id)),
            'status' => (string) $packet['status'],
            'target_product' => $packet['target_product'],
        ];
    }

    public function render_old2new_loop_badge(): void {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }

        $payload = $this->old2new_badge_payload_for_product_id((int) $product->get_id());
        if (!$payload) {
            return;
        }

        printf(
            '<a class="old2new-product-badge" href="%1$s" data-old2new-packet-id="%2$d">%3$s</a>',
            esc_url((string) $payload['url']),
            (int) $payload['packet_id'],
            esc_html((string) $payload['text'])
        );
    }

    public function filter_old2new_canonical_url($canonical, $post = null) {
        $product_id = 0;
        if ($post instanceof WP_Post && $post->post_type === 'product') {
            $product_id = (int) $post->ID;
        } elseif (function_exists('is_singular') && is_singular('product')) {
            $product_id = $this->old2new_current_product_id();
        }

        $packet = $this->old2new_old_packet_for_product_id($product_id);
        if (!$packet || $packet['status'] !== 'canonical' || empty($packet['target_product']['permalink'])) {
            return $canonical;
        }

        return esc_url_raw((string) $packet['target_product']['permalink']);
    }

    public function maybe_redirect_old2new_product(): void {
        if (
            is_admin()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (function_exists('wp_doing_cron') && wp_doing_cron())
            || (function_exists('is_preview') && is_preview())
            || !function_exists('is_singular')
            || !is_singular('product')
        ) {
            return;
        }

        $product_id = $this->old2new_current_product_id();
        $packet = $this->old2new_old_packet_for_product_id($product_id);
        if (!$packet || $packet['status'] !== 'hard_redirect' || empty($packet['target_product']['permalink'])) {
            return;
        }

        $target_url = (string) $packet['target_product']['permalink'];
        $current_url = function_exists('get_permalink') ? (string) get_permalink($product_id) : '';
        if ($target_url === '' || $target_url === $current_url) {
            return;
        }

        // Tag the redirect so the new-product page shows the replacement
        // banner to this visitor (organic visitors never see it).
        $old_product_id = (int) ($packet['old_product']['id'] ?? $product_id);
        if ($old_product_id > 0) {
            $target_url = add_query_arg(self::OLD2NEW_REFERRAL_PARAM, $old_product_id, $target_url);
        }

        wp_safe_redirect($target_url, 301, 'Product Manager Old2New');
        exit;
    }

    private function resolve_old2new_packet_for_new_sku(string $new_sku, WC_Product $current_product): ?array {
        $query = new WP_Query([
            'post_type' => self::OLD2NEW_PACKET_CPT,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 25,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => '_hp_old2new_new_skus',
                    'value' => $new_sku,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        $old_products = [];
        $first_packet = null;
        foreach ($query->posts as $packet_id) {
            $packet = $this->old2new_packet_response((int) $packet_id);
            if ($packet && isset($packet['old_product']) && empty($packet['banner_expired'])) {
                $first_packet = $first_packet ?: $packet;
                $old_products[$packet['old_product']['sku']] = $packet['old_product'];
            }
        }

        if (empty($old_products)) {
            return null;
        }

        return [
            'state' => 'new',
            'oldProducts' => array_values($old_products),
            'newProducts' => [$this->old2new_product_summary($current_product)],
            'packet' => $first_packet,
        ];
    }

    private function resolve_legacy_old2new_for_product(WC_Product $current_product): ?array {
        $product_id = (int) $current_product->get_id();
        $current_sku = $this->normalize_old2new_sku((string) $current_product->get_sku());
        $rows = get_post_meta($product_id, self::OLD2NEW_LEGACY_FIELD, true);
        if (!is_array($rows)) {
            return null;
        }

        $old_products = [];
        $new_products = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $old_sku = $this->normalize_old2new_sku((string) ($row['old_sku'] ?? ''));
            $new_sku = $this->normalize_old2new_sku((string) ($row['new_sku'] ?? ''));
            if ($old_sku === '' || $new_sku === '') {
                continue;
            }

            if ($current_sku === $old_sku) {
                $new_product = $this->old2new_product_by_sku($new_sku);
                if ($new_product instanceof WC_Product) {
                    $new_products[$new_sku] = $this->old2new_product_summary($new_product);
                }
            }

            if ($current_sku === $new_sku) {
                $old_product = $this->old2new_product_by_sku($old_sku);
                if ($old_product instanceof WC_Product) {
                    $old_products[$old_sku] = $this->old2new_product_summary($old_product);
                }
            }
        }

        if (!empty($new_products)) {
            return [
                'state' => 'old',
                'oldProducts' => [$this->old2new_product_summary($current_product)],
                'newProducts' => array_values($new_products),
            ];
        }

        if (!empty($old_products)) {
            return [
                'state' => 'new',
                'oldProducts' => array_values($old_products),
                'newProducts' => [$this->old2new_product_summary($current_product)],
            ];
        }

        return null;
    }

    public function render_old2new_product_block($atts = []): string {
        $atts = shortcode_atts(
            [
                'product_id' => '',
                'class' => 'old2new-product-block',
            ],
            is_array($atts) ? $atts : [],
            'old2new_product_block'
        );

        $resolved = $this->resolve_old2new_for_product_id($this->old2new_current_product_id(absint($atts['product_id'])));
        if (!$resolved) {
            return '';
        }

        $this->enqueue_old2new_frontend_assets();

        $base_class = sanitize_html_class((string) $atts['class'], 'old2new-product-block');
        $state = (string) $resolved['state'];
        $packet = is_array($resolved['packet'] ?? null) ? $resolved['packet'] : null;
        if ($state === 'new' && $packet && !empty($packet['banner_expired'])) {
            return '';
        }

        $old_products = array_values(array_filter($resolved['oldProducts'] ?? []));
        $new_products = array_values(array_filter($resolved['newProducts'] ?? []));
        if (empty($old_products) || empty($new_products)) {
            return '';
        }

        if ($state === 'new') {
            // Only visitors who followed an Old2New link (301 redirect or a
            // banner card click) see the replacement notice; organic visitors
            // to the new product are not shown confusing history.
            $referred_old_id = isset($_GET[self::OLD2NEW_REFERRAL_PARAM])
                ? absint(wp_unslash($_GET[self::OLD2NEW_REFERRAL_PARAM]))
                : 0;
            if ($referred_old_id <= 0) {
                return '';
            }

            $matched_old = array_values(array_filter($old_products, static function ($product) use ($referred_old_id): bool {
                return is_array($product) && (int) ($product['id'] ?? 0) === $referred_old_id;
            }));
            if (empty($matched_old)) {
                return '';
            }

            // Narrow the flow to the product the visitor actually came from.
            $old_products = $matched_old;
        }

        $highlight = count($new_products) > 1
            ? '<strong class="' . esc_attr($base_class) . '__highlight">' . esc_html__('new products', 'hp-products-manager') . '</strong>'
            : '<strong class="' . esc_attr($base_class) . '__highlight">' . esc_html__('new product', 'hp-products-manager') . '</strong>';

        if ($state === 'old') {
            if ($packet && !empty($packet['custom_old_message'])) {
                $message = esc_html($this->old2new_render_message_text((string) $packet['custom_old_message'], $old_products, $new_products));
            } elseif ($this->old2new_summary_in_stock($old_products[0])) {
                // Remaining stock is still sellable: say "being discontinued",
                // not "no longer available", so the copy doesn't contradict the
                // live price and add-to-cart right below the banner.
                $message = count($new_products) > 1
                    ? sprintf(__('This product is being discontinued &mdash; limited stock remains. Dr. Cousens recommends these %s going forward.', 'hp-products-manager'), $highlight)
                    : sprintf(__('This product is being discontinued &mdash; limited stock remains. Dr. Cousens recommends this %s going forward.', 'hp-products-manager'), $highlight);
            } else {
                $message = count($new_products) > 1
                    ? sprintf(__('This product is no longer available. Follow Dr. Cousens\' recommendations for these %s.', 'hp-products-manager'), $highlight)
                    : sprintf(__('This product is no longer available. Follow Dr. Cousens\' recommendation for this %s.', 'hp-products-manager'), $highlight);
            }
            $new_clickable = true;
        } else {
            $message = $packet && !empty($packet['custom_new_message'])
                ? esc_html($this->old2new_render_message_text((string) $packet['custom_new_message'], $old_products, $new_products))
                : (count($old_products) > 1
                    ? esc_html__('This product is now replacing these previous products.', 'hp-products-manager')
                    : esc_html__('This product is now replacing the previous product.', 'hp-products-manager'));
            $new_clickable = false;
        }

        $link_ref_id = $state === 'old' ? (int) ($old_products[0]['id'] ?? 0) : 0;
        $recommended_id = 0;
        if ($state === 'old' && count($new_products) > 1 && $packet && !empty($packet['target_product']['id'])) {
            $recommended_id = (int) $packet['target_product']['id'];
        }

        return sprintf(
            '<section class="%1$s %1$s--%2$s" data-old2new-state="%2$s" aria-label="%3$s"><div class="%1$s__copy"><p class="%1$s__message">%4$s</p></div>%5$s</section>',
            esc_attr($base_class),
            esc_attr($state),
            esc_attr__('Product replacement notice', 'hp-products-manager'),
            wp_kses_post($message),
            $this->render_old2new_flow($old_products, $new_products, $new_clickable, $base_class, $link_ref_id, $recommended_id)
        );
    }

    public function enqueue_old2new_frontend_assets(): void {
        $style_path = plugin_dir_path(__FILE__) . 'assets/css/old2new-product-block.css';
        if (file_exists($style_path)) {
            wp_enqueue_style(
                self::HANDLE . '-old2new-product-block',
                plugin_dir_url(__FILE__) . 'assets/css/old2new-product-block.css',
                [],
                self::VERSION
            );
        }

        // HP-Zen owns FiboSearch/autocomplete presentation and consumes the
        // Old2New badge REST endpoint directly inside its scoped search bridge.
    }

    private function render_old2new_flow(array $old_products, array $new_products, bool $new_clickable, string $block_class, int $link_ref_id = 0, int $recommended_id = 0): string {
        return sprintf(
            '<div class="%1$s__flow"><div class="%1$s__column %1$s__column--old">%2$s</div><span class="%1$s__arrow" aria-hidden="true">&rarr;</span><div class="%1$s__column %1$s__column--new">%3$s</div></div>',
            esc_attr($block_class),
            $this->render_old2new_product_cards($old_products, 'old', false),
            $this->render_old2new_product_cards($new_products, 'new', $new_clickable, $link_ref_id, $recommended_id)
        );
    }

    private function render_old2new_product_cards(array $products, string $role, bool $clickable, int $link_ref_id = 0, int $recommended_id = 0): string {
        $cards = [];
        foreach ($products as $product) {
            if (is_array($product) && !empty($product['name'])) {
                $recommended = $recommended_id > 0 && (int) ($product['id'] ?? 0) === $recommended_id;
                $cards[] = $this->render_old2new_product_card($product, $role, $clickable, $link_ref_id, $recommended);
            }
        }

        return '<span class="old2new-product-block__cards">' . implode('', $cards) . '</span>';
    }

    private function render_old2new_product_card(array $product, string $role, bool $clickable, int $link_ref_id = 0, bool $recommended = false): string {
        $base_class = 'old2new-product-card';
        $classes = $base_class . ' ' . $base_class . '--' . sanitize_html_class($role);
        $permalink = (string) ($product['permalink'] ?? '');
        if ($clickable && $link_ref_id > 0 && $permalink !== '') {
            // Tag Old2New referral clicks so the new-product page knows the
            // visitor followed a replacement link (the banner only shows then).
            $permalink = add_query_arg(self::OLD2NEW_REFERRAL_PARAM, $link_ref_id, $permalink);
        }
        $tag = $clickable ? 'a' : 'span';
        $href = $clickable ? ' href="' . esc_url($permalink) . '"' : '';
        $cta = $clickable ? sprintf('<span class="%1$s__cta" aria-hidden="true">&rarr;</span>', esc_attr($base_class)) : '';
        if ($clickable) {
            $classes .= ' ' . $base_class . '--clickable';
        }
        $flag = $recommended
            ? sprintf('<span class="%1$s__flag">%2$s</span>', esc_attr($base_class), esc_html__('Recommended', 'hp-products-manager'))
            : '';

        return sprintf(
            '<%1$s class="%2$s"%3$s data-old2new-product-role="%4$s">%5$s<span class="%6$s__body"><span class="%6$s__title">%7$s</span>%8$s</span>%9$s</%1$s>',
            $tag,
            esc_attr($classes),
            $href,
            esc_attr($role),
            $this->render_old2new_thumb($product, $base_class),
            esc_attr($base_class),
            esc_html((string) ($product['name'] ?? '')),
            $flag,
            $cta
        );
    }

    private function render_old2new_thumb(array $product, string $base_class): string {
        $name = (string) ($product['name'] ?? '');
        $image = '';

        if (!empty($product['image_id']) && function_exists('wp_get_attachment_image')) {
            $image = (string) wp_get_attachment_image((int) $product['image_id'], 'woocommerce_thumbnail', false, [
                'class' => $base_class . '__image',
                'alt' => $name,
            ]);
        }

        if ($image === '' && !empty($product['image'])) {
            $image = sprintf(
                '<img class="%1$s__image" src="%2$s" alt="%3$s" loading="lazy" decoding="async" />',
                esc_attr($base_class),
                esc_url((string) $product['image']),
                esc_attr($name)
            );
        }

        if ($image === '') {
            $image = sprintf(
                '<span class="%1$s__placeholder" aria-hidden="true">%2$s</span>',
                esc_attr($base_class),
                esc_html($this->old2new_initials($name))
            );
        }

        return sprintf('<span class="%1$s__thumb">%2$s</span>', esc_attr($base_class), $image);
    }

    private function old2new_initials(string $name): string {
        $words = preg_split('/\s+/', trim(wp_strip_all_tags($name))) ?: [];
        $letters = '';
        foreach (array_slice(array_filter($words), 0, 2) as $word) {
            $letters .= strtoupper(substr((string) $word, 0, 1));
        }

        return $letters !== '' ? $letters : 'HP';
    }

    /**
     * Plugin activation: install or upgrade legacy ERP schema once.
     */
    public static function on_activation(): void {
        self::instance()->maybe_install_tables(true);
    }

    /**
     * Plugin uninstall cleanup: drop custom tables and remove options.
     */
    public static function on_uninstall(): void {
        global $wpdb;
        $elog = $wpdb->prefix . 'hp_pm_event_log';
        $mov  = $wpdb->prefix . 'hp_pm_movements';
        $state= $wpdb->prefix . 'hp_pm_state';
        $wpdb->query("DROP TABLE IF EXISTS {$elog}");
        $wpdb->query("DROP TABLE IF EXISTS {$mov}");
        $wpdb->query("DROP TABLE IF EXISTS {$state}");
        delete_option('hp_pm_erp_schema_version');
        delete_option('hp_pm_rebuild_all_state');
        // Best-effort clear cache group
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
        }
    }

    /**
     * REST callback: Perform SEO audit for a product.
     */
    public function rest_get_product_seo_audit(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $product = wc_get_product($id);
        if (!$product) {
            return new \WP_Error('not_found', __('Product not found', 'hp-products-manager'), ['status' => 404]);
        }

        $focus_kw = get_post_meta($id, '_yoast_wpseo_focuskw', true);
        $title = get_post_meta($id, '_yoast_wpseo_title', true) ?: $product->get_name();
        $desc_short = $product->get_short_description();
        $desc_long = get_post_meta($id, 'description_long', true) ?: $product->get_description();
        $meta_desc = get_post_meta($id, '_yoast_wpseo_metadesc', true);

        $results = [];

        // 1. Focus Keyword exists
        if (empty($focus_kw)) {
            $results[] = ['level' => 'error', 'msg' => __('No focus keyword set.', 'hp-products-manager')];
        } else {
            $kw = strtolower($focus_kw);

            // 2. Keyword in title
            if (strpos(strtolower($title), $kw) === false) {
                $results[] = ['level' => 'warning', 'msg' => sprintf(__('Focus keyword "%s" not found in SEO title.', 'hp-products-manager'), $focus_kw)];
            } else {
                $results[] = ['level' => 'good', 'msg' => __('Focus keyword found in SEO title.', 'hp-products-manager')];
            }

            // 3. Keyword in descriptions
            $in_short = strpos(strtolower($desc_short), $kw) !== false;
            $in_long = strpos(strtolower($desc_long), $kw) !== false;
            if (!$in_short && !$in_long) {
                $results[] = ['level' => 'warning', 'msg' => __('Focus keyword not found in product descriptions.', 'hp-products-manager')];
            } else {
                $results[] = ['level' => 'good', 'msg' => __('Focus keyword found in product descriptions.', 'hp-products-manager')];
            }
        }

        // 4. Meta description length
        $meta_len = strlen($meta_desc);
        if ($meta_len === 0) {
            $results[] = ['level' => 'error', 'msg' => __('No meta description set.', 'hp-products-manager')];
        } elseif ($meta_len < 120) {
            $results[] = ['level' => 'warning', 'msg' => __('Meta description is too short (less than 120 chars).', 'hp-products-manager')];
        } elseif ($meta_len > 160) {
            $results[] = ['level' => 'warning', 'msg' => __('Meta description is too long (more than 160 chars).', 'hp-products-manager')];
        } else {
            $results[] = ['level' => 'good', 'msg' => __('Meta description length is good.', 'hp-products-manager')];
        }

        // 5. Content length
        $content_len = str_word_count(strip_tags($desc_long));
        if ($content_len < 300) {
            $results[] = ['level' => 'warning', 'msg' => sprintf(__('Long description is short (%d words). Aim for 300+.', 'hp-products-manager'), $content_len)];
        } else {
            $results[] = ['level' => 'good', 'msg' => __('Content length is good.', 'hp-products-manager')];
        }

        return rest_ensure_response([
            'id' => $id,
            'results' => $results,
            'overall' => (function() use ($results) {
                $levels = array_column($results, 'level');
                if (in_array('error', $levels)) return 'bad';
                if (in_array('warning', $levels)) return 'ok';
                return 'good';
            })()
        ]);
    }
}

// Register uninstall hook to fully purge plugin data when removed
register_activation_hook(__FILE__, ['HP_Products_Manager', 'on_activation']);
register_uninstall_hook(__FILE__, ['HP_Products_Manager', 'on_uninstall']);

HP_Products_Manager::instance();
