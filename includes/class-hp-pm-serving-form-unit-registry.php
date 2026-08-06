<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the source-controlled compatibility contract for the legacy ACF
 * serving-form registry. Product values remain Woo product meta; this class
 * changes field schema only and never migrates product content.
 */
final class HP_PM_Serving_Form_Unit_Registry
{
    public const FIELD_KEY = 'field_60630d7415c75';
    public const FIELD_NAME = 'serving_form_unit';
    public const FIELD_GROUP = 'group_60630bdd97bfa';
    public const PRODUCT_TYPE_FIELD_KEY = 'field_60635249351af';
    public const SUPPLEMENT_TYPE = 'supplement_type';
    public const BOOK_TYPE = 'book_type';
    public const CHOICE_VALUE = 'Dropper';
    public const BASELINE_CHOICES_SHA256 = 'cfdb958119c5aba5e3b1834861ed869f67ff1774a5000c60ed2a8c7bd1ba3461';
    public const NORMALIZED_CHOICES_SHA256 = '9db6f5a8b0ff2f375577e9da65df74b4a7f33dca27a8aacb8d49ce1fb593ee6e';

    /** Product IDs used only for read-only mutation-boundary verification. */
    private const ASSURANCE_PRODUCTS = [42198, 42569, 109731];

    public static function register(): void
    {
        // WP-CLI must see the persisted field, not the runtime-normalized view,
        // so status/apply/rollback can prove the exact database transition.
        if (defined('WP_CLI') && WP_CLI) {
            if (class_exists('WP_CLI')) {
                \WP_CLI::add_command(
                    'hp-products-manager acf-registry serving-form-unit',
                    [self::class, 'cli']
                );
            }
            return;
        }

        add_filter('acf/load_field/key=' . self::FIELD_KEY, [self::class, 'normalize_field']);
    }

