<?php
/** Finite private reviewed content import; no worker activation or taxonomy writer. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

final class HP_PM_Reviewed_Product_Import
{
    public const SCHEMA = 'hp_pm_reviewed_product_import_v1';
    private const POST_FIELDS = ['post_title', 'post_excerpt', 'post_content'];
    private const TEXT_FIELDS = ['slogan', 'aka_product_name', 'description_long', 'ingredients', 'ingredients_other', 'how_to_use', 'recommended_use', 'cautions', 'sku_mfr', 'bottle_size_eu', 'bottle_size_units_eu', 'bottle_size_usa', 'bottle_size_units_usa'];
    private const BOUNDARY = '\\HP_Catalog\\Product_Source_Update';

    public static function register(): void
    {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) { \WP_CLI::add_command('hp-products-manager reviewed-import', [self::class, 'cli']); }
    }

    /** Private CLI only; review means an actual externally reviewed finite packet, never preview output. */
    public static function cli(array $args, array $options): void
    {
        unset($args);
        try {
            $action = $options['action'] ?? 'preview';
            if (!in_array($action, ['preview', 'apply', 'rollback'], true)) { throw new \InvalidArgumentException('Expected preview, apply or rollback.'); }
            $path = $options['file'] ?? '';
            if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path) || filesize($path) > 1048576) { throw new \InvalidArgumentException('A bounded absolute private --file manifest is required.'); }
            $packet = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($packet)) { throw new \InvalidArgumentException('A manifest object is required.'); }
            \WP_CLI::line((string) wp_json_encode(self::run($packet, $action), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) { \WP_CLI::error($e->getMessage()); }
    }

    public static function run(array $manifest, string $action = 'preview'): array
    {
        self::authorize();
        if (!in_array($action, ['preview', 'apply', 'rollback'], true)) { throw new \InvalidArgumentException('Unknown action.'); }
        $m = self::manifest($manifest);
        $id = $m['productId'];
        if (!current_user_can('edit_post', $id)) { throw new \RuntimeException('Exact product edit permission required.'); }
        $key = 'hp_pm_reviewed_import_' . $id . '_' . hash('sha256', $m['operationId']);
        $hash = hash('sha256', serialize($m));
        if ($action !== 'preview') {
        $boundary = self::BOUNDARY;
        if (!defined($boundary . '::VERSION') || constant($boundary . '::VERSION') !== '1.0.0') { throw new \RuntimeException('qualified_catalog_source_boundary_version_unavailable'); }
        foreach (['begin', 'assert_current', 'complete', 'abort', 'active'] as $method) { if (!is_callable([$boundary, $method])) { throw new \RuntimeException('qualified_catalog_source_boundary_unavailable'); } }
        }
        $journal = get_option($key, null);
        if ($journal !== null) { self::validate_journal($journal, $m, $hash); }
        if (is_array($journal) && ($journal['phase'] ?? '') === 'completed' && $action !== 'rollback') {
            self::verify_after($m, $journal, false);
            $protectedUnchanged = self::protected_hash($m) === $journal['protectedHash'];
            $boundary = self::BOUNDARY;
            if ($action === 'preview') { return ['status' => 'completed_readback', 'writeMode' => false, 'productId' => $id, 'protectedUnchanged' => $protectedUnchanged, 'boundaryActive' => is_callable([$boundary, 'active']) ? $boundary::active($id) : null, 'nativeWrites' => 0, 'journalWrites' => 0]; }
            if (is_callable([$boundary, 'active']) && $boundary::active($id)) {
                $token = $boundary::begin($id, $m['operationId']);
                $done = false;
                try { self::verify_after($m, $journal); $boundary::assert_current($token); $boundary::complete($token); $done = true; }
                finally { if (!$done && $boundary::active($id)) { $boundary::abort($token); } }
                return ['status' => 'recovered_completion', 'productId' => $id, 'nativeWrites' => 0, 'journalWrites' => 0];
            }
            return ['status' => $protectedUnchanged ? 'noop' : 'fields_noop_protected_state_requires_review', 'productId' => $id, 'operationId' => $m['operationId'], 'protectedUnchanged' => $protectedUnchanged, 'nativeWrites' => 0, 'journalWrites' => 0];
        }
        if (is_array($journal) && ($journal['phase'] ?? '') === 'rolled_back') {
            self::verify_before($m, $journal['protectedHash']);
            if ($action === 'preview') { return ['status' => 'restored_readback', 'writeMode' => false, 'productId' => $id, 'nativeWrites' => 0, 'journalWrites' => 0]; }
            if ($action === 'rollback') {
                $boundary = self::BOUNDARY;
                if ($boundary::active($id)) {
                    $token = $boundary::begin($id, $m['operationId']); $done = false;
                    try { self::verify_before($m, $journal['protectedHash']); $boundary::assert_current($token); $boundary::complete($token); $done = true; }
                    finally { if (!$done && $boundary::active($id)) { $boundary::abort($token); } }
                    return ['status' => 'recovered_restoration', 'productId' => $id, 'nativeWrites' => 0, 'journalWrites' => 0];
                }
                return ['status' => 'noop_restored', 'productId' => $id, 'nativeWrites' => 0, 'journalWrites' => 0];
            }
            throw new \RuntimeException('operation_already_rolled_back_use_new_reviewed_operation');
        }
        if ($action === 'preview') {
            if ($journal !== null) { throw new \RuntimeException('partial_operation_requires_scoped_recovery'); }
            self::verify_before($m);
            return ['status' => 'reviewed_preview', 'writeMode' => false, 'productId' => $id, 'operationId' => $m['operationId'], 'manifestHash' => $hash, 'fields' => array_column($m['fields'], 'field')];
        }
        $boundary = self::BOUNDARY;
        if ($action === 'rollback' && $journal === null) { throw new \RuntimeException('owned_journal_required'); }
        $token = $boundary::begin($id, $m['operationId']);
        $completed = false;
        try {
            if ($action === 'rollback') {
                $result = self::rollback($m, $key, $journal, $token);
            } else {
                if ($journal === null) {
                    self::verify_before($m);
                    $journal = ['schemaVersion' => self::SCHEMA, 'manifestHash' => $hash, 'productId' => $id, 'operationId' => $m['operationId'], 'phase' => 'intent', 'protectedHash' => self::protected_hash($m), 'beforeIdentity' => self::identity($id), 'afterIdentity' => self::after_identity($m), 'rows' => [], 'reviewRef' => $m['review']['reference'], 'actorId' => get_current_user_id(), 'recordedAt' => gmdate('c')];
                    $boundary::assert_current($token);
                    if (!add_option($key, $journal, '', false) || get_option($key) !== $journal) { throw new \RuntimeException('intent_checkpoint_failed'); }
                } else { throw new \RuntimeException('partial_operation_requires_scoped_rollback'); }
                foreach ($m['fields'] as $row) {
                    $name = $row['field'];
                    $boundary::assert_current($token);
                    self::assert_protected($m, $journal['protectedHash']);
                    if (self::state($id, $name) !== $row['before'] || self::reference($id, $name) !== $row['referenceBefore']) { throw new \RuntimeException('field_drift:' . $name); }
                    $journal['rows'][$name] = ['phase' => 'intent', 'before' => $row['before'], 'referenceBefore' => $row['referenceBefore'], 'after' => ['exists' => true, 'value' => $row['proposed']], 'referenceAfter' => self::expected_reference($row)];
                    self::checkpoint($key, $journal, $token);
                    $boundary::assert_current($token);
                    if (self::state($id, $name) !== $row['before'] || self::reference($id, $name) !== $row['referenceBefore']) { throw new \RuntimeException('field_drift_before_write:' . $name); }
                    self::write($id, $row);
                    if (self::state($id, $name) !== ['exists' => true, 'value' => $row['proposed']] || self::reference($id, $name) !== self::expected_reference($row)) { throw new \RuntimeException('field_readback_failed:' . $name); }
                    $journal['rows'][$name]['phase'] = 'verified';
                    self::checkpoint($key, $journal, $token);
                }
                self::verify_after($m, $journal);
                // The source boundary is completed only after every final native value is verified.
                $journal['phase'] = 'completed';
                self::checkpoint($key, $journal, $token);
                $result = ['status' => 'applied', 'productId' => $id, 'operationId' => $m['operationId'], 'fieldCount' => count($m['fields']), 'journalKey' => $key];
            }
            $boundary::assert_current($token);
            $boundary::complete($token);
            $completed = true;
            return $result;
        } finally { if (!$completed && $boundary::active($id)) { $boundary::abort($token); } }
    }


    private static function validate_journal(mixed $journal, array $m, string $hash): void
    {
        if (!is_array($journal) || ($journal['manifestHash'] ?? '') !== $hash || ($journal['schemaVersion'] ?? '') !== self::SCHEMA || ($journal['productId'] ?? 0) !== $m['productId'] || ($journal['operationId'] ?? '') !== $m['operationId'] || ($journal['beforeIdentity'] ?? null) !== $m['identity'] || ($journal['afterIdentity'] ?? null) !== self::after_identity($m) || !is_string($journal['protectedHash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $journal['protectedHash']) || !in_array($journal['phase'] ?? '', ['intent', 'completed', 'rollback_intent', 'rolled_back'], true) || !is_array($journal['rows'] ?? null)) { throw new \RuntimeException('operation_journal_collision_or_corruption'); }
        $selected = [];
        foreach ($m['fields'] as $row) { $selected[$row['field']] = $row; }
        foreach ($journal['rows'] as $name => $owned) {
            $row = $selected[$name] ?? null;
            if (!$row || !is_array($owned) || !in_array($owned['phase'] ?? '', ['intent', 'verified', 'restored'], true) || ($owned['before'] ?? null) !== $row['before'] || ($owned['referenceBefore'] ?? null) !== $row['referenceBefore'] || ($owned['after'] ?? null) !== ['exists' => true, 'value' => $row['proposed']] || ($owned['referenceAfter'] ?? null) !== self::expected_reference($row)) { throw new \RuntimeException('owned_row_journal_corruption'); }
        }
        if ($journal['phase'] === 'completed' && count($journal['rows']) !== count($selected)) { throw new \RuntimeException('completed_journal_incomplete'); }
    }

    private static function authorize(): void
    {
        if (!defined('WP_CLI') || WP_CLI !== true || get_current_user_id() < 1 || !current_user_can('manage_woocommerce') || !current_user_can('edit_products')) { throw new \RuntimeException('Qualified private CLI operator permission required.'); }
    }

    private static function manifest(array $m): array
    {
        HP_PM_Product_Composition_Fields::keys($m, ['schemaVersion', 'operationId', 'siteUrl', 'environment', 'productId', 'identity', 'review', 'fields']);
        if ($m['schemaVersion'] !== self::SCHEMA || !is_int($m['productId']) || $m['productId'] < 1 || get_post_type($m['productId']) !== 'product') { throw new \InvalidArgumentException('Exact existing native product required.'); }
        if (!is_string($m['operationId']) || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/D', $m['operationId'])) { throw new \InvalidArgumentException('Exact bounded source operation ID required.'); }
        if ($m['siteUrl'] !== home_url('/') || $m['environment'] !== wp_get_environment_type() || !in_array($m['environment'], ['staging', 'production'], true)) { throw new \RuntimeException('site_environment_mismatch'); }
        HP_PM_Product_Composition_Fields::keys($m['identity'], array_keys(self::identity($m['productId'])));
        $m['identity'] = array_replace(self::identity($m['productId']), $m['identity']);
        foreach ($m['identity'] as $value) { if (!is_string($value)) { throw new \InvalidArgumentException('Native identity values must preserve string types.'); } }
        HP_PM_Product_Composition_Fields::keys($m['review'], ['decision', 'reference', 'reviewer', 'identityApproved', 'sourceMarket', 'holds', 'finalCopySha256', 'sourceFidelityReviewer', 'sourceFidelityReference']);
        if ($m['review']['decision'] !== 'approved' || $m['review']['identityApproved'] !== true || $m['review']['holds'] !== []) { throw new \RuntimeException('explicit_reviewed_identity_without_holds_required'); }
        foreach (['reference', 'reviewer', 'sourceMarket', 'sourceFidelityReviewer', 'sourceFidelityReference'] as $name) { HP_PM_Product_Composition_Fields::text($m['review'][$name], 200); }
        if (!is_array($m['fields']) || !array_is_list($m['fields']) || !$m['fields'] || count($m['fields']) > 30 || strlen(serialize($m)) > 1048576) { throw new \InvalidArgumentException('Bounded finite field list required.'); }
        $seen = [];
        foreach ($m['fields'] as $index => $row) {
            HP_PM_Product_Composition_Fields::keys($row, ['field', 'before', 'proposed', 'referenceBefore', 'evidence']);
            $name = $row['field'];
            if (!is_string($name) || isset($seen[$name]) || !in_array($name, array_merge(self::POST_FIELDS, self::TEXT_FIELDS, array_keys(HP_PM_Product_Composition_Fields::schema()['fields'])), true)) { throw new \InvalidArgumentException('Unsupported or repeated content field.'); }
            $seen[$name] = true;
            self::validate_state($row['before']);
            if (in_array($name, self::POST_FIELDS, true)) {
                if ($row['referenceBefore'] !== null || $row['before']['exists'] !== true) { throw new \InvalidArgumentException('Native post field has no ACF reference or absent state.'); }
            } else {
                self::validate_state($row['referenceBefore']);
                if ($row['referenceBefore']['exists'] && !is_string($row['referenceBefore']['value'])) { throw new \InvalidArgumentException('Malformed ACF reference.'); }
                $definition = self::field_definition($name);
                if ($row['referenceBefore']['exists'] && $row['referenceBefore']['value'] !== $definition['key']) { throw new \RuntimeException('ACF_reference_identity_mismatch:' . $name); }
            }
            if (isset(HP_PM_Product_Composition_Fields::schema()['fields'][$name])) { HP_PM_Product_Composition_Fields::validate($name, $row['proposed']); }
            elseif (!is_string($row['proposed']) || strlen($row['proposed']) > 64000 || preg_match('//u', $row['proposed']) !== 1 || str_contains($row['proposed'], "\0")) { throw new \InvalidArgumentException('Bounded UTF-8 field text required.'); }
            // Values are never silently sanitized into a different reviewed proposal.
            if (is_string($row['proposed']) && wp_kses_post($row['proposed']) !== $row['proposed']) { throw new \InvalidArgumentException('Reviewed field contains unsupported HTML.'); }
            if (!is_array($row['evidence']) || !array_is_list($row['evidence']) || !$row['evidence'] || count($row['evidence']) > 20) { throw new \InvalidArgumentException('Exact field evidence references required.'); }
            foreach ($row['evidence'] as $ref) { HP_PM_Product_Composition_Fields::text($ref, 500); }
            $row['before'] = ['exists' => $row['before']['exists'], 'value' => $row['before']['value']];
            if ($row['referenceBefore'] !== null) { $row['referenceBefore'] = ['exists' => $row['referenceBefore']['exists'], 'value' => $row['referenceBefore']['value']]; }
            $m['fields'][$index] = ['field' => $row['field'], 'before' => $row['before'], 'proposed' => $row['proposed'], 'referenceBefore' => $row['referenceBefore'], 'evidence' => $row['evidence']];
        }
        if (!is_string($m['review']['finalCopySha256']) || !hash_equals(self::final_copy_hash($m['fields']), $m['review']['finalCopySha256'])) { throw new \RuntimeException('final_rewritten_copy_review_hash_mismatch'); }
        return ['schemaVersion' => $m['schemaVersion'], 'operationId' => $m['operationId'], 'siteUrl' => $m['siteUrl'], 'environment' => $m['environment'], 'productId' => $m['productId'], 'identity' => $m['identity'], 'review' => ['decision' => $m['review']['decision'], 'reference' => $m['review']['reference'], 'reviewer' => $m['review']['reviewer'], 'identityApproved' => $m['review']['identityApproved'], 'sourceMarket' => $m['review']['sourceMarket'], 'holds' => $m['review']['holds'], 'finalCopySha256' => $m['review']['finalCopySha256'], 'sourceFidelityReviewer' => $m['review']['sourceFidelityReviewer'], 'sourceFidelityReference' => $m['review']['sourceFidelityReference']], 'fields' => $m['fields']];
    }


    /** Review binds every final field proposal, including rewritten copy, without requiring verbatim manufacturer language. */
    public static function final_copy_hash(array $fields): string
    {
        $values = [];
        foreach ($fields as $row) { $values[$row['field']] = $row['proposed']; }
        ksort($values, SORT_STRING);
        return hash('sha256', (string) wp_json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function validate_state(mixed $state): void
    {
        HP_PM_Product_Composition_Fields::keys($state, ['exists', 'value']);
        if (!is_bool($state['exists']) || (!$state['exists'] && $state['value'] !== null) || (!is_null($state['value']) && !is_string($state['value']) && !is_array($state['value']))) { throw new \InvalidArgumentException('Exact absent/null/string/structured before state required.'); }
    }

    public static function identity(int $id): array
    {
        $post = get_post($id);
        return ['sku' => (string) get_post_meta($id, '_sku', true), 'manufacturer' => (string) get_post_meta($id, 'manufacturer_acf', true), 'manufacturerSku' => (string) get_post_meta($id, 'sku_mfr', true), 'sizeEu' => (string) get_post_meta($id, 'bottle_size_eu', true), 'sizeEuUnits' => (string) get_post_meta($id, 'bottle_size_units_eu', true), 'sizeUsa' => (string) get_post_meta($id, 'bottle_size_usa', true), 'sizeUsaUnits' => (string) get_post_meta($id, 'bottle_size_units_usa', true), 'status' => (string) ($post->post_status ?? ''), 'slug' => (string) ($post->post_name ?? '')];
    }

    private static function after_identity(array $m): array
    {
        $identity = $m['identity'];
        $map = ['sku_mfr' => 'manufacturerSku', 'bottle_size_eu' => 'sizeEu', 'bottle_size_units_eu' => 'sizeEuUnits', 'bottle_size_usa' => 'sizeUsa', 'bottle_size_units_usa' => 'sizeUsaUnits'];
        foreach ($m['fields'] as $row) { if (isset($map[$row['field']])) { $identity[$map[$row['field']]] = $row['proposed']; } }
        return $identity;
    }

    private static function state(int $id, string $name): array
    {
        if (in_array($name, self::POST_FIELDS, true)) { return ['exists' => true, 'value' => get_post($id)->$name]; }
        $exists = metadata_exists('post', $id, $name);
        if ($exists && count(get_post_meta($id, $name, false)) !== 1) { throw new \RuntimeException('duplicate_native_meta:' . $name); }
        return ['exists' => $exists, 'value' => $exists ? get_post_meta($id, $name, true) : null];
    }

    private static function reference(int $id, string $name): ?array { return in_array($name, self::POST_FIELDS, true) ? null : self::state($id, '_' . $name); }

    private static function field_definition(string $name): array
    {
        if (!function_exists('acf_get_field') || !function_exists('update_field')) { throw new \RuntimeException('Native ACF field APIs required.'); }
        $definition = acf_get_field($name);
        $own = HP_PM_Product_Composition_Fields::schema()['fields'][$name] ?? null;
        if (!is_array($definition) || ($definition['name'] ?? '') !== $name || !is_string($definition['key'] ?? null) || !str_starts_with($definition['key'], 'field_') || ($own ? (($definition['key'] ?? '') !== $own['key'] || ($definition['type'] ?? '') !== $own['type']) : !in_array($definition['type'] ?? '', ['text', 'textarea', 'wysiwyg', 'number', 'select'], true))) { throw new \RuntimeException('Current exact ACF field unavailable:' . $name); }
        if ($own && isset($own['choices']) && ($definition['choices'] ?? null) !== $own['choices']) { throw new \RuntimeException('Current ACF choices differ:' . $name); }
        return $definition;
    }

    private static function expected_reference(array $row): ?array
    {
        return in_array($row['field'], self::POST_FIELDS, true) ? null : ['exists' => true, 'value' => self::field_definition($row['field'])['key']];
    }

    private static function write(int $id, array $row): void
    {
        if (in_array($row['field'], self::POST_FIELDS, true)) {
            $result = wp_update_post(wp_slash(['ID' => $id, $row['field'] => $row['proposed']]), true);
            if (is_wp_error($result) || (int) $result !== $id) { throw new \RuntimeException('native_post_write_failed'); }
        } else { update_field(self::field_definition($row['field'])['key'], wp_slash($row['proposed']), $id); }
    }

    private static function protected_hash(array $m): string
    {
        $id = $m['productId'];
        $meta = get_post_meta($id);
        foreach ($m['fields'] as $row) { unset($meta[$row['field']], $meta['_' . $row['field']]); }
        // Catalog owns these derived checkpoints; source completion legitimately dirties them.
        unset($meta['_hp_catalog_ingredient_index_state'], $meta['_hp_catalog_ingredient_index_receipt'], $meta['_hp_catalog_categorization_state_v1']);
        ksort($meta);
        $post = get_post($id);
        $postState = [];
        $selected = array_column($m['fields'], 'field');
        foreach (['post_type', 'post_status', 'post_name', 'post_password', 'post_parent', 'post_title', 'post_excerpt', 'post_content'] as $key) { if (!in_array($key, $selected, true)) { $postState[$key] = $post->$key; } }
        $taxonomies = get_object_taxonomies('product'); sort($taxonomies, SORT_STRING);
        $terms = [];
        foreach ($taxonomies as $taxonomy) {
            $ids = wp_get_object_terms($id, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($ids)) { throw new \RuntimeException('protected_terms_unavailable'); }
            $ids = array_map('intval', $ids); sort($ids, SORT_NUMERIC); $terms[$taxonomy] = $ids;
        }
        return hash('sha256', serialize([$postState, $meta, $terms]));
    }

    private static function assert_protected(array $m, string $hash): void { if (self::protected_hash($m) !== $hash) { throw new \RuntimeException('protected_product_state_drift'); } }
    private static function verify_before(array $m, ?string $protected = null): void
    {
        if (self::identity($m['productId']) !== $m['identity']) { throw new \RuntimeException('exact_product_identity_drift'); }
        foreach ($m['fields'] as $row) { if (self::state($m['productId'], $row['field']) !== $row['before'] || self::reference($m['productId'], $row['field']) !== $row['referenceBefore']) { throw new \RuntimeException('before_state_drift:' . $row['field']); } }
        if ($protected !== null) { self::assert_protected($m, $protected); }
    }
    private static function verify_after(array $m, array $journal, bool $strictProtected = true): void
    {
        if (self::identity($m['productId']) !== self::after_identity($m)) { throw new \RuntimeException('after_identity_drift'); }
        if ($strictProtected) { self::assert_protected($m, $journal['protectedHash']); }
        foreach ($m['fields'] as $row) { if (self::state($m['productId'], $row['field']) !== ['exists' => true, 'value' => $row['proposed']] || self::reference($m['productId'], $row['field']) !== self::expected_reference($row)) { throw new \RuntimeException('after_state_drift:' . $row['field']); } }
    }
    private static function checkpoint(string $key, array $journal, array $token): void
    {
        $boundary = self::BOUNDARY; $boundary::assert_current($token);
        update_option($key, $journal, false);
        if (get_option($key) !== $journal) { throw new \RuntimeException('journal_checkpoint_failed'); }
    }

    private static function rollback(array $m, string $key, array $journal, array $token): array
    {
        $boundary = self::BOUNDARY; $id = $m['productId'];
        self::assert_protected($m, $journal['protectedHash']);
        // Validate every owned value and pointer before undoing any row. A crash may leave value and pointer at different owned states.
        foreach ($m['fields'] as $row) {
            $name = $row['field']; $owned = $journal['rows'][$name] ?? null;
            $current = self::state($id, $name); $ref = self::reference($id, $name);
            if ($owned === null) {
                if ($current !== $row['before'] || $ref !== $row['referenceBefore']) { throw new \RuntimeException('rollback_unowned_field_drift:' . $name); }
            } elseif (!in_array($current, [$owned['before'], $owned['after']], true) || !in_array($ref, [$owned['referenceBefore'], $owned['referenceAfter']], true)) { throw new \RuntimeException('rollback_foreign_field_drift:' . $name); }
        }
        $journal['phase'] = 'rollback_intent'; self::checkpoint($key, $journal, $token);
        foreach ($m['fields'] as $row) { if (isset($journal['rows'][$row['field']])) { self::assert_owned_row($id, $row, $journal['rows'][$row['field']]); } }
        foreach (array_reverse($m['fields']) as $row) {
            $name = $row['field']; if (!isset($journal['rows'][$name])) { continue; }
            $boundary::assert_current($token); self::assert_protected($m, $journal['protectedHash']);
            self::assert_owned_row($id, $row, $journal['rows'][$name]);
            if (in_array($name, self::POST_FIELDS, true)) { self::write($id, ['field' => $name, 'proposed' => $row['before']['value']]); }
            else {
                self::restore_meta($id, $name, $row['before'], $journal['rows'][$name]['before'], $journal['rows'][$name]['after'], $token);
                self::restore_meta($id, '_' . $name, $row['referenceBefore'], $journal['rows'][$name]['referenceBefore'], $journal['rows'][$name]['referenceAfter'], $token);
            }
            if (self::state($id, $name) !== $row['before'] || self::reference($id, $name) !== $row['referenceBefore']) { throw new \RuntimeException('rollback_readback_failed:' . $name); }
            $journal['rows'][$name]['phase'] = 'restored'; self::checkpoint($key, $journal, $token);
        }
        self::verify_before($m, $journal['protectedHash']);
        $journal['phase'] = 'rolled_back'; self::checkpoint($key, $journal, $token);
        return ['status' => 'rolled_back', 'productId' => $id, 'operationId' => $m['operationId'], 'journalKey' => $key];
    }

    private static function assert_owned_row(int $id, array $row, array $owned): void
    {
        if (!in_array(self::state($id, $row['field']), [$owned['before'], $owned['after']], true) || !in_array(self::reference($id, $row['field']), [$owned['referenceBefore'], $owned['referenceAfter']], true)) { throw new \RuntimeException('rollback_action_time_field_drift:' . $row['field']); }
    }

    private static function restore_meta(int $id, string $name, array $state, array $before, array $after, array $token): void
    {
        $boundary = self::BOUNDARY; $boundary::assert_current($token);
        $current = self::state($id, $name);
        if (!in_array($current, [$before, $after], true)) { throw new \RuntimeException('rollback_action_time_meta_drift:' . $name); }
        if ($current === $state) { return; }
        if (!$state['exists']) { delete_post_meta($id, $name, $current['value']); }
        elseif (!$current['exists']) { add_post_meta($id, $name, wp_slash($state['value']), true); }
        else { update_post_meta($id, $name, wp_slash($state['value']), $current['value']); }
    }
}
