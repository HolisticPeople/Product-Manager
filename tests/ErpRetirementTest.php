<?php
declare(strict_types=1);

$plugin = file_get_contents(__DIR__ . '/../products-manager.php');

function hp_pm_erp_retirement_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

hp_pm_erp_retirement_assert(is_string($plugin), 'Unable to read products-manager.php');

hp_pm_erp_retirement_assert(strpos($plugin, 'hp_inventory_product_manager_erp_migrated') !== false, 'Products Manager should check the HP Inventory migration completion option.');
hp_pm_erp_retirement_assert(strpos($plugin, 'is_hp_inventory_erp_migrated') !== false, 'Products Manager should centralize the HP Inventory migration guard.');
hp_pm_erp_retirement_assert(strpos($plugin, 'hp_pm_erp_retired_by_hp_inventory') !== false, 'Products Manager should expose a filter for the HP Inventory ERP retirement guard.');
hp_pm_erp_retirement_assert(strpos($plugin, 'is_erp_enabled') !== false && strpos($plugin, '!$this->is_hp_inventory_erp_migrated()') !== false, 'ERP hooks should be disabled after migration to HP Inventory.');
hp_pm_erp_retirement_assert(strpos($plugin, 'is_erp_persist_enabled') !== false && strpos($plugin, 'Product-Manager ERP persistence is retired') !== false, 'ERP persistence should be disabled with an explicit retirement message.');
hp_pm_erp_retirement_assert(strpos($plugin, 'rest_erp_retired_response') !== false, 'ERP rebuild REST endpoints should share a retired response.');
hp_pm_erp_retirement_assert(strpos($plugin, 'rest_rebuild_all_start') !== false && strpos($plugin, 'rest_erp_retired_response') !== false, 'ERP rebuild start should be blocked after HP Inventory migration.');
hp_pm_erp_retirement_assert(strpos($plugin, 'maybe_install_tables') !== false && strpos($plugin, 'is_hp_inventory_erp_migrated') !== false, 'Legacy ERP tables should stop installing after HP Inventory migration.');

echo "ErpRetirementTest passed\n";
