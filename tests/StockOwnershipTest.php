<?php
declare(strict_types=1);

/**
 * Stock ownership contract: HP-Inventory owns stock truth, so Product-Manager
 * must not write stock quantity while HP-Inventory is active — read-only UI
 * with a deep link, plus an explicit REST rejection (fail visibly).
 */

$plugin = file_get_contents(__DIR__ . '/../products-manager.php');

function hp_pm_stock_ownership_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'StockOwnershipTest: ' . $message . PHP_EOL);
        exit(1);
    }
}

hp_pm_stock_ownership_assert(is_string($plugin) && $plugin !== '', 'products-manager.php should be readable.');

// The ownership gate is fail-soft (HP-Inventory absent => editing unchanged)
// and filterable.
hp_pm_stock_ownership_assert(
    strpos($plugin, 'private function is_stock_editing_owned_by_hp_inventory(): bool') !== false
    && strpos($plugin, "apply_filters('hp_pm_stock_editing_disabled', defined('HP_INVENTORY_VERSION'))") !== false,
    'The stock ownership gate must key on HP-Inventory presence behind the hp_pm_stock_editing_disabled filter.'
);

// UI: the Quantity input renders disabled with the HP-Inventory deep link
// when ownership is active, and editable otherwise.
hp_pm_stock_ownership_assert(
    strpos($plugin, 'is_stock_editing_owned_by_hp_inventory()) : ?>') !== false
    && strpos($plugin, 'id="hp-pm-pd-stock-qty" type="number" step="1" class="regular-text" disabled') !== false
    && strpos($plugin, 'hp_inventory_stock_link(absint($_GET[\'product_id\'] ?? 0))') !== false,
    'The Quantity field must render disabled with the HP-Inventory link when ownership is active.'
);
hp_pm_stock_ownership_assert(
    strpos($plugin, "levels_search=' . rawurlencode(\$sku)") !== false
    && strpos($plugin, 'page=hp-inventory&tab=inventory-levels') !== false,
    'The deep link must target the Inventory Levels tab with a levels_search prefill.'
);

// REST: a stock_quantity write is rejected with an explicit error BEFORE
// set_stock_quantity runs — never silently ignored, never applied.
$guardPos = strpos($plugin, "'hp_pm_stock_owned_by_hp_inventory'");
$setPos = strpos($plugin, '$product->set_stock_quantity(');
hp_pm_stock_ownership_assert(
    $guardPos !== false && $setPos !== false && $guardPos < $setPos
    && strpos($plugin, "['status' => 409]") !== false,
    'The apply endpoint must reject stock_quantity with a 409 before any set_stock_quantity call.'
);

echo "StockOwnershipTest passed\n";
