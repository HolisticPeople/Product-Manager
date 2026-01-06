<?php
/**
 * Plugin Name: Products Manager
 * Description: Adds a persistent blue Products shortcut after the Create New Order button in the admin top actions.
 * Author: Holistic People Dev Team
 * Version: 0.5.83
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: hp-products-manager
 *
 * @package HP_Products_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

// Note: WP_Query, WP_Post, WP_REST_Request, WP_REST_Server, WC_Product are global classes
// No 'use' statements needed - they were causing PHP warnings

/**
 * Bootstrap class for the Products Manager plugin.
 */
final class HP_Products_Manager {
    private const REST_NAMESPACE = 'hp-products-manager/v1';

    const VERSION = '0.5.83';
    const HANDLE  = 'hp-products-manager';
    private const ALL_LOAD_THRESHOLD = 2500; // safety fallback if too many products
    private const METRICS_CACHE_KEY = 'metrics';
    private const CACHE_GROUP       = 'hp_products_manager';
    private const METRICS_TTL       = 60; // 1 minute for fresher stats
    // Definitive cost meta key (locked)
    private const COST_META_KEY     = '_cogs_total_value';
    // ERP feature flag (enabled by default now)
    private const ERP_ENABLED       = true;

    private function is_erp_enabled(): bool {
        // Allow enabling via filter without editing plugin
        return (bool) apply_filters('hp_pm_erp_enabled', self::ERP_ENABLED);
    }

    private function is_erp_persist_enabled(): bool {
        // Separate flag for DB writes to movements/state (now ON by default, overrideable)
        return (bool) apply_filters('hp_pm_erp_persist_enabled', true);
    }

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
        // First risky step: automatically ensure tables exist on admin load (dbDelta is idempotent)
        add_action('admin_init', [$this, 'maybe_install_tables']);

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
                <div class="hp-pm-header-actions" style="display:none;">
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
                    'brands'     => $this->extract_term_slugs(wc_get_product_terms($product_id, 'yith_product_brand', ['fields' => 'all'])),
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
                    'image'      => $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : null,
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

    public function maybe_install_tables(): void {
        // Create/upgrade ERP tables if missing
        if (!current_user_can('manage_woocommerce')) return;
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

    public function rest_rebuild_all_start(WP_REST_Request $request) {
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
                    } elseif (method_exists($order, 'is_paid') ? $order->is_paid() : in_array($status, ['processing','completed'], true)) {
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
        return rest_ensure_response($this->get_rebuild_all_state());
    }

    public function rest_rebuild_all_abort(WP_REST_Request $request) {
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
                } elseif (method_exists($order, 'is_paid') ? $order->is_paid() : in_array($status, ['processing','completed'], true)) {
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
            $product->set_backorders(sanitize_key((string) $apply['backorders']));
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
            $taxonomies = ['yith_product_brand', 'product_cat', 'product_tag', 'product_shipping_class'];
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
    private function get_reserved_quantities(array $limit_ids = null): array {
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
        delete_option('hp_pm_rebuild_all_state');
        // Best-effort clear cache group
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
        }
    }
}

// Register uninstall hook to fully purge plugin data when removed
register_uninstall_hook(__FILE__, ['HP_Products_Manager', 'on_uninstall']);

HP_Products_Manager::instance();

