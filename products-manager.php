<?php
/**
 * Plugin Name: Products Manager
 * Description: Admin-only helper plugin that surfaces a quick Products shortcut next to the Enhanced Admin Order Create button.
 * Author: Holistic People Dev Team
 * Version: 0.1.0
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
    const VERSION = '0.1.0';
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
    }

    /**
     * Load admin assets when on the Orders Summary experience.
     */
    public function enqueue_admin_assets(): void {
        if (!$this->should_activate_for_current_screen()) {
            return;
        }

        $style_path = plugin_dir_url(__FILE__) . 'assets/css/admin.css';
        $script_path = plugin_dir_url(__FILE__) . 'assets/js/admin.js';

        wp_enqueue_style(
            self::HANDLE,
            $style_path,
            [],
            self::VERSION
        );

        wp_enqueue_script(
            self::HANDLE,
            $script_path,
            ['jquery'],
            self::VERSION,
            true
        );

        wp_localize_script(
            self::HANDLE,
            'HPProductsManager',
            [
                'productsUrl' => admin_url('edit.php?post_type=product'),
                'buttonLabel' => esc_html__('Products', 'hp-products-manager'),
            ]
        );
    }

    /**
     * Determine if the current screen should load the plugin assets.
     *
     * @return bool
     */
    private function should_activate_for_current_screen(): bool {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && strpos((string) $screen->id, 'orders-summary') !== false) {
            return true;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return $page === 'orders-summary';
    }
}

HP_Products_Manager::instance();
