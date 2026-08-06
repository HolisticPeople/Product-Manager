<?php

declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');

    final class WP_Error
    {
        public function __construct(
            private string $code,
            private string $message,
            private mixed $data = null
        ) {
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

    /** @var array<string,list<callable>> */
    $hpPmEditorialActions = [];
    /** @var array<int,object> */
    $hpPmEditorialPosts = [];
    /** @var array<int,array<string,mixed>> */
    $hpPmEditorialMeta = [];
    /** @var list<array<string,mixed>> */
    $hpPmRegisteredProviders = [];
    /** @var array<string,mixed> */
    $hpPmEditorialField = [];
    /** @var array<int,array<string,mixed>> */
    $hpPmEditorialGroups = [];

    function add_action(string $hook, callable $callback): void
    {
        global $hpPmEditorialActions;
        $hpPmEditorialActions[$hook][] = $callback;
    }

    function sanitize_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value));
    }

    function absint(mixed $value): int
    {
        return abs((int) $value);
    }

    function get_post(int $productId): ?object
    {
        global $hpPmEditorialPosts;
        return $hpPmEditorialPosts[$productId] ?? null;
    }

    function get_post_meta(int $productId, string $key, bool $single = false): mixed
    {
        global $hpPmEditorialMeta;
        unset($single);
        return $hpPmEditorialMeta[$productId][$key] ?? '';
    }

    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }

    function is_wp_error(mixed $value): bool
    {
        return $value instanceof WP_Error;
    }

    function acf_get_field(string $fieldKey): array|false
    {
        global $hpPmEditorialField;
        return ($hpPmEditorialField['key'] ?? '') === $fieldKey ? $hpPmEditorialField : false;
    }

    function acf_get_raw_field_group(int $parentId): array|false
    {
        global $hpPmEditorialGroups;
        return $hpPmEditorialGroups[$parentId] ?? false;
    }
}

