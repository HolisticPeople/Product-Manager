<?php

declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');

    final class WP_Error
    {
        public function __construct(private string $code, private string $message, private mixed $data = null)
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }

    /** @var array<string,array<int,array<string,callable>>> */
    $hpPmCoreActions = [];
    /** @var array<int,object> */
    $hpPmCorePosts = [];
    /** @var array<int,array<string,mixed>> */
    $hpPmCoreMeta = [];
    /** @var array<string,mixed> */
    $hpPmCoreField = [];
    /** @var array<int,array<string,mixed>> */
    $hpPmCoreGroups = [];

    function hp_pm_core_callback_id(callable $callback): string
    {
        if (is_array($callback)) {
            $owner = is_object($callback[0]) ? spl_object_hash($callback[0]) : (string) $callback[0];
            return $owner . '::' . (string) $callback[1];
        }
        return is_string($callback) ? $callback : spl_object_hash($callback);
    }

    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        global $hpPmCoreActions;
        $hpPmCoreActions[$hook][$priority][hp_pm_core_callback_id($callback)] = $callback;
    }

    function add_filter(string $hook, callable $callback, int $priority = 10): void
    {
        add_action($hook, $callback, $priority);
    }

    function do_action(string $hook, mixed ...$args): void
    {
        global $hpPmCoreActions;
        $priorities = $hpPmCoreActions[$hook] ?? [];
        ksort($priorities);
        foreach ($priorities as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback(...$args);
            }
        }
    }

    function register_activation_hook(string $file, callable $callback): void
    {
        unset($file, $callback);
    }

    function register_deactivation_hook(string $file, callable $callback): void
    {
        unset($file, $callback);
    }

    function plugin_dir_path(string $file): string
    {
        return dirname($file) . '/';
    }

    function plugin_dir_url(string $file): string
    {
        return 'https://example.test/' . basename(dirname($file)) . '/';
    }

    function plugin_basename(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
    }

    function sanitize_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value));
    }

    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function get_post(int $productId): ?object
    {
        global $hpPmCorePosts;
        return $hpPmCorePosts[$productId] ?? null;
    }

    function get_post_meta(int $productId, string $key, bool $single = false): mixed
    {
        global $hpPmCoreMeta;
        unset($single);
        return $hpPmCoreMeta[$productId][$key] ?? '';
    }

    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }

    function acf_get_field(string $fieldKey): array|false
    {
        global $hpPmCoreField;
        return ($hpPmCoreField['key'] ?? '') === $fieldKey ? $hpPmCoreField : false;
    }

    function acf_get_raw_field_group(int $parentId): array|false
    {
        global $hpPmCoreGroups;
        return $hpPmCoreGroups[$parentId] ?? false;
    }

    function hp_pm_core_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function hp_pm_core_error_code(mixed $value): string
    {
        if ($value instanceof WP_Error) {
            return $value->get_error_code();
        }
        return is_array($value) && ($value['state'] ?? '') === 'error'
            ? (string) ($value['error']['code'] ?? '')
            : '';
    }

    $coreRoot = getenv('HP_CORE_ROOT');
    hp_pm_core_assert(is_string($coreRoot) && $coreRoot !== '', 'HP_CORE_ROOT must point to a fresh HP-Core origin/dev worktree.');
    $coreMain = rtrim($coreRoot, '/') . '/hp-core-infrastructure.php';
    hp_pm_core_assert(is_file($coreMain), 'HP_CORE_ROOT must contain hp-core-infrastructure.php.');

    require_once $coreMain;
    require_once dirname(__DIR__) . '/includes/class-hp-pm-product-editorial-gallery-source-provider.php';

    global $hpPmCorePosts, $hpPmCoreMeta, $hpPmCoreField, $hpPmCoreGroups;
    $hpPmCoreField = [
        'key' => HP_PM_Product_Editorial_Gallery_Source_Provider::FIELD_KEY,
        'name' => HP_PM_Product_Editorial_Gallery_Source_Provider::FIELD_NAME,
        'type' => 'gallery',
        'parent' => 126109,
    ];
    $hpPmCoreGroups[126109] = ['key' => HP_PM_Product_Editorial_Gallery_Source_Provider::FIELD_GROUP];
    $hpPmCorePosts[109731] = (object) [
        'ID' => 109731,
        'post_type' => 'product',
        'post_status' => 'publish',
    ];
    $hpPmCoreMeta[109731] = [
        'product_type_hp' => 'book_type',
        'product_magazine_gallery' => [117053, 117054, 117055, 117056, 117057, 117058],
    ];

    HP_PM_Product_Editorial_Gallery_Source_Provider::register();
    HP_PM_Product_Editorial_Gallery_Source_Provider::register();
    $contracts = \HP_Core\refresh_contract_providers();
    hp_pm_core_assert(count($contracts) === 1, 'Repeated source registration must produce one public HP-Core contract.');
    hp_pm_core_assert(
        ($contracts[0]['providerId'] ?? '') === HP_PM_Product_Editorial_Gallery_Source_Provider::PROVIDER_ID,
        'The actual HP-Core registry must retain the exact provider ID.'
    );
    hp_pm_core_assert(
        \HP_Core\register_contract_provider([
            'provider_id' => HP_PM_Product_Editorial_Gallery_Source_Provider::PROVIDER_ID,
            'plugin' => 'products-manager',
            'label' => 'Product Manager Editorial Gallery Source',
            'description' => 'Public-safe product and field provenance plus ordered editorial-gallery attachment IDs.',
            'contract_version' => HP_PM_Product_Editorial_Gallery_Source_Provider::CONTRACT_VERSION,
            'capabilities' => [HP_PM_Product_Editorial_Gallery_Source_Provider::CAPABILITY],
            'schema_versions' => [HP_PM_Product_Editorial_Gallery_Source_Provider::SCHEMA_VERSION],
            'rest_namespace' => '',
            'rest_base' => '',
            'payload_callback' => [HP_PM_Product_Editorial_Gallery_Source_Provider::class, 'payload'],
        ]) === true,
        'Equivalent repeated registration must be idempotent in the actual HP-Core registry.'
    );

    $resolve = static fn (mixed $productId = 109731, mixed $consumer = 'hp-catalog'): array|WP_Error =>
        \HP_Core\get_contract_provider_payload(
            HP_PM_Product_Editorial_Gallery_Source_Provider::PROVIDER_ID,
            HP_PM_Product_Editorial_Gallery_Source_Provider::CAPABILITY,
            ['consumer' => $consumer, 'product_id' => $productId]
        );

    $ready = $resolve();
    hp_pm_core_assert(is_array($ready) && ($ready['state'] ?? '') === 'ready', 'Actual HP-Core resolution must preserve the ready source envelope.');
    hp_pm_core_assert(
        ($ready['attachment_ids'] ?? []) === [117053, 117054, 117055, 117056, 117057, 117058],
        'Actual HP-Core resolution must preserve exact source order.'
    );
    $canonicalString = $resolve('109731');
    hp_pm_core_assert(
        is_array($canonicalString)
            && ($canonicalString['state'] ?? '') === 'ready'
            && ($canonicalString['product']['id'] ?? 0) === 109731,
        'Actual HP-Core resolution must accept the canonical positive numeric string without changing identity.'
    );

    foreach ([-109731, 109731.0, 109731.5, ' 109731', '109731 ', '1.09731e5', 'x109731', '109731x', true, [109731], (object) ['id' => 109731], str_repeat('9', 40)] as $invalidId) {
        $invalid = $resolve($invalidId);
        hp_pm_core_assert(is_array($invalid), 'Governed provider diagnostics must remain arrays through HP-Core.');
        hp_pm_core_assert(
            hp_pm_core_error_code($invalid) === 'hp_pm_editorial_gallery_invalid_product_id',
            'HP-Core must preserve the exact invalid-product diagnostic instead of collapsing it.'
        );
    }

    $hpPmCoreMeta[109731]['product_magazine_gallery'] = [117053, '117054', 'private-source-marker', 117055];
    $partial = $resolve();
    hp_pm_core_assert(
        is_array($partial)
            && hp_pm_core_error_code($partial) === 'hp_pm_editorial_gallery_invalid_attachment_id'
            && ($partial['attachment_ids'] ?? null) === [],
        'A mixed valid/invalid list must fail without exposing a partial attachment sequence.'
    );
    hp_pm_core_assert(!str_contains((string) json_encode($partial), 'private-source-marker'), 'Failure envelopes must not disclose raw private values.');

    $hpPmCoreMeta[109731]['product_magazine_gallery'] = [];
    $empty = $resolve();
    hp_pm_core_assert(
        is_array($empty)
            && ($empty['state'] ?? '') === 'empty'
            && array_key_exists('error', $empty)
            && $empty['error'] === null,
        'An empty governed field must remain a valid empty envelope through HP-Core.'
    );

    $missing = \HP_Core\get_contract_provider_payload('missing-provider', 'missing-capability', []);
    hp_pm_core_assert(
        $missing instanceof WP_Error && $missing->get_error_code() === 'hp_core_contract_provider_missing',
        'The actual HP-Core wrapper must retain missing-provider transport diagnostics.'
    );

    \HP_Core\register_contract_provider([
        'provider_id' => 'hp_pm_throwing_test',
        'plugin' => 'products-manager',
        'label' => 'Throwing test',
        'description' => 'Test-only callback isolation.',
        'contract_version' => '1.0.0',
        'capabilities' => ['test_capability'],
        'schema_versions' => ['test_v1'],
        'payload_callback' => static function (): array {
            throw new RuntimeException('private-exception-marker');
        },
    ]);
    $thrown = \HP_Core\get_contract_provider_payload('hp_pm_throwing_test', 'test_capability', []);
    hp_pm_core_assert(
        $thrown instanceof WP_Error && $thrown->get_error_code() === 'hp_core_contract_provider_callback_threw',
        'The actual HP-Core registry must isolate throwing callbacks.'
    );
    hp_pm_core_assert(!str_contains($thrown->get_error_message(), 'private-exception-marker'), 'Thrown diagnostics must not expose private exception text.');

    \HP_Core\register_contract_provider([
        'provider_id' => 'hp_pm_nonarray_test',
        'plugin' => 'products-manager',
        'label' => 'Nonarray test',
        'description' => 'Test-only transport boundary.',
        'contract_version' => '1.0.0',
        'capabilities' => ['test_capability'],
        'schema_versions' => ['test_v1'],
        'payload_callback' => static fn (): WP_Error => new WP_Error('private-provider-error', 'private-provider-message'),
    ]);
    $collapsed = \HP_Core\get_contract_provider_payload('hp_pm_nonarray_test', 'test_capability', []);
    hp_pm_core_assert(
        $collapsed instanceof WP_Error && $collapsed->get_error_code() === 'hp_core_contract_provider_payload_invalid',
        'The actual HP-Core registry must reject non-array provider payloads.'
    );
    hp_pm_core_assert(!str_contains($collapsed->get_error_message(), 'private-provider'), 'HP-Core transport collapse must not expose provider-private diagnostics.');

    fwrite(STDOUT, "Product editorial-gallery HP-Core integration test passed.\n");
}