    /**
     * Append the governed plain value and make the field supplement-only.
     * All unrelated choices and product values remain untouched.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    public static function normalize_field(array $field): array
    {
        if (!self::field_matches_identity($field)) {
            return $field;
        }

        $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
        $normalized = [];
        $inserted = false;
        foreach ($choices as $value => $label) {
            $value = (string) $value;
            if ($value === self::CHOICE_VALUE) {
                if (!$inserted) {
                    $normalized[self::CHOICE_VALUE] = self::CHOICE_VALUE;
                    $inserted = true;
                }
                continue;
            }
            $normalized[$value] = (string) $label;
            if ($value === 'Drop(s)' && !$inserted) {
                $normalized[self::CHOICE_VALUE] = self::CHOICE_VALUE;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $normalized[self::CHOICE_VALUE] = self::CHOICE_VALUE;
        }

        $field['choices'] = $normalized;
        $field['conditional_logic'] = [[[
            'field' => self::PRODUCT_TYPE_FIELD_KEY,
            'operator' => '==',
            'value' => self::SUPPLEMENT_TYPE,
        ]]];

        return $field;
    }

    /**
     * Prevent a hidden Book control from becoming a write surface. Existing
     * Book meta is deliberately preserved; this is not a cleanup migration.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public static function guard_changes_for_product_type(array $changes, string $productType): array
    {
        if ($productType === self::BOOK_TYPE) {
            unset($changes[self::FIELD_NAME]);
        }
        return $changes;
    }

    /** @param array<string,mixed> $choices */
    public static function choices_hash(array $choices): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($choices, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($choices, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', is_string($json) ? $json : '');
    }

    /**
     * WP-CLI entry point.
     *
     * ## OPTIONS
     *
     * --action=<status|apply|rollback>
     * : Inspect, persist the governed schema, or restore a complete backup.
     *
     * [--backup=<absolute-path>]
     * : Required for apply/rollback. Apply never overwrites an existing file.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assocArgs
     */
    public static function cli(array $args, array $assocArgs): void
    {
        unset($args);
        $action = sanitize_key((string) ($assocArgs['action'] ?? 'status'));
        if (!in_array($action, ['status', 'apply', 'rollback'], true)) {
            \WP_CLI::error('Action must be status, apply, or rollback.');
        }

        if (!function_exists('acf_update_field') || (!function_exists('acf_get_raw_field') && !function_exists('acf_get_field'))) {
            \WP_CLI::error('ACF field APIs are unavailable; no registry change was made.');
        }

        $field = self::read_persisted_field();
        self::assert_field_identity($field);
        $beforeProducts = self::assurance_product_snapshot();
        $beforeChoicesHash = self::choices_hash(is_array($field['choices'] ?? null) ? $field['choices'] : []);

        if ($action === 'status') {
            self::write_cli_result([
                'action' => 'status',
                'changed' => false,
                'environment' => self::environment(),
                'field' => self::field_summary($field),
                'choices_sha256' => $beforeChoicesHash,
                'normalized' => self::is_normalized_field($field),
                'assurance_products' => $beforeProducts,
            ]);
            return;
        }

        self::assert_staging_environment();
        $backupPath = self::validated_backup_path((string) ($assocArgs['backup'] ?? ''));

        if ($action === 'apply') {
            if ($beforeChoicesHash === self::NORMALIZED_CHOICES_SHA256 && self::is_normalized_field($field)) {
                self::write_cli_result([
                    'action' => 'apply',
                    'changed' => false,
                    'reason' => 'already_normalized',
                    'environment' => self::environment(),
                    'field' => self::field_summary($field),
                    'choices_sha256' => $beforeChoicesHash,
                    'assurance_products' => $beforeProducts,
                ]);
                return;
            }
            if ($beforeChoicesHash !== self::BASELINE_CHOICES_SHA256) {
                \WP_CLI::error('Serving-form choices do not match the approved baseline; no registry change was made.');
            }
            if (file_exists($backupPath)) {
                \WP_CLI::error('Backup path already exists; refusing to overwrite it.');
            }

            self::write_backup($backupPath, $field, $beforeChoicesHash, $beforeProducts);
            $updated = self::normalize_field($field);
            acf_update_field($updated);
            $afterField = self::read_persisted_field();
            self::assert_field_identity($afterField);
            $afterChoicesHash = self::choices_hash(is_array($afterField['choices'] ?? null) ? $afterField['choices'] : []);
            if ($afterChoicesHash !== self::NORMALIZED_CHOICES_SHA256 || !self::is_normalized_field($afterField)) {
                \WP_CLI::error('Registry verification failed after apply; use the recorded backup for rollback.');
            }
            $afterProducts = self::assurance_product_snapshot();
            self::assert_products_unchanged($beforeProducts, $afterProducts);
            self::write_cli_result([
                'action' => 'apply',
                'changed' => true,
                'environment' => self::environment(),
                'backup' => $backupPath,
                'before_choices_sha256' => $beforeChoicesHash,
                'after_choices_sha256' => $afterChoicesHash,
                'field' => self::field_summary($afterField),
                'product_meta_writes' => 0,
                'assurance_products' => $afterProducts,
            ]);
            return;
        }

        $backup = self::read_backup($backupPath);
        $backupField = is_array($backup['field'] ?? null) ? $backup['field'] : [];
        self::assert_field_identity($backupField);
        $backupChoicesHash = self::choices_hash(is_array($backupField['choices'] ?? null) ? $backupField['choices'] : []);
        if (!hash_equals((string) ($backup['choices_sha256'] ?? ''), $backupChoicesHash)) {
            \WP_CLI::error('Backup choice hash is invalid; no registry change was made.');
        }
        $backupFieldHash = self::field_hash($backupField);
        if (hash_equals($backupFieldHash, self::field_hash($field))) {
            self::write_cli_result([
                'action' => 'rollback',
                'changed' => false,
                'reason' => 'already_restored',
                'environment' => self::environment(),
                'choices_sha256' => $beforeChoicesHash,
                'assurance_products' => $beforeProducts,
            ]);
            return;
        }
        if ($beforeChoicesHash !== self::NORMALIZED_CHOICES_SHA256) {
            \WP_CLI::error('Current choices do not match the governed normalized state; refusing rollback.');
        }

        acf_update_field($backupField);
        $afterField = self::read_persisted_field();
        self::assert_field_identity($afterField);
        $afterChoicesHash = self::choices_hash(is_array($afterField['choices'] ?? null) ? $afterField['choices'] : []);
        if (!hash_equals($backupChoicesHash, $afterChoicesHash) || !hash_equals($backupFieldHash, self::field_hash($afterField))) {
            \WP_CLI::error('Registry verification failed after rollback.');
        }
        $afterProducts = self::assurance_product_snapshot();
        self::assert_products_unchanged($beforeProducts, $afterProducts);
        self::write_cli_result([
            'action' => 'rollback',
            'changed' => true,
            'environment' => self::environment(),
            'backup' => $backupPath,
            'before_choices_sha256' => $beforeChoicesHash,
            'after_choices_sha256' => $afterChoicesHash,
            'field' => self::field_summary($afterField),
            'product_meta_writes' => 0,
            'assurance_products' => $afterProducts,
        ]);
    }

    /** @return array<string,mixed> */
    private static function read_persisted_field(): array
    {
        $field = function_exists('acf_get_raw_field')
            ? acf_get_raw_field(self::FIELD_KEY)
            : acf_get_field(self::FIELD_KEY);
        return is_array($field) ? $field : [];
    }

    /** @param array<string,mixed> $field */
    private static function assert_field_identity(array $field): void
    {
        if (!self::field_matches_identity($field)) {
            \WP_CLI::error('The persisted ACF field identity differs from the approved serving-form registry.');
        }
    }

    /** @param array<string,mixed> $field */
    private static function field_matches_identity(array $field): bool
    {
        return (string) ($field['key'] ?? '') === self::FIELD_KEY
            && (string) ($field['name'] ?? '') === self::FIELD_NAME
            && (string) ($field['type'] ?? '') === 'select'
            && self::parent_matches_group($field['parent'] ?? null);
    }

    private static function parent_matches_group(mixed $parent): bool
    {
        if ($parent === self::FIELD_GROUP) {
            return true;
        }

        if (is_int($parent)) {
            $parentId = $parent;
        } elseif (is_string($parent) && ctype_digit($parent)) {
            $parentId = (int) $parent;
        } else {
            return false;
        }

        if ($parentId <= 0 || !function_exists('acf_get_raw_field_group')) {
            return false;
        }

        $group = acf_get_raw_field_group($parentId);
        return is_array($group) && (string) ($group['key'] ?? '') === self::FIELD_GROUP;
    }

    private static function assert_staging_environment(): void
    {
        if (self::environment() !== 'staging') {
            \WP_CLI::error('Registry writes are staging-only. Production promotion requires separate authorization.');
        }
    }

    private static function environment(): string
    {
        return function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'unknown';
    }

    private static function validated_backup_path(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR)) {
            \WP_CLI::error('An absolute --backup path is required.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            \WP_CLI::error('The backup directory must already exist and be writable.');
        }
        return $path;
    }

