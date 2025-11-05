<?php
/**
 * Plugin Name: Products Manager
 * Description: Adds a persistent blue Products shortcut to the WordPress admin toolbar.
 * Author: Holistic People Dev Team
 * Version: 0.1.1
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
    const VERSION = '0.1.1';
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
        add_action('admin_bar_menu', [$this, 'register_toolbar_button'], 95);
    }

    /**
     * Load admin CSS for the toolbar button.
     */
    public function enqueue_admin_assets(): void {
        if (!current_user_can('edit_products')) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            self::VERSION
        );
    }

    /**
     * Add the Products shortcut to the admin toolbar.
     *
     * @param \WP_Admin_Bar $admin_bar Admin bar instance.
     */
    public function register_toolbar_button(\WP_Admin_Bar $admin_bar): void {
        if (!is_admin_bar_showing() || !current_user_can('edit_products')) {
            return;
        }

        $admin_bar->add_node([
            'id'     => 'hp-products-manager',
            'title'  => esc_html__('Products', 'hp-products-manager'),
            'href'   => admin_url('edit.php?post_type=product'),
            'parent' => 'root-default',
            'meta'   => [
                'class'    => 'hp-products-button',
                'title'    => esc_attr__('Go to Products', 'hp-products-manager'),
            ],
        ]);
    }
}

HP_Products_Manager::instance();
