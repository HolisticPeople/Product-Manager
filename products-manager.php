<?php
/**
 * Plugin Name: Products Manager
 * Description: Adds a persistent blue Products shortcut after the Create New Order button in the admin top actions.
 * Author: Holistic People Dev Team
 * Version: 0.5.23
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

    const VERSION = '0.5.23';
    const HANDLE  = 'hp-products-manager';
    private const ALL_LOAD_THRESHOLD = 2500; // safety fallback if too many products
    private const METRICS_CACHE_KEY = 'metrics';
    private const CACHE_GROUP       = 'hp_products_manager';
    private const METRICS_TTL       = 60; // 1 minute for fresher stats
    // Definitive cost meta key (locked)
    private const COST_META_KEY     = '_cogs_total_value';

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
        add_action('admin_menu', [$this, 'register_product_detail_page'], 30);
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
                'productUrlBase' => admin_url('admin.php?page=hp-products-manager-product&product_id='),
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
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $active_tab = in_array($active_tab, ['general', 'erp'], true) ? $active_tab : 'general';

        // Enqueue product detail assets and bootstrap data for JS
        $asset_base = plugin_dir_url(__FILE__) . 'assets/';
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
            [],
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
                    'brands'     => array_map(function ($t) { return $t->slug; }, (array) wc_get_product_terms($product_id, 'yith_product_brand', ['fields' => 'all'])),
                    'categories' => array_map(function ($t) { return $t->slug; }, (array) wc_get_product_terms($product_id, 'product_cat', ['fields' => 'all'])),
                    'tags'       => array_map(function ($t) { return $t->slug; }, (array) wc_get_product_terms($product_id, 'product_tag', ['fields' => 'all'])),
                    'shipping_class' => (function() use ($product_id) {
                        $terms = wc_get_product_terms($product_id, 'product_shipping_class', ['fields' => 'all']);
                        return !empty($terms) ? $terms[0]->slug : '';
                    })(),
                    'weight'     => $product->get_weight('edit'),
                    'length'     => method_exists($product, 'get_length') ? $product->get_length('edit') : '',
                    'width'      => method_exists($product, 'get_width') ? $product->get_width('edit') : '',
                    'height'     => method_exists($product, 'get_height') ? $product->get_height('edit') : '',
                    'cost'       => $this->get_strict_cost($product_id),
                    'image'      => $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : null,
                    'image_id'   => $product->get_image_id() ?: null,
                    'gallery_ids'=> method_exists($product, 'get_gallery_image_ids') ? $product->get_gallery_image_ids() : [],
                    'gallery'    => (function() use ($product){ $out=[]; if (method_exists($product, 'get_gallery_image_ids')) { foreach ($product->get_gallery_image_ids() as $gid) { $out[] = ['id'=>$gid,'url'=> wp_get_attachment_image_url($gid,'thumbnail')]; } } return $out; })(),
                    'editLink'   => admin_url('post.php?post=' . $product_id . '&action=edit'),
                    'viewLink'   => get_permalink($product_id),
                ],
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

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg(['page' => 'hp-products-manager-product', 'product_id' => $product_id, 'tab' => 'general'], admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General', 'hp-products-manager'); ?></a>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'hp-products-manager-product', 'product_id' => $product_id, 'tab' => 'erp'], admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'erp' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('ERP', 'hp-products-manager'); ?></a>
            </h2>

            <?php if ($active_tab === 'general') : ?>
                <div class="card hp-pm-card" style="max-width: 1200px;">
                    <div class="hp-pm-pd-header">
                        <div class="hp-pm-pd-image">
                            <img id="hp-pm-pd-image" src="" alt="" class="hp-pm-main-img">
                        </div>
                        <div id="hp-pm-pd-gallery" class="hp-pm-gallery"></div>
                        <div class="hp-pm-pd-links">
                            <a id="hp-pm-pd-edit" href="#" target="_blank" class="button"><?php esc_html_e('Open in WP Admin', 'hp-products-manager'); ?></a>
                            <a id="hp-pm-pd-view" href="#" target="_blank" class="button"><?php esc_html_e('View Product', 'hp-products-manager'); ?></a>
                        </div>
                    </div>

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
                        </table>

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

                    <div class="hp-pm-staging-actions">
                        <button id="hp-pm-stage-btn" class="button button-primary"></button>
                        <button id="hp-pm-apply-btn" class="button" disabled></button>
                        <button id="hp-pm-discard-btn" class="button-link"></button>
                    </div>

                    <div class="hp-pm-staged">
                        <h3 id="hp-pm-staged-title"></h3>
                        <table class="widefat fixed striped" id="hp-pm-staged-table" style="display:none;">
                            <thead><tr><th><?php esc_html_e('Field', 'hp-products-manager'); ?></th><th><?php esc_html_e('From', 'hp-products-manager'); ?></th><th><?php esc_html_e('To', 'hp-products-manager'); ?></th><th></th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            <?php else : ?>
                <div class="card" style="max-width: 1200px;">
                    <h2><?php esc_html_e('Stock Movements (mockup)', 'hp-products-manager'); ?></h2>
                    <p><?php esc_html_e('This tab will aggregate stock movements from HPOS Orders, ShipStation and manual adjustments. We will cache results per product and provide a Refresh button.', 'hp-products-manager'); ?></p>
                    <p><button class="button"><?php esc_html_e('Refresh Movements', 'hp-products-manager'); ?></button></p>
                    <table class="widefat fixed striped" style="margin-top: 8px;">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'hp-products-manager'); ?></th>
                            <th><?php esc_html_e('Type', 'hp-products-manager'); ?></th>
                            <th><?php esc_html_e('Qty', 'hp-products-manager'); ?></th>
                            <th><?php esc_html_e('Description', 'hp-products-manager'); ?></th>
                            <th><?php esc_html_e('Source', 'hp-products-manager'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i')); ?></td>
                            <td><?php esc_html_e('order', 'hp-products-manager'); ?></td>
                            <td>-1</td>
                            <td><?php esc_html_e('Shipment for order #000000 (example)', 'hp-products-manager'); ?></td>
                            <td>HPOS</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
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
        $margin_pct = null;
        if ($cost !== null && $price !== null && $price > 0) {
            $margin_pct = round((($price - (float) $cost) / $price) * 100, 1);
        }

        $reserved = isset($this->reserved_quantities_map[$product_id]) ? (int) $this->reserved_quantities_map[$product_id] : 0;
        $available = $stock_qty !== null ? ((int) $stock_qty - $reserved) : null;

        return [
            'id'           => $product_id,
            'image'        => $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : null,
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
            $value = reset($value);
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
     * REST: Get single product detail
     */
    public function rest_get_product_detail(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product) {
            return new \WP_Error('not_found', __('Product not found', 'hp-products-manager'), ['status' => 404]);
        }

        $terms = wc_get_product_terms($id, 'yith_product_brand', ['fields' => 'all']);
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
            'image'      => $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : null,
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
                $v = is_array($vals) ? end($vals) : $vals;
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
            'brands','categories','tags','shipping_class',
            'weight','length','width','height','cost',
            'image_id','gallery_ids',
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
        if (isset($apply['cost'])) {
            $cost = $this->parse_decimal($apply['cost']);
            if ($cost !== null) {
                $this->update_cost_meta($id, $cost);
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
            $status = sanitize_key((string) $apply['status']);
            $product->set_status($status);
        }
        if (isset($apply['visibility'])) {
            $vis = sanitize_key((string) $apply['visibility']);
            $product->set_catalog_visibility($vis);
        }
        if (isset($apply['brands']) && is_array($apply['brands'])) {
            $slugs = array_values(array_filter(array_map('sanitize_title', $apply['brands'])));
            if (taxonomy_exists('yith_product_brand')) {
                wp_set_object_terms($id, $slugs, 'yith_product_brand', false);
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
            $img = is_array($apply['image_id']) ? reset($apply['image_id']) : $apply['image_id'];
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

        $product->save();
        $this->flush_metrics_cache();

        // Build fresh snapshot inline to avoid any WP_REST_Request quirks
        $terms_brand = wc_get_product_terms($id, 'yith_product_brand', ['fields' => 'all']);
        $snapshot = [
            'id'         => $product->get_id(),
            'name'       => $product->get_name(),
            'sku'        => $product->get_sku(),
            'price'      => ($product->get_price('edit') !== '' ? (float) $product->get_price('edit') : null),
            'sale_price' => ($product->get_sale_price('edit') !== '' ? (float) $product->get_sale_price('edit') : null),
            'status'     => $product->get_status(),
            'visibility' => $product->get_catalog_visibility(),
            'brands'     => $this->extract_term_slugs($terms_brand),
            'categories' => $this->extract_term_slugs(wc_get_product_terms($id, 'product_cat', ['fields' => 'all'])),
            'tags'       => $this->extract_term_slugs(wc_get_product_terms($id, 'product_tag', ['fields' => 'all'])),
            'shipping_class' => $this->get_shipping_class_slug_for($id),
            'weight'     => $product->get_weight('edit'),
            'length'     => method_exists($product, 'get_length') ? $product->get_length('edit') : '',
            'width'      => method_exists($product, 'get_width') ? $product->get_width('edit') : '',
            'height'     => method_exists($product, 'get_height') ? $product->get_height('edit') : '',
            'cost'       => $this->get_strict_cost($id),
            'image'      => $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : null,
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

