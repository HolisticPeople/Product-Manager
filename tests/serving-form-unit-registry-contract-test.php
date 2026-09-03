<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}

$hpPmRegistryTestGroups = [
    28 => ['key' => 'group_60630bdd97bfa'],
    29 => ['key' => 'group_unrelated'],
];

if (!function_exists('acf_get_raw_field_group')) {
    function acf_get_raw_field_group(int|string $parent): array|false
    {
        global $hpPmRegistryTestGroups;
        return $hpPmRegistryTestGroups[(int) $parent] ?? false;
    }
}

require_once dirname(__DIR__) . '/includes/class-hp-pm-serving-form-unit-registry.php';

function hp_pm_registry_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$baseline = [
    'key' => HP_PM_Serving_Form_Unit_Registry::FIELD_KEY,
    'name' => HP_PM_Serving_Form_Unit_Registry::FIELD_NAME,
    'parent' => HP_PM_Serving_Form_Unit_Registry::FIELD_GROUP,
    'type' => 'select',
    'choices' => [
        'Capsule' => 'Capsule',
        'Drop(s)' => 'Drop(s)',
        'Dropper (1 Dropper~0.75 ml)' => 'Dropper (1 Dropper~0.75 ml)',
        'Scoop' => 'Scoop',
    ],
    'conditional_logic' => 0,
];

$normalized = HP_PM_Serving_Form_Unit_Registry::normalize_field($baseline);
$normalizedAgain = HP_PM_Serving_Form_Unit_Registry::normalize_field($normalized);

hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::BASELINE_CHOICES_SHA256 === 'cfdb958119c5aba5e3b1834861ed869f67ff1774a5000c60ed2a8c7bd1ba3461',
    'The approved persisted pre-choices hash must remain exact.'
);
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::NORMALIZED_CHOICES_SHA256 === '9db6f5a8b0ff2f375577e9da65df74b4a7f33dca27a8aacb8d49ce1fb593ee6e',
    'The approved persisted post-choices hash must remain exact.'
);
hp_pm_registry_assert(strlen(HP_PM_Serving_Form_Unit_Registry::BASELINE_CHOICES_SHA256) === 64, 'The pre-hash must be SHA-256.');
hp_pm_registry_assert(strlen(HP_PM_Serving_Form_Unit_Registry::NORMALIZED_CHOICES_SHA256) === 64, 'The post-hash must be SHA-256.');

$expectedChoices = [
    'Capsule' => 'Capsule',
    'Drop(s)' => 'Drop(s)',
    'Dropper' => 'Dropper',
    'Dropper (1 Dropper~0.75 ml)' => 'Dropper (1 Dropper~0.75 ml)',
    'Scoop' => 'Scoop',
];
hp_pm_registry_assert($normalized['choices'] === $expectedChoices, 'Only the exact plain Dropper choice may be appended after Drop(s).');
hp_pm_registry_assert($normalizedAgain === $normalized, 'Normalization must be idempotent.');
hp_pm_registry_assert(
    ($normalized['conditional_logic'] ?? null) === [[[
        'field' => HP_PM_Serving_Form_Unit_Registry::PRODUCT_TYPE_FIELD_KEY,
        'operator' => '==',
        'value' => HP_PM_Serving_Form_Unit_Registry::SUPPLEMENT_TYPE,
    ]]],
    'The ACF field must be conditional on supplement_type.'
);

$wrongField = $baseline;
$wrongField['key'] = 'field_not_authorized';
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::normalize_field($wrongField) === $wrongField,
    'A field outside the exact key/name/group identity must not change.'
);

$numericParentField = $baseline;
$numericParentField['parent'] = 28;
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::normalize_field($numericParentField)['choices'] === $expectedChoices,
    'The persisted numeric ACF parent may normalize only when its raw group resolves to the governed key.'
);

$numericStringParentField = $baseline;
$numericStringParentField['parent'] = '28';
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::normalize_field($numericStringParentField)['choices'] === $expectedChoices,
    'The persisted numeric-string ACF parent may normalize only through the same governed group resolution.'
);

