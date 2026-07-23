<?php
declare(strict_types=1);

$plugin = file_get_contents(dirname(__DIR__) . '/products-manager.php');
$script = file_get_contents(dirname(__DIR__) . '/assets/js/products-page.js');

if (!is_string($plugin) || !is_string($script)) {
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
    str_contains($script, 'location_ids')
        && str_contains($script, 'scopeRowToLocations')
        && str_contains($script, 'locationQuantityFormatter')
        && str_contains($script, "label + ' by location"),
    'The products UI must scope quantities and tooltips to the selected locations.'
);

echo "location filter contract checks passed\n";
