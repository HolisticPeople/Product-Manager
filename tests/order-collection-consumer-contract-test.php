<?php

$plugin = file_get_contents(dirname(__DIR__) . '/products-manager.php') ?: '';
$fail = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$fail(str_contains($plugin, "function_exists('hp_core_order_collection_snapshot_v1')"), 'Collection V2 must be preferred when available.');
$fail(str_contains($plugin, "['paid', 'overpaid', 'not_required']"), 'Only settled Collection V2 states may classify a sale.');
$fail(str_contains($plugin, "(\$snapshot['confidence'] ?? '') === 'exception'"), 'Exceptional collection evidence must fail closed.');
$fail(!str_contains($plugin, "in_array(\$status, ['on-account']"), 'The legacy order status must not classify a sale.');
$fail(substr_count($plugin, '$this->order_has_verified_collection($order)') === 2, 'Both rebuild paths must use the same collection predicate.');

echo "order collection consumer contract passed\n";
