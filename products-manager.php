<?php
/**
 * Plugin Name: Products Manager
 * Description: Adds a persistent blue Products shortcut after the Create New Order button in the admin top actions.
 * Author: Holistic People Dev Team
 * Version: 0.1.4
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
    const VERSION = '0.1.4';
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
    }

    /**
     * Load admin CSS for the Products button.
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
     * Inject Products button into the admin toolbar.
     *
     * @param \WP_Admin_Bar $admin_bar Toolbar instance.
     */
    public function maybe_add_toolbar_button(\WP_Admin_Bar $admin_bar): void {
        if (!is_admin_bar_showing() || !current_user_can('edit_products')) {
            return;
        }

        // Remove native view links to avoid duplication.
        $this->remove_default_view_links($admin_bar);

        $reference = $admin_bar->get_node('eao-create-new-order');
        $parent    = 'top-secondary'; // default secondary toolbar
        $position  = 60;              // near other action buttons

        if ($reference) {
            $parent   = $reference->parent ?: 'top-secondary';
            $position = isset($reference->meta['position'])
                ? (int) $reference->meta['position'] + 1
                : $position;
        }

        // Ensure we don't duplicate if already added.
        if ($admin_bar->get_node('hp-products-manager')) {
            $admin_bar->remove_node('hp-products-manager');
        }

        $admin_bar->add_node([
            'id'     => 'hp-products-manager',
            'title'  => esc_html__('Products', 'hp-products-manager'),
            'href'   => admin_url('edit.php?post_type=product'),
            'parent' => $parent,
            'meta'   => [
                'class'    => 'hp-products-toolbar-node',
                'title'    => esc_attr__('Go to Products', 'hp-products-manager'),
                'position' => $position,
            ],
        ]);
    }

    /**
     * Remove generic "View" toolbar nodes so only the Products button shows.
     *
     * @param \WP_Admin_Bar $admin_bar Toolbar instance.
     */
    private function remove_default_view_links(\WP_Admin_Bar $admin_bar): void {
        $candidate_ids = ['view', 'view-site', 'view-product', 'view-page', 'view-post'];

        foreach ($candidate_ids as $candidate) {
            if ($admin_bar->get_node($candidate)) {
                $admin_bar->remove_node($candidate);
            }
        }
    }
}

HP_Products_Manager::instance();
