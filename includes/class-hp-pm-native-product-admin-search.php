<?php
/**
 * Keyboard-layout recovery for the native WooCommerce product list.
 *
 * @package HP_Products_Manager
 */

/**
 * Adapts native wp-admin product queries to HP Core's shared keyboard map.
 */
final class HP_PM_Native_Product_Admin_Search {
    /**
     * Normalize before search engines consume the term, then keep the late
     * guard for product-list extensions that adjust the query afterwards.
     */
    public static function register(): void {
        add_action('pre_get_posts', [self::class, 'recover_product_query'], 0);
        add_action('pre_get_posts', [self::class, 'recover_product_query'], PHP_INT_MAX);
    }

    /**
     * Convert a Hebrew-layout term only for authenticated admin product queries.
     *
     * Products do not use Hebrew text, so the converted QWERTY term is the
     * deterministic fallback for this surface. The early hook is required
     * because FiboSearch consumes and clears the search term before its own
     * product-ID query; the late hook remains a fail-safe for Admin Columns
     * and other product-list extensions.
     */
    public static function recover_product_query(object $query): void {
        if (!is_admin()
            || !function_exists('current_user_can')
            || !current_user_can('edit_products')
            || !method_exists($query, 'get')
            || !method_exists($query, 'set')
        ) {
            return;
        }

        $post_type = $query->get('post_type');
        $is_product_query = $post_type === 'product'
            || (is_array($post_type) && in_array('product', $post_type, true));
        if (!$is_product_query) {
            return;
        }

        $search = (string) $query->get('s');
        if ($search === '' || !function_exists('\\HP_Core\\admin_search_keyboard_variants')) {
            return;
        }

        $variants = \HP_Core\admin_search_keyboard_variants($search);
        $converted = isset($variants[1]) && is_string($variants[1])
            ? $variants[1]
            : $search;

        if ($converted !== '' && $converted !== $search) {
            $query->set('s', $converted);
        }
    }
}