    /**
     * @param array<string,mixed> $field
     * @param array<int,array<string,string>> $products
     */
    private static function write_backup(string $path, array $field, string $choicesHash, array $products): void
    {
        $payload = [
            'schema' => 'hp_pm_serving_form_unit_registry_backup_v1',
            'generated_at' => gmdate('c'),
            'environment' => self::environment(),
            'site_url' => function_exists('home_url') ? home_url('/') : '',
            'field_key' => self::FIELD_KEY,
            'field_name' => self::FIELD_NAME,
            'field_group' => self::FIELD_GROUP,
            'choices_sha256' => $choicesHash,
            'field_sha256' => self::field_hash($field),
            'field' => $field,
            'assurance_products' => $products,
        ];
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            \WP_CLI::error('Unable to write the complete registry backup; no registry change was made.');
        }
    }

    /** @return array<string,mixed> */
    private static function read_backup(string $path): array
    {
        if (!is_readable($path)) {
            \WP_CLI::error('The requested backup is not readable.');
        }
        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload) || ($payload['schema'] ?? '') !== 'hp_pm_serving_form_unit_registry_backup_v1') {
            \WP_CLI::error('The requested backup does not match the serving-form registry schema.');
        }
        $field = is_array($payload['field'] ?? null) ? $payload['field'] : [];
        if (!hash_equals((string) ($payload['field_sha256'] ?? ''), self::field_hash($field))) {
            \WP_CLI::error('The complete field backup hash is invalid.');
        }
        return $payload;
    }

    /** @return array<int,array<string,string>> */
    private static function assurance_product_snapshot(): array
    {
        $snapshot = [];
        foreach (self::ASSURANCE_PRODUCTS as $productId) {
            $snapshot[$productId] = [
                'serving_form_unit' => (string) get_post_meta($productId, self::FIELD_NAME, true),
                'product_type_hp' => (string) get_post_meta($productId, 'product_type_hp', true),
            ];
        }
        return $snapshot;
    }

    /**
     * @param array<int,array<string,string>> $before
     * @param array<int,array<string,string>> $after
     */
    private static function assert_products_unchanged(array $before, array $after): void
    {
        if ($before !== $after) {
            \WP_CLI::error('Product meta changed during a registry-only operation; investigate and rollback the field schema.');
        }
    }

    /** @param array<string,mixed> $field */
    private static function field_hash(array $field): string
    {
        $json = wp_json_encode($field, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', is_string($json) ? $json : '');
    }

    /** @param array<string,mixed> $field */
    private static function is_normalized_field(array $field): bool
    {
        $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
        return self::choices_hash($choices) === self::NORMALIZED_CHOICES_SHA256
            && ($field['conditional_logic'] ?? null) === [[[
                'field' => self::PRODUCT_TYPE_FIELD_KEY,
                'operator' => '==',
                'value' => self::SUPPLEMENT_TYPE,
            ]]];
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    private static function field_summary(array $field): array
    {
        return [
            'key' => (string) ($field['key'] ?? ''),
            'name' => (string) ($field['name'] ?? ''),
            'parent' => (string) ($field['parent'] ?? ''),
            'type' => (string) ($field['type'] ?? ''),
            'conditional_logic' => $field['conditional_logic'] ?? 0,
            'choices' => is_array($field['choices'] ?? null) ? $field['choices'] : [],
        ];
    }

    /** @param array<string,mixed> $result */
    private static function write_cli_result(array $result): void
    {
        \WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
