<?php
/**
 * Plugin Name: Products Manager
 * Description: Adds a persistent blue Products shortcut after the Create New Order button in the admin top actions.
 * Author: Holistic People Dev Team
 * Version: 0.3.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: hp-products-manager
 *
 * @package HP_Products_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap class for the Products Manager plugin.
 */
final class HP_Products_Manager {
    const VERSION = '0.3.3';
    const HANDLE  = 'hp-products-manager';

    /**
     * Retrieve the singleton instance.
     *
     * @return HP_Products_Manager
     */
    public static function instance(): HP_Products_Manager {
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
        if (!is_admin()) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this, 'maybe_add_toolbar_button'], 80);
        add_action('admin_menu', [$this, 'register_admin_page'], 30);
    }

    /**
     * Load admin CSS for the Products button.
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

        if ($is_products_page) {
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
        }
    }

    /**
     * Inject Products button into the admin toolbar.
     *
     * @param \WP_Admin_Bar $admin_bar Toolbar instance.
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
     * Render the Products Manager interface (mock layout).
     */
    public function render_products_page(): void {
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');

        ?>
        <div class="wrap hp-products-manager-page">
            <header class="hp-pm-header">
                <div>
                    <h1><?php esc_html_e('Products Manager', 'hp-products-manager'); ?></h1>
                    <p class="hp-pm-version">
                        <?php
                        printf(
                            /* translators: %s version number */
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

            <section class="hp-pm-filters">
                <div class="hp-pm-filter-group">
                    <label>
                        <?php esc_html_e('Search', 'hp-products-manager'); ?>
                        <input type="search" placeholder="<?php esc_attr_e('Name or SKU…', 'hp-products-manager'); ?>">
                    </label>
                </div>
                <div class="hp-pm-filter-group">
                    <label>
                        <?php esc_html_e('Brand', 'hp-products-manager'); ?>
                        <select>
                            <option value=""><?php esc_html_e('All brands', 'hp-products-manager'); ?></option>
                            <option>Pure Encapsulations</option>
                            <option>Life Extension</option>
                            <option>Organic India</option>
                        </select>
                    </label>
                </div>
                <div class="hp-pm-filter-group">
                    <label>
                        <?php esc_html_e('Status', 'hp-products-manager'); ?>
                        <select>
                            <option value=""><?php esc_html_e('Any status', 'hp-products-manager'); ?></option>
                            <option><?php esc_html_e('Enabled', 'hp-products-manager'); ?></option>
                            <option><?php esc_html_e('Disabled', 'hp-products-manager'); ?></option>
                        </select>
                    </label>
                </div>
                <div class="hp-pm-filter-group">
                    <label>
                        <?php esc_html_e('Stock Range', 'hp-products-manager'); ?>
                        <input type="number" min="0" placeholder="0"> –
                        <input type="number" min="0" placeholder="999">
                    </label>
                </div>
                <div class="hp-pm-filter-actions">
                    <button class="button"><?php esc_html_e('Reset Filters', 'hp-products-manager'); ?></button>
                </div>
            </section>

            <section class="hp-pm-metrics">
                <div class="hp-pm-metric">
                    <span class="hp-pm-metric-label"><?php esc_html_e('Catalog', 'hp-products-manager'); ?></span>
                    <span class="hp-pm-metric-value">1,549</span>
                </div>
                <div class="hp-pm-metric">
                    <span class="hp-pm-metric-label"><?php esc_html_e('Low Stock', 'hp-products-manager'); ?></span>
                    <span class="hp-pm-metric-value hp-pm-metric-value--warning">82</span>
                </div>
                <div class="hp-pm-metric">
                    <span class="hp-pm-metric-label"><?php esc_html_e('Hidden', 'hp-products-manager'); ?></span>
                    <span class="hp-pm-metric-value hp-pm-metric-value--muted">67</span>
                </div>
                <div class="hp-pm-metric">
                    <span class="hp-pm-metric-label"><?php esc_html_e('Avg. Margin', 'hp-products-manager'); ?></span>
                    <span class="hp-pm-metric-value hp-pm-metric-value--success">48%</span>
                </div>
            </section>

            <section class="hp-pm-table">
                <div id="hp-products-table"></div>
            </section>
        </div>
        <?php
    }
}

HP_Products_Manager::instance();
