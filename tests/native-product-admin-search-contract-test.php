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
    $GLOBALS['pagenow'] = 'edit.php';

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

    function wp_unslash(string $value): string {
        return $value;
    }

    function sanitize_key(string $value): string {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '');
    }

    function sanitize_text_field(string $value): string {
        return trim($value);
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
    count($hooks) === 3 || $fail('The native product adapter must register request, early-query, and late-query hooks.');
    $hooks[0][0] === 'admin_init' || $fail('The request adapter must run during admin_init.');
    $hooks[0][2] === 0 || $fail('The request adapter must run before list-screen search integrations.');
    $hooks[1][0] === 'pre_get_posts' || $fail('The early query adapter must run on pre_get_posts.');
    $hooks[1][2] === 0 || $fail('The query adapter must normalize before product search engines run.');
    $hooks[2][0] === 'pre_get_posts' || $fail('The late adapter must run on pre_get_posts.');
    $hooks[2][2] === PHP_INT_MAX || $fail('The late adapter must run after product-list extensions.');

    // Model the real browser lifecycle: Admin Columns can consume the request
    // and resolve post__in before the main query reaches pre_get_posts.
    $_GET = ['post_type' => 'product', 's' => 'וךארש'];
    $_REQUEST = $_GET;
    hp_pm_admin_search_run_action('admin_init', []);
    $_GET['s'] === 'ultra' || $fail('The Products-list GET term must be converted before query construction.');
    $_REQUEST['s'] === 'ultra' || $fail('The normalized term must also be visible through REQUEST.');

    // FiboSearch now receives the already-normalized request term, resolves
    // product IDs, and clears `s` before WordPress builds search SQL.
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

    $hebrew = new HP_PM_Admin_Search_Test_Query(['post_type' => 'product', 's' => $_GET['s']]);
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
    $_GET = ['post_type' => 'product', 's' => 'וךארש'];
    $_REQUEST = $_GET;
    HP_PM_Native_Product_Admin_Search::recover_product_list_request();
    $_GET['s'] === 'וךארש' || $fail('Unauthorized product-list requests must remain unchanged.');

    $unauthorized = new HP_PM_Admin_Search_Test_Query(['post_type' => 'product', 's' => 'וךארש']);
    HP_PM_Native_Product_Admin_Search::recover_product_query($unauthorized);
    $unauthorized->get('s') === 'וךארש' || $fail('Unauthorized product queries must remain unchanged.');

    $GLOBALS['hp_pm_admin_search_can_edit'] = true;
    $_GET = ['post_type' => 'post', 's' => 'וךארש'];
    $_REQUEST = $_GET;
    HP_PM_Native_Product_Admin_Search::recover_product_list_request();
    $_GET['s'] === 'וךארש' || $fail('Non-product list requests must remain unchanged.');

    $GLOBALS['pagenow'] = 'admin.php';
    $_GET = ['post_type' => 'product', 's' => 'וךארש'];
    $_REQUEST = $_GET;
    HP_PM_Native_Product_Admin_Search::recover_product_list_request();
    $_GET['s'] === 'וךארש' || $fail('Non-list admin requests must remain unchanged.');

    echo "Native product admin search contract passed.\n";
}