namespace HP_Core {
    function register_contract_provider(array $provider): bool
    {
        global $hpPmRegisteredProviders;
        $hpPmRegisteredProviders[] = $provider;
        return true;
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/class-hp-pm-product-editorial-gallery-source-provider.php';

    function hp_pm_editorial_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function hp_pm_editorial_error_code(mixed $value): string
    {
        return $value instanceof WP_Error ? $value->get_error_code() : '';
    }

    /** @return array<string,mixed>|WP_Error */
    function hp_pm_editorial_payload(int $productId = 109731, string $consumer = 'hp-catalog'): array|WP_Error
    {
        return HP_PM_Product_Editorial_Gallery_Source_Provider::payload(
            HP_PM_Product_Editorial_Gallery_Source_Provider::CAPABILITY,
            ['consumer' => $consumer, 'product_id' => $productId]
        );
    }

    global $hpPmEditorialActions, $hpPmEditorialPosts, $hpPmEditorialMeta, $hpPmRegisteredProviders, $hpPmEditorialField, $hpPmEditorialGroups;

    HP_PM_Product_Editorial_Gallery_Source_Provider::register();
    hp_pm_editorial_assert(
        count($hpPmEditorialActions['hp_core_register_contract_providers'] ?? []) === 1,
        'The source provider must register once on the HP-Core collection hook.'
    );
    ($hpPmEditorialActions['hp_core_register_contract_providers'][0])();
    hp_pm_editorial_assert(count($hpPmRegisteredProviders) === 1, 'The HP-Core provider descriptor must register once.');
    $descriptor = $hpPmRegisteredProviders[0];
    hp_pm_editorial_assert(
        ($descriptor['provider_id'] ?? '') === HP_PM_Product_Editorial_Gallery_Source_Provider::PROVIDER_ID,
        'The provider ID must remain exact.'
    );
    hp_pm_editorial_assert(
        ($descriptor['capabilities'] ?? []) === [HP_PM_Product_Editorial_Gallery_Source_Provider::CAPABILITY]
            && ($descriptor['schema_versions'] ?? []) === [HP_PM_Product_Editorial_Gallery_Source_Provider::SCHEMA_VERSION],
        'The descriptor must expose only the governed capability and schema.'
    );
    hp_pm_editorial_assert(($descriptor['rest_namespace'] ?? null) === '', 'The source provider must not add REST transport.');
    hp_pm_editorial_assert(($descriptor['rest_base'] ?? null) === '', 'The source provider must not add a REST base.');

    $hpPmEditorialField = [
        'key' => 'field_679f5a3cce9a1',
        'name' => 'product_magazine_gallery',
        'type' => 'gallery',
        'parent' => 126109,
    ];
    $hpPmEditorialGroups[126109] = ['key' => 'group_6062fd00d4314'];
    $hpPmEditorialPosts[109731] = (object) [
        'ID' => 109731,
        'post_type' => 'product',
        'post_status' => 'publish',
    ];
    $hpPmEditorialMeta[109731] = [
        'product_type_hp' => 'book_type',
        'product_magazine_gallery' => [117053, '117054', 117055, '117056', 117057, 117058],
    ];

    $payload = hp_pm_editorial_payload();
    hp_pm_editorial_assert(is_array($payload), 'The governed Book fixture must return a source packet.');
    hp_pm_editorial_assert(
        ($payload['attachment_ids'] ?? []) === [117053, 117054, 117055, 117056, 117057, 117058],
        'The exact six attachment IDs must retain source order.'
    );
    hp_pm_editorial_assert(
        ($payload['product'] ?? []) === [
            'id' => 109731,
            'post_type' => 'product',
            'product_type' => 'book',
            'status' => 'publish',
        ],
        'The packet must carry only public Book product identity.'
    );
    hp_pm_editorial_assert(
        ($payload['field'] ?? []) === [
            'field_key' => 'field_679f5a3cce9a1',
            'field_name' => 'product_magazine_gallery',
            'group_key' => 'group_6062fd00d4314',
            'storage' => 'acf_product_meta',
        ],
        'The exact field and group provenance must remain immutable.'
    );
    hp_pm_editorial_assert(
        preg_match('/^sha256:[a-f0-9]{64}$/', (string) ($payload['source_fingerprint'] ?? '')) === 1,
        'The source fingerprint must be a prefixed SHA-256 value.'
    );
    hp_pm_editorial_assert(
        ($payload['source_fingerprint'] ?? '') === 'sha256:9c7c47c289cad30c39be06e7eb7d71a1e80760b1fed7e2804d93039db07d9c28',
        'The exact Book source fixture must retain its canonical fingerprint.'
    );
    $firstFingerprint = $payload['source_fingerprint'];
    hp_pm_editorial_assert(
        hp_pm_editorial_payload()['source_fingerprint'] === $firstFingerprint,
        'The same canonical source must produce a deterministic fingerprint.'
    );
    $hpPmEditorialMeta[109731]['product_magazine_gallery'] = [117054, 117053, 117055, 117056, 117057, 117058];
    hp_pm_editorial_assert(
        hp_pm_editorial_payload()['source_fingerprint'] !== $firstFingerprint,
        'Changing source order must change the source fingerprint.'
    );

    foreach ([[], '', null, false] as $emptySource) {
        $hpPmEditorialMeta[109731]['product_magazine_gallery'] = $emptySource;
        $empty = hp_pm_editorial_payload();
        hp_pm_editorial_assert(is_array($empty) && ($empty['attachment_ids'] ?? null) === [], 'An empty field must return a valid empty packet.');
    }

    foreach ([
        'scalar' => '117053',
        'object item' => [(object) ['ID' => 117053]],
        'array item' => [['ID' => 117053]],
        'associative source' => ['first' => 117053],
        'zero' => [0],
        'negative' => [-1],
        'float' => [117053.0],
        'URL' => ['https://example.com/image.png'],
        'overflowing numeric string' => [str_repeat('9', 40)],
    ] as $label => $source) {
        $hpPmEditorialMeta[109731]['product_magazine_gallery'] = $source;
        hp_pm_editorial_assert(is_wp_error(hp_pm_editorial_payload()), "Malformed source state {$label} must fail soft.");
    }

    $hpPmEditorialMeta[109731]['product_magazine_gallery'] = [117053, 117053];
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(hp_pm_editorial_payload()) === 'hp_pm_editorial_gallery_duplicate_attachment_id',
        'Duplicate IDs must be a typed source error, not silently deduplicated.'
    );

