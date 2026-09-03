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

    /** @param array<int,mixed> $args */
    function hp_pm_admin_search_run_action(string $hook, array $args): void {
        $callbacks = array_values(array_filter(
            $GLOBALS['hp_pm_admin_search_hooks'],
            static fn(array $registered): bool => $registered[0] === $hook
        ));
        usort(
            $callbacks,
            static fn(array $left, array $right): int => $left[2] <=> $right[2]
        );

        foreach ($callbacks as $registered) {
            $registered[1](...$args);
        }
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
    $hooks = $GLOBALS['hp_pm_admin_search_hooks'];
    count($hooks) === 2 || $fail('The native product adapter must register early and late query hooks.');
    $hooks[0][0] === 'pre_get_posts' || $fail('The early adapter must run on pre_get_posts.');
    $hooks[0][2] === 0 || $fail('The adapter must normalize before product search engines run.');
    $hooks[1][0] === 'pre_get_posts' || $fail('The late adapter must run on pre_get_posts.');
    $hooks[1][2] === PHP_INT_MAX || $fail('The late adapter must run after product-list extensions.');

    // Model the real staging path: FiboSearch reads `s`, resolves product IDs,
    // and clears `s` at priority 900001 before WordPress builds search SQL.
    $GLOBALS['hp_pm_admin_search_hooks'][] = [
        'pre_get_posts',
        static function (HP_PM_Admin_Search_Test_Query $query): void {
            $phrase = (string) $query->get('s');
            $GLOBALS['hp_pm_fibosearch_phrase'] = $phrase;
            $query->set('dgwt_wcas', $phrase);
            $query->set('post__in', $phrase === 'ultra' ? [101, 102] : [-1]);
            $query->set('s', '');
        },
        900001,
    ];

    $hebrew = new HP_PM_Admin_Search_Test_Query(['post_type' => 'product', 's' => 'וךארש']);
    hp_pm_admin_search_run_action('pre_get_posts', [$hebrew]);
    $GLOBALS['hp_pm_fibosearch_phrase'] === 'ultra'
        || $fail('FiboSearch must receive the converted QWERTY term.');
    $hebrew->get('dgwt_wcas') === 'ultra'
        || $fail('FiboSearch must preserve the converted term for its result query.');
    $hebrew->get('post__in') === [101, 102]
        || $fail('The converted FiboSearch path must resolve matching product IDs.');

    $english = new HP_PM_Admin_Search_Test_Query(['post_type' => 'product', 's' => 'ultra']);
    hp_pm_admin_search_run_action('pre_get_posts', [$english]);
    $GLOBALS['hp_pm_fibosearch_phrase'] === 'ultra'
        || $fail('English product searches must remain unchanged for FiboSearch.');
    $english->get('post__in') === [101, 102]
        || $fail('English FiboSearch product IDs must remain unchanged.');

    $other_post_type = new HP_PM_Admin_Search_Test_Query(['post_type' => 'post', 's' => 'וךארש']);
    HP_PM_Native_Product_Admin_Search::recover_product_query($other_post_type);
    $other_post_type->get('s') === 'וךארש' || $fail('Non-product admin searches must remain unchanged.');

    $GLOBALS['hp_pm_admin_search_can_edit'] = false;
    $unauthorized = new HP_PM_Admin_Search_Test_Query(['post_type' => 'product', 's' => 'וךארש']);
    HP_PM_Native_Product_Admin_Search::recover_product_query($unauthorized);
    $unauthorized->get('s') === 'וךארש' || $fail('Unauthorized product queries must remain unchanged.');

    echo "Native product admin search contract passed.\n";
}
