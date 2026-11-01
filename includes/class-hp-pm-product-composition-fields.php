<?php
/** Brand-neutral product composition fields; no content migration. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

final class HP_PM_Product_Composition_Fields
{
    public const VERSION = '1.0.0';
    public const METHODS = ['aromatic' => 'Aromatic', 'topical_external' => 'Topical / external', 'internal' => 'Internal', 'home_environment' => 'Home / environment'];
    public const PREPARATIONS = ['essential_oil' => 'Essential oil', 'essential_oil_blend' => 'Essential-oil blend', 'diluted_essential_oil' => 'Diluted essential oil', 'carrier_oil' => 'Carrier oil', 'nutritional_oil' => 'Nutritional oil'];

    public static function schema(): array
    {
        return ['version' => self::VERSION, 'fields' => [
            'application_methods' => ['key' => 'field_hp_pm_application_methods_v1', 'type' => 'checkbox', 'choices' => self::METHODS],
            'oil_preparation' => ['key' => 'field_hp_pm_oil_preparation_v1', 'type' => 'select', 'choices' => self::PREPARATIONS],
            'natural_constituents' => ['key' => 'field_hp_pm_natural_constituents_v1', 'type' => 'hp_natural_constituents'],
        ]];
    }

    public static function register(): void
    {
        add_action('acf/include_field_types', [self::class, 'register_constituent_type']);
        add_action('acf/init', [self::class, 'register_fields']);
        foreach (array_keys(self::schema()['fields']) as $name) {
            add_filter('acf/validate_value/name=' . $name, static function ($valid, $value) use ($name) {
                if ($valid !== true) { return $valid; }
                try { self::validate($name, self::submitted_value($name, $value)); return true; }
                catch (\InvalidArgumentException $e) { return $e->getMessage(); }
            }, 10, 2);
            add_filter('acf/update_value/name=' . $name, static fn($value) => self::validate($name, self::submitted_value($name, $value)));
        }
    }

    public static function register_constituent_type(): void
    {
        if (class_exists('acf_field') && !class_exists('HP_PM_ACF_Natural_Constituents')) {
            require_once __DIR__ . '/class-hp-pm-acf-natural-constituents.php';
            new HP_PM_ACF_Natural_Constituents();
        }
    }

    public static function register_fields(): void
    {
        if (!function_exists('acf_add_local_field_group')) { return; }
        $fields = [];
        foreach (self::schema()['fields'] as $name => $definition) {
            $fields[] = $definition + ['name' => $name, 'label' => ucwords(str_replace('_', ' ', $name)), 'required' => 0, 'allow_null' => 1, 'return_format' => 'value'];
        }
        acf_add_local_field_group(['key' => 'group_hp_pm_product_composition_v1', 'title' => 'Application and natural composition', 'fields' => $fields, 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]], 'position' => 'normal', 'active' => true, 'show_in_rest' => 0]);
    }

    private static function submitted_value(string $name, mixed $value): mixed
    {
        // Native ACF's unselected checkbox/select sends an empty string.
        if ($name === 'application_methods' && $value === '') { return []; }
        if ($name === 'natural_constituents' && is_string($value)) {
            try { return json_decode($value, true, 10, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new \InvalidArgumentException('Natural constituents must be a valid JSON list.'); }
        }
        return $value;
    }

    public static function validate(string $name, mixed $value): mixed
    {
        if ($name === 'oil_preparation') {
            if (!is_string($value) || ($value !== '' && !array_key_exists($value, self::PREPARATIONS))) { throw new \InvalidArgumentException('Unknown oil preparation.'); }
            return $value;
        }
        if ($name === 'application_methods') {
            if (!is_array($value) || !array_is_list($value) || count($value) > 4) { throw new \InvalidArgumentException('Application methods must be a list of known methods.'); }
            $seen = [];
            foreach ($value as $method) {
                if (!is_string($method) || !array_key_exists($method, self::METHODS) || isset($seen[$method])) { throw new \InvalidArgumentException('Unknown or repeated application method.'); }
                $seen[$method] = true;
            }
            return $value;
        }
        if ($name !== 'natural_constituents' || !is_array($value) || !array_is_list($value) || count($value) > 50) { throw new \InvalidArgumentException('Natural constituents must be a bounded structured list.'); }
        $seen = [];
        foreach ($value as $row) {
            self::keys($row, ['sourceName', 'canonicalIngredient', 'chemicalClass', 'amount', 'species', 'isomer']);
            foreach (['sourceName', 'chemicalClass', 'amount', 'species', 'isomer'] as $key) { self::text($row[$key], $key === 'sourceName' ? 200 : 120); }
            $identity = strtolower($row['sourceName']);
            if (isset($seen[$identity])) { throw new \InvalidArgumentException('Repeated constituent source name.'); }
            $seen[$identity] = true;
            if ($row['canonicalIngredient'] !== null) {
                $ref = $row['canonicalIngredient'];
                self::keys($ref, ['termId', 'slug', 'name', 'reviewRef']);
                if (!is_int($ref['termId']) || $ref['termId'] < 1) { throw new \InvalidArgumentException('Resolved ingredient needs an exact native term ID.'); }
                foreach (['slug', 'name', 'reviewRef'] as $key) { self::text($ref[$key], 200); }
            }
        }
        if (strlen(serialize($value)) > 64000) { throw new \InvalidArgumentException('Natural constituent data is too large.'); }
        return $value;
    }

    public static function keys(mixed $row, array $keys): void
    {
        if (!is_array($row) || count($row) !== count($keys) || array_diff(array_keys($row), $keys) || array_diff($keys, array_keys($row))) { throw new \InvalidArgumentException('Unexpected structured field shape.'); }
    }

    public static function text(mixed $value, int $limit): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > $limit * 4 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match_all('/./us', $value) > $limit) { throw new \InvalidArgumentException('Expected bounded nonempty UTF-8 text; use UNKNOWN for missing facts.'); }
    }


    /** Product-Manager details mirror native fields; editing remains the native ACF form or finite reviewed operator. */
    public static function render_admin_rows(int $productId): string
    {
        $html = '';
        foreach (self::schema()['fields'] as $name => $definition) {
            $value = metadata_exists('post', $productId, $name) ? get_post_meta($productId, $name, true) : null;
            if ($name === 'natural_constituents') { $display = $value === null ? 'Not recorded' : (string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); }
            elseif (is_array($value)) { $display = implode(', ', array_map(static fn($key) => $definition['choices'][$key] ?? (string) $key, $value)); }
            else { $display = $value === null ? 'Not recorded' : ($definition['choices'][$value] ?? (string) $value); }
            $html .= '<tr><th scope="row">' . esc_html(ucwords(str_replace('_', ' ', $name))) . '</th><td><pre style="white-space:pre-wrap">' . esc_html($display) . '</pre></td></tr>';
        }
        return $html . '<tr><th scope="row">Edit composition</th><td><a href="' . esc_url(get_edit_post_link($productId, 'raw')) . '">Open native product fields</a></td></tr>';
    }

    /** Public source summary, not relationship/claim approval. */
    public static function summary(int $productId): array
    {
        $unavailable = ['version' => self::VERSION, 'status' => 'unavailable', 'productId' => $productId, 'fields' => []];
        try { return self::read_summary($productId, $unavailable); } catch (\Throwable $e) { return $unavailable; }
    }

    private static function boundary_available(): bool
    {
        return defined('HP_Catalog\\Product_Source_Update::VERSION') && constant('HP_Catalog\\Product_Source_Update::VERSION') === '1.0.0' && is_callable(['\\HP_Catalog\\Product_Source_Update', 'active']);
    }

    private static function source_snapshot(int $productId): array
    {
        $snapshot = ['postType' => get_post_type($productId), 'postStatus' => get_post_status($productId), 'postPassword' => (string) (get_post($productId)->post_password ?? ''), 'fields' => []];
        foreach (self::schema()['fields'] as $name => $_) { $snapshot['fields'][$name] = ['values' => get_post_meta($productId, $name, false), 'references' => get_post_meta($productId, '_' . $name, false)]; }
        return $snapshot;
    }

    private static function read_summary(int $productId, array $unavailable): array
    {
        if ($productId < 1 || !function_exists('get_post_type') || get_post_type($productId) !== 'product' || get_post_status($productId) !== 'publish' || (string) (get_post($productId)->post_password ?? '') !== '') { return $unavailable; }
        if (!self::boundary_available() || \HP_Catalog\Product_Source_Update::active($productId)) { return $unavailable; }
        $snapshot = self::source_snapshot($productId);
        if ($snapshot['postType'] !== 'product' || $snapshot['postStatus'] !== 'publish' || $snapshot['postPassword'] !== '' || \HP_Catalog\Product_Source_Update::active($productId)) { return $unavailable; }
        $fields = [];
        foreach (self::schema()['fields'] as $name => $definition) {
            $state = $snapshot['fields'][$name];
            if ($state['references'] !== [$definition['key']] || count($state['values']) !== 1) { continue; }
            try { $fields[$name] = self::validate($name, $state['values'][0]); }
            catch (\InvalidArgumentException $e) { continue; }
        }
        foreach ($fields['natural_constituents'] ?? [] as $index => $row) {
            $ref = $row['canonicalIngredient'];
            if ($ref !== null) {
                $term = function_exists('get_term') ? get_term($ref['termId'], 'product_cat') : null;
                if (!$term || (function_exists('is_wp_error') && is_wp_error($term)) || ($term->slug ?? '') !== $ref['slug'] || ($term->name ?? '') !== $ref['name']) { $fields['natural_constituents'][$index]['canonicalIngredient'] = null; }
                else {
                    $eligible = false;
                    if (function_exists('apply_filters')) {
                        try { $result = apply_filters('hp_catalog_ingredient_summaries_v1', null, ['term_ids' => [$ref['termId']]]); foreach (($result['results'] ?? []) as $candidate) { if (($result['schemaVersion'] ?? '') === 'hp_catalog_ingredient_summary_v1' && ($candidate['termId'] ?? 0) === $ref['termId'] && ($candidate['status'] ?? '') === 'resolved' && ($candidate['canonical']['slug'] ?? '') === $ref['slug'] && ($candidate['canonical']['name'] ?? '') === $ref['name']) { $eligible = true; } } } catch (\Throwable $e) { $eligible = false; }
                    }
                    if (!$eligible) { $fields['natural_constituents'][$index]['canonicalIngredient'] = null; }
                    else { unset($fields['natural_constituents'][$index]['canonicalIngredient']['reviewRef']); }
                }
            }
        }
        if (!self::boundary_available() || \HP_Catalog\Product_Source_Update::active($productId) || self::source_snapshot($productId) !== $snapshot || \HP_Catalog\Product_Source_Update::active($productId)) { return $unavailable; }
        return ['version' => self::VERSION, 'status' => 'available', 'productId' => $productId, 'fields' => $fields];
    }
}

function hp_pm_product_composition_schema_v1(): array { return HP_PM_Product_Composition_Fields::schema(); }
function hp_pm_product_composition_v1(int $productId): array { return HP_PM_Product_Composition_Fields::summary($productId); }

function hp_pm_validate_product_composition_v1(string $name, mixed $value): mixed { return HP_PM_Product_Composition_Fields::validate($name, $value); }
