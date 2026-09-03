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
     * Normalize the list-screen request before Admin Columns or FiboSearch
     * builds query arguments, then retain query-level guards for integrations
     * that create or adjust product queries later in the request.
     */
    public static function register(): void {
        add_action('admin_init', [self::class, 'recover_product_list_request'], 0);
        add_action('pre_get_posts', [self::class, 'recover_product_query'], 0);
        add_action('pre_get_posts', [self::class, 'recover_product_query'], PHP_INT_MAX);
    }

    /**
     * Convert the native Products-list request before search plugins consume it.
     *
     * Admin Columns can resolve the submitted term into `post__in` before the
     * main WP_Query reaches `pre_get_posts`. Normalizing the narrowly scoped GET
     * request during `admin_init` gives every downstream search integration the
     * intended English term while leaving other admin screens untouched.
     */
    public static function recover_product_list_request(): void {
        if (!is_admin()
            || ($GLOBALS['pagenow'] ?? '') !== 'edit.php'
            || !function_exists('current_user_can')
            || !current_user_can('edit_products')
            || !isset($_GET['post_type'], $_GET['s'])
        ) {
            return;
        }

        $post_type = sanitize_key(wp_unslash((string) $_GET['post_type']));
        if ($post_type !== 'product') {
            return;
        }

        $search = sanitize_text_field(wp_unslash((string) $_GET['s']));
        $converted = self::converted_search_term($search);
        if ($converted === '' || $converted === $search) {
            return;
        }

        $_GET['s'] = $converted;
        $_REQUEST['s'] = $converted;
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
        $converted = self::converted_search_term($search);

        if ($converted !== '' && $converted !== $search) {
            $query->set('s', $converted);
        }
    }

    /**
     * Return HP Core's converted fallback, or the literal term when unavailable.
     */
    private static function converted_search_term(string $search): string {
        if ($search === '' || !function_exists('\\HP_Core\\admin_search_keyboard_variants')) {
            return $search;
        }

        $variants = \HP_Core\admin_search_keyboard_variants($search);
        return isset($variants[1]) && is_string($variants[1])
            ? $variants[1]
            : $search;
    }
}