$unrelatedNumericParentField = $baseline;
$unrelatedNumericParentField['parent'] = 29;
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::normalize_field($unrelatedNumericParentField) === $unrelatedNumericParentField,
    'A numeric ACF parent resolving to an unrelated group must not normalize.'
);

$missingNumericParentField = $baseline;
$missingNumericParentField['parent'] = 30;
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::normalize_field($missingNumericParentField) === $missingNumericParentField,
    'An unresolved numeric ACF parent must not normalize.'
);

$wrongTypeField = $baseline;
$wrongTypeField['type'] = 'text';
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::normalize_field($wrongTypeField) === $wrongTypeField,
    'The centralized identity guard must retain the exact select type requirement.'
);

$bookChanges = [
    'serving_form_unit' => 'Dropper',
    'name' => 'Existing title',
];
$guardedBook = HP_PM_Serving_Form_Unit_Registry::guard_changes_for_product_type($bookChanges, 'book_type');
hp_pm_registry_assert(!array_key_exists('serving_form_unit', $guardedBook), 'Book changes must never retain serving_form_unit.');
hp_pm_registry_assert(($guardedBook['name'] ?? '') === 'Existing title', 'The Book guard must not alter unrelated changes.');
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::guard_changes_for_product_type(['serving_form_unit' => 'Dropper'], 'supplement_type') === ['serving_form_unit' => 'Dropper'],
    'A supplement must preserve the governed Reishi Dropper value.'
);
hp_pm_registry_assert(
    HP_PM_Serving_Form_Unit_Registry::guard_changes_for_product_type(['serving_form_unit' => 'Drop(s)'], 'supplement_type') === ['serving_form_unit' => 'Drop(s)'],
    'A supplement must preserve the governed D3 Drop(s) value.'
);

$registrySource = (string) file_get_contents(dirname(__DIR__) . '/includes/class-hp-pm-serving-form-unit-registry.php');
$pluginSource = (string) file_get_contents(dirname(__DIR__) . '/products-manager.php');
hp_pm_registry_assert(str_contains($registrySource, "hp-products-manager acf-registry serving-form-unit"), 'The bounded WP-CLI command must be registered.');
hp_pm_registry_assert(str_contains($registrySource, "['status', 'apply', 'rollback']"), 'The command must expose status/apply/rollback only.');
hp_pm_registry_assert(str_contains($registrySource, 'acf_update_field('), 'Schema writes must use the WordPress-native ACF field API.');
hp_pm_registry_assert(str_contains($registrySource, 'acf_get_raw_field_group($parentId)'), 'Numeric parents must resolve through the raw ACF field-group API.');
hp_pm_registry_assert(str_contains($registrySource, "self::environment() !== 'staging'"), 'Schema writes must be staging-only.');
hp_pm_registry_assert(str_contains($registrySource, 'hp_pm_serving_form_unit_registry_backup_v1'), 'Apply/rollback must use the complete versioned JSON backup.');
hp_pm_registry_assert(str_contains($registrySource, "'field_sha256' => self::field_hash(\$field)"), 'The complete backed-up field must be checksummed.');
hp_pm_registry_assert(str_contains($registrySource, "'product_meta_writes' => 0"), 'Mutation output must explicitly report zero product-meta writes.');
hp_pm_registry_assert(!str_contains($registrySource, 'update_post_meta('), 'The registry owner must never write product meta.');
hp_pm_registry_assert(!str_contains($registrySource, 'delete_post_meta('), 'The registry owner must never delete product meta.');
hp_pm_registry_assert(!str_contains($registrySource, '0.75'), 'The new registry owner must not encode a 0.75ml mapping.');
hp_pm_registry_assert(str_contains($pluginSource, 'HP_PM_Serving_Form_Unit_Registry::register();'), 'The registry owner must bootstrap from Product Manager.');
hp_pm_registry_assert(
    strpos($pluginSource, 'guard_changes_for_product_type') < strpos($pluginSource, 'if (empty($apply))'),
    'The Book server guard must run before the empty-apply exit.'
);

fwrite(STDOUT, "Serving-form registry contract test passed.\n");
