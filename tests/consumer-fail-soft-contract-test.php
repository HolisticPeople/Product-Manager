<?php
declare(strict_types=1);

$plugin = file_get_contents(dirname(__DIR__) . '/products-manager.php');
$script = file_get_contents(dirname(__DIR__) . '/assets/js/products-page.js');
$detail_script = file_get_contents(dirname(__DIR__) . '/assets/js/product-detail.js');

if (!is_string($plugin) || !is_string($script) || !is_string($detail_script)) {
    fwrite(STDERR, "Unable to read Product Manager location-filter sources.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(
    str_contains($plugin, "'/hp-inventory/v1/location-positions'"),
    'Location data must come from HP-Inventory permission-protected REST contracts.'
);
$assert(
    str_contains($plugin, "!defined('HP_INVENTORY_VERSION')")
        && str_contains($plugin, "!function_exists('rest_do_request')")
        && str_contains($plugin, 'catch (\Throwable $error)')
        && str_contains($plugin, 'product_manager.inventory.location_positions_failed')
        && str_contains($plugin, '$status >= 400'),
    'The HP-Inventory consumer must log and fail soft when the provider or contract is unavailable.'
);
$assert(
    str_contains($plugin, "'role' => sanitize_key")
        && str_contains($plugin, "'is_sellable' => !empty"),
    'Active location metadata must retain quarantine/non-sellable semantics.'
);
$assert(
    str_contains($plugin, "'stock_locations'=> \$stock_locations"),
    'Product rows must expose normalized per-location stock positions.'
);
$assert(
    str_contains($plugin, "\$positions[\$product_id][\$location_id]['available'] += (int) (\$row['available'] ?? 0)")
        && !str_contains($plugin, "\$position['available'] = (int) \$position['qoh']"),
    'Product Manager must preserve HP-Inventory availability instead of recomputing away location sellability.'
);
$assert(
    str_contains($script, 'location_ids')
        && str_contains($script, 'scopeRowToLocations')
        && str_contains($script, 'locationQuantityFormatter')
        && str_contains($script, "label + ' by location"),
    'The products UI must scope quantities and tooltips to the selected locations.'
);

$render_pos = strpos($plugin, 'function render_product_detail_page');
$next_function_pos = $render_pos === false ? false : strpos($plugin, 'function ', $render_pos + 1);
$render_body = ($render_pos !== false && $next_function_pos !== false)
    ? substr($plugin, $render_pos, $next_function_pos - $render_pos)
    : '';

$assert(
    substr_count($plugin, 'hp-inventory/v1/product-history') === 1
        && str_contains($render_body, 'hp-inventory/v1/product-history'),
    'Product history contract URL must appear exactly once, inside render_product_detail_page.'
);
$assert(
    preg_match("/version_compare\\([^\\)]*'2\\.17\\.0'[^\\)]*'>='/s", $render_body) === 1
        && !str_contains($render_body, "HP_INVENTORY_VERSION === '2.17.0'"),
    'Product history availability must use a >= HP-Inventory 2.17.0 floor, not an exact pin.'
);

$tab_pos = strpos($plugin, 'id="tab-erp"');
$button_pos = strpos($plugin, 'hp-pm-erp-rebuild-product');
$erp_tab_prefix = ($tab_pos !== false && $button_pos !== false && $button_pos > $tab_pos)
    ? substr($plugin, $tab_pos, $button_pos - $tab_pos)
    : '';

$assert(
    str_contains($erp_tab_prefix, 'is_hp_inventory_erp_migrated()'),
    'ERP rebuild toolbar must be guarded by is_hp_inventory_erp_migrated() before rendering.'
);
$assert(
    str_contains($plugin, 'Stock & History') && !str_contains($plugin, 'Sales & ERP'),
    'Product detail tab must be labeled Stock & History and must not retain Sales & ERP.'
);
$assert(
    str_contains($detail_script, 'fetch(data.hpInvHistoryUrl')
        && str_contains($detail_script, 'loadLegacyMovements')
        && str_contains($detail_script, '/movements/logs?limit=200'),
    'Product detail JS must fetch HP-Inventory history and retain the legacy movements fallback.'
);

echo "location filter contract checks passed\n";
