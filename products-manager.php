<?php
/**
 * Plugin Name: Products Manager
 * Description: Adds a persistent blue Products shortcut after the Create New Order button in the admin top actions.
 * Author: Holistic People Dev Team
 * Version: 0.1.3
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
    const VERSION = '0.1.3';
    const HANDLE  = 'hp-products-manager';

    /**
     * Track whether we injected into the toolbar.
     *
     * @var bool
     */
    private $toolbar_button_added = false;

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
        add_action('admin_bar_menu', [$this, 'remove_default_view_link'], 120);
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

        $reference    = $admin_bar->get_node('eao-create-new-order');
        $parent       = $reference && !empty($reference->parent) ? $reference->parent : 'root-default';
        $position     = $reference && isset($reference->meta['position'])
            ? (int) $reference->meta['position'] + 1
            : 31;

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

        $this->toolbar_button_added = true;
    }

    /**
     * Remove generic "View" toolbar node so only the Products button shows.
     *
     * @param \WP_Admin_Bar $admin_bar Toolbar instance.
     */
    public function remove_default_view_link(\WP_Admin_Bar $admin_bar): void {
        if (!$this->toolbar_button_added) {
            return;
        }

        $candidate_ids = [
            'view',
            'view-site',
            'view-product',
            'view-page',
            'view-post',
        ];

        foreach ($candidate_ids as $candidate) {
            if ($admin_bar->get_node($candidate)) {
                $admin_bar->remove_node($candidate);
            }
        }
    }
}

HP_Products_Manager::instance();