    $hpPmEditorialMeta[109731]['product_magazine_gallery'] = range(1, 25);
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(hp_pm_editorial_payload()) === 'hp_pm_editorial_gallery_too_many_items',
        'An oversized source must fail rather than truncate.'
    );

    $hpPmEditorialMeta[109731]['product_magazine_gallery'] = [117053];
    $validField = $hpPmEditorialField;
    $validGroups = $hpPmEditorialGroups;
    foreach ([
        'wrong key' => ['key' => 'field_wrong', 'name' => 'product_magazine_gallery', 'type' => 'gallery', 'parent' => 126109],
        'wrong name' => ['key' => 'field_679f5a3cce9a1', 'name' => 'wrong_name', 'type' => 'gallery', 'parent' => 126109],
        'wrong type' => ['key' => 'field_679f5a3cce9a1', 'name' => 'product_magazine_gallery', 'type' => 'text', 'parent' => 126109],
        'wrong group' => ['key' => 'field_679f5a3cce9a1', 'name' => 'product_magazine_gallery', 'type' => 'gallery', 'parent' => 999999],
    ] as $label => $field) {
        $hpPmEditorialField = $field;
        hp_pm_editorial_assert(
            hp_pm_editorial_error_code(hp_pm_editorial_payload()) === 'hp_pm_editorial_gallery_field_identity_mismatch',
            "Field registry state {$label} must fail soft."
        );
    }
    $hpPmEditorialField = $validField;
    $hpPmEditorialGroups = $validGroups;

    $fixedFieldPayload = HP_PM_Product_Editorial_Gallery_Source_Provider::payload(
        HP_PM_Product_Editorial_Gallery_Source_Provider::CAPABILITY,
        [
            'consumer' => 'hp-catalog',
            'product_id' => 109731,
            'field_name' => '_private_meta_attempt',
        ]
    );
    hp_pm_editorial_assert(
        is_array($fixedFieldPayload)
            && ($fixedFieldPayload['field']['field_name'] ?? '') === HP_PM_Product_Editorial_Gallery_Source_Provider::FIELD_NAME,
        'Caller context must never select an arbitrary product-meta key.'
    );
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(hp_pm_editorial_payload(109731, 'hp-zen')) === 'hp_pm_editorial_gallery_unauthorized_consumer',
        'HP-Zen must not consume the raw Product Manager source packet.'
    );
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(
            HP_PM_Product_Editorial_Gallery_Source_Provider::payload('wrong_capability', ['consumer' => 'hp-catalog', 'product_id' => 109731])
        ) === 'hp_pm_editorial_gallery_unsupported_capability',
        'Unsupported capabilities must fail soft.'
    );
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(hp_pm_editorial_payload(0)) === 'hp_pm_editorial_gallery_invalid_product_id',
        'A missing product ID must fail soft.'
    );
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(hp_pm_editorial_payload(999999)) === 'hp_pm_editorial_gallery_product_unavailable',
        'A missing product must fail soft.'
    );

    $hpPmEditorialPosts[109732] = (object) ['ID' => 109732, 'post_type' => 'page', 'post_status' => 'publish'];
    $hpPmEditorialMeta[109732] = ['product_type_hp' => 'book_type', 'product_magazine_gallery' => [117053]];
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(hp_pm_editorial_payload(109732)) === 'hp_pm_editorial_gallery_product_unavailable',
        'A non-product post must fail soft.'
    );

    $hpPmEditorialPosts[109733] = (object) ['ID' => 109733, 'post_type' => 'product', 'post_status' => 'draft'];
    $hpPmEditorialMeta[109733] = ['product_type_hp' => 'book_type', 'product_magazine_gallery' => [117053]];
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(hp_pm_editorial_payload(109733)) === 'hp_pm_editorial_gallery_product_unavailable',
        'An unpublished product must fail soft.'
    );

    $hpPmEditorialPosts[42198] = (object) ['ID' => 42198, 'post_type' => 'product', 'post_status' => 'publish'];
    $hpPmEditorialMeta[42198] = ['product_type_hp' => 'supplement_type', 'product_magazine_gallery' => [117053]];
    hp_pm_editorial_assert(
        hp_pm_editorial_error_code(hp_pm_editorial_payload(42198)) === 'hp_pm_editorial_gallery_wrong_product_type',
        'A supplement must never expose the Book editorial-gallery source.'
    );

    $providerSource = (string) file_get_contents(dirname(__DIR__) . '/includes/class-hp-pm-product-editorial-gallery-source-provider.php');
    $pluginSource = (string) file_get_contents(dirname(__DIR__) . '/products-manager.php');
    $contract = json_decode((string) file_get_contents(dirname(__DIR__) . '/hp-contract.json'), true);
    hp_pm_editorial_assert(
        str_contains($pluginSource, 'HP_PM_Product_Editorial_Gallery_Source_Provider::register();'),
        'Product Manager must bootstrap the source provider.'
    );
    hp_pm_editorial_assert(!str_contains($providerSource, 'update_post_meta('), 'The source provider must not write product meta.');
    hp_pm_editorial_assert(!str_contains($providerSource, 'delete_post_meta('), 'The source provider must not delete product meta.');
    hp_pm_editorial_assert(!str_contains($providerSource, 'register_rest_route('), 'The source provider must not add REST transport.');
    hp_pm_editorial_assert(!str_contains($providerSource, 'wp_get_attachment_'), 'Attachment validation and delivery belong to HP-Catalog.');
    hp_pm_editorial_assert(
        ($contract['exposes']['providers'] ?? []) === [HP_PM_Product_Editorial_Gallery_Source_Provider::PROVIDER_ID],
        'The owner manifest must declare the exact provider.'
    );

    fwrite(STDOUT, "Product editorial-gallery source provider contract test passed.\n");
}
