<?php
/**
 * Plugin Name: Products Manager
 * Description: Adds a persistent blue Products shortcut after the Create New Order button in the admin top actions.
 * Author: Holistic People Dev Team
 * Version: 0.5.5
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: hp-products-manager
 *
 * @package HP_Products_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

use WP_Query;
use WP_Post;
use WP_REST_Request;
use WP_REST_Server;
use WC_Product;

/**
 * Bootstrap class for the Products Manager plugin.
 */
final class HP_Products_Manager {
    private const REST_NAMESPACE = 'hp-products-manager/v1';

    const VERSION = '0.5.5';
    const HANDLE  = 'hp-products-manager';
    private const ALL_LOAD_THRESHOLD = 2500; // safety fallback if too many products
    private const METRICS_CACHE_KEY = 'metrics';
    private const CACHE_GROUP       = 'hp_products_manager';
    private const METRICS_TTL       = 60; // 1 minute for fresher stats
    private const COST_META_KEYS    = [
        'product_po_cost',
    ];

    /**
     * Cached map of reserved quantities per product for this request.
     * @var array<int,int>
     */
    private $reserved_quantities_map = [];

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
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        if (!is_admin()) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this, 'maybe_add_toolbar_button'], 80);
        add_action('admin_head', [$this, 'maybe_suppress_notices'], 0);
        add_action('in_admin_header', [$this, 'maybe_suppress_notices'], 0);
        add_action('admin_menu', [$this, 'register_admin_page'], 30);
        add_filter('admin_body_class', [$this, 'maybe_flag_body_class']);
        add_action('save_post_product', [$this, 'flush_metrics_cache'], 10, 1);
        add_action('deleted_post', [$this, 'maybe_flush_deleted_product_cache'], 10, 2);
        add_action('woocommerce_update_product', [$this, 'flush_metrics_cache'], 10, 1);
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
                'i18n'    => [
                    'loading'   => __('Loading products...', 'hp-products-manager'),
                    'loadError' => __('Unable to load products. Please try again.', 'hp-products-manager'),
                    'allBrands' => __('All brands', 'hp-products-manager'),
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
                <div class="hp-pm-header-actions">
                    <button class="button button-primary"><?php esc_html_e('Add Product', 'hp-products-manager'); ?></button>
                    <button class="button"><?php esc_html_e('Bulk Update', 'hp-products-manager'); ?></button>
                    <button class="button"><?php esc_html_e('Export CSV', 'hp-products-manager'); ?></button>
                </div>
            </header>

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
                update_object_term_cache($query->posts, ['yith_product_brand', 'product_visibility']);
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

        $reserved = isset($this->reserved_quantities_map[$product_id]) ? (int) $this->reserved_quantities_map[$product_id] : 0;
        $available = $stock_qty !== null ? ((int) $stock_qty - $reserved) : null;

        return [
            'id'           => $product_id,
            'image'        => $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : null,
            'name'         => $product->get_name(),
            'sku'          => $product->get_sku(),
            'cost'         => $cost,
            'price'        => $price,
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

        $taxonomies = (array) apply_filters(
            'hp_products_manager_brand_taxonomies',
            ['yith_product_brand']
        );

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
        $meta_keys = (array) apply_filters('hp_products_manager_cost_meta_keys', self::COST_META_KEYS);

        foreach ($meta_keys as $key) {
            $value = get_post_meta($product_id, $key, true);

            $parsed = $this->parse_decimal($value);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
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

    private function parse_decimal($value): ?float {
        if (is_array($value)) {
            $value = reset($value);
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

    private function get_brand_options(): array {
        $taxonomies = array_filter(['yith_product_brand'], 'taxonomy_exists');

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

    /**
     * Build a map of reserved quantities per product based on Processing orders.
     * If $limit_ids is provided, only those product IDs are considered (useful for response rows).
     *
     * @param array<int>|null $limit_ids
     * @return array<int,int>
     */
    private function get_reserved_quantities(array $limit_ids = null): array {
        $map = [];

        $orders = wc_get_orders([
            'status' => ['processing'],
            'limit'  => -1,
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
}

HP_Products_Manager::instance();

