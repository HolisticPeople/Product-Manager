<?php

$plugin = file_get_contents(__DIR__ . '/../products-manager.php');

if ($plugin === false) {
    fwrite(STDERR, "Unable to read products-manager.php\n");
    exit(1);
}

$expected = [
    'normalize_product_status' => "Product status updates must be checked against an explicit allow-list.",
    'normalize_catalog_visibility' => "Catalog visibility updates must be checked against an explicit allow-list.",
    'normalize_backorders' => "Backorder updates must be checked against an explicit allow-list.",
    'normalize_tax_status' => "Tax status updates must be checked against an explicit allow-list.",
];

foreach ($expected as $symbol => $message) {
    if (strpos($plugin, 'function ' . $symbol . '(') === false) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

if (strpos($plugin, "\$status = sanitize_key((string) \$apply['status']);") !== false) {
    fwrite(STDERR, "Product status must not be normalized inline from request data.\n");
    exit(1);
}

if (strpos($plugin, "\$vis = sanitize_key((string) \$apply['visibility']);") !== false) {
    fwrite(STDERR, "Catalog visibility must not be normalized inline from request data.\n");
    exit(1);
}

if (strpos($plugin, "set_backorders(sanitize_key") !== false) {
    fwrite(STDERR, "Backorders must not pass sanitized request values directly to set_backorders().\n");
    exit(1);
}

if (strpos($plugin, "set_tax_status(sanitize_key") !== false) {
    fwrite(STDERR, "Tax status must not pass sanitized request values directly to set_tax_status().\n");
    exit(1);
}

echo "request boundary contracts passed\n";
