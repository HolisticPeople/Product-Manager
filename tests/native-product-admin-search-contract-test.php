<?php

declare(strict_types=1);

namespace HP_Core {
    function admin_search_keyboard_variants(string $term): array {
        return $term === 'וךארש' ? [$term, 'ultra'] : [$term];
    }
}

namespace {
    $GLOBALS['hp_pm_admin_search_hooks'] = [];
    $GLOBALS['hp_pm_admin_search_is_admin'] = true;
    $GLOBALS['hp_pm_admin_search_can_edit'] = true;

    function add_action(string $hook, callable $callback, int $priority = 10): void {
        $GLOBALS['hp_pm_admin_search_hooks'][] = [$hook, $callback, $priority];
    }

    function is_admin(): bool {
        return $GLOBALS['hp_pm_admin_search_is_admin'];
    }

    function current_user_can(string $capability): bool {
        return $capability === 'edit_products' && $GLOBALS['hp_pm_admin_search_can_edit'];
    }

    final class HP_PM_Admin_Search_Test_Query {
        /** @var array<string,mixed> */
        private array $vars;

        /** @param array<string,mixed> $vars */
        public function __construct(array $vars) {
            $this->vars = $vars;
        }

        public function get(string $key): mixed {
            return $this->vars[$key] ?? null;
        }

        public function set(string $key, mixed $value): void {
            $this->vars[$key] = $value;
        }
    }

    $fail = static function (string $message): void {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    };

    require_once dirname(__DIR__) . '/includes/class-hp-pm-native-product-admin-search.php';

    HP_PM_Native_Product_Admin_Search::register();
    $hook = $GLOBALS['hp_pm_admin_search_hooks'][0] ?? null;
    is_array($hook) || $fail('The native product adapter must register a query hook.');
    $hook[0] === 'pre_get_posts' || $fail('The adapter must run on pre_get_posts.');
    $hook[2] === PHP_INT_MAX || $fail('The adapter must run after product-list extensions.');

    $hebrew = new HP_PM_Admin_Search_Test_Query(['post_type' => 'product', 's' => 'וךארש']);
    HP_PM_Native_Product_Admin_Search::recover_product_query($hebrew);
    $hebrew->get('s') === 'ultra' || $fail('Hebrew product searches must use the Core QWERTY fallback.');

    $english = new HP_PM_Admin_Search_Test_Query(['post_type' => 'product', 's' => 'ultra']);
    HP_PM_Native_Product_Admin_Search::recover_product_query($english);
    $english->get('s') === 'ultra' || $fail('English product searches must remain unchanged.');

    $other_post_type = new HP_PM_Admin_Search_Test_Query(['post_type' => 'post', 's' => 'וךארש']);
    HP_PM_Native_Product_Admin_Search::recover_product_query($other_post_type);
    $other_post_type->get('s') === 'וךארש' || $fail('Non-product admin searches must remain unchanged.');

    $GLOBALS['hp_pm_admin_search_can_edit'] = false;
    $unauthorized = new HP_PM_Admin_Search_Test_Query(['post_type' => 'product', 's' => 'וךארש']);
    HP_PM_Native_Product_Admin_Search::recover_product_query($unauthorized);
    $unauthorized->get('s') === 'וךארש' || $fail('Unauthorized product queries must remain unchanged.');

    echo "Native product admin search contract passed.\n";
}
