<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function hp_pm_old2new_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$plugin = (string) file_get_contents($root . '/products-manager.php');
$contract = (string) file_get_contents($root . '/hp-contract.json');
$readme = (string) file_get_contents($root . '/README.md');

$files = [
    'assets/css/old2new-product-block.css',
    'assets/css/old2new-admin.css',
    'assets/js/old2new-admin.js',
];

foreach ($files as $file) {
    hp_pm_old2new_assert(file_exists($root . '/' . $file), "{$file} must exist.");
}

$frontend_css = (string) file_get_contents($root . '/assets/css/old2new-product-block.css');
$admin_css = (string) file_get_contents($root . '/assets/css/old2new-admin.css');
$admin_js = (string) file_get_contents($root . '/assets/js/old2new-admin.js');

hp_pm_old2new_assert(str_contains($plugin, 'Version: 2.0.9'), 'Product Manager plugin header must bump to 2.0.9.');
hp_pm_old2new_assert(str_contains($plugin, "const VERSION = '2.0.9'"), 'Product Manager VERSION constant must bump to 2.0.9.');

hp_pm_old2new_assert(str_contains($plugin, "register_post_type('hp_old2new_packet'"), 'Product Manager must register hidden hp_old2new_packet CPT.');
hp_pm_old2new_assert(str_contains($plugin, "'public' => false"), 'Old2New packet CPT must not be public.');
hp_pm_old2new_assert(str_contains($plugin, "'show_ui' => false"), 'Old2New packet CPT UI must stay hidden behind Product Manager admin.');

hp_pm_old2new_assert(str_contains($plugin, "register_shortcode('old2new_product_block'"), 'HP-Core shortcode registry must expose old2new_product_block.');
hp_pm_old2new_assert(str_contains($plugin, "add_shortcode('old2new_product_block'"), 'Direct WordPress shortcode fallback must expose old2new_product_block.');
hp_pm_old2new_assert(str_contains($plugin, 'render_old2new_product_block'), 'Product Manager must own Old2New shortcode rendering.');

hp_pm_old2new_assert(str_contains($plugin, "'/old2new-packets'"), 'Product Manager must expose REST list/create endpoint for Old2New packets.');
hp_pm_old2new_assert(str_contains($plugin, "'/old2new-packets/(?P<id>\\d+)'"), 'Product Manager must expose REST update/delete endpoint for Old2New packets.');
hp_pm_old2new_assert(str_contains($plugin, "'/old2new-products/search'"), 'Product Manager must expose REST product lookup for Old2New editors.');
hp_pm_old2new_assert(str_contains($plugin, "current_user_can('manage_woocommerce')"), 'Old2New write endpoints must require manage_woocommerce.');
hp_pm_old2new_assert(str_contains($plugin, 'sanitize_old2new_status'), 'Old2New lifecycle status must be allow-list sanitized.');
hp_pm_old2new_assert(str_contains($plugin, 'old2new_redirect_type'), 'Old2New response must expose derived redirect type.');

hp_pm_old2new_assert(str_contains($plugin, 'old2new_product_pairs'), 'Legacy old2new_product_pairs meta fallback/migration must remain readable.');
hp_pm_old2new_assert(str_contains($plugin, 'maybe_import_default_old2new_packets'), 'Real QA/default packet import must be idempotent.');
hp_pm_old2new_assert(str_contains($plugin, 'NTI-O-Mega-Zen'), 'Default packet import must include NTI-O-Mega-Zen.');
hp_pm_old2new_assert(str_contains($plugin, 'NTI-O-Mega-Zen-EPA'), 'Default packet import must include NTI-O-Mega-Zen-EPA.');
hp_pm_old2new_assert(str_contains($plugin, 'WA-1650'), 'Default packet import must include WA-1650.');
hp_pm_old2new_assert(str_contains($plugin, 'HD-NCMC30') && str_contains($plugin, 'HD-NCMC60') && str_contains($plugin, 'HD-NXMC2'), 'Default packet import must include WA replacement SKUs.');

hp_pm_old2new_assert(str_contains($plugin, 'Stock:'), 'Old2New product cards must render Stock labels.');
hp_pm_old2new_assert(str_contains($frontend_css, '60px'), 'Old2New frontend cards must use 60px thumbnails.');
hp_pm_old2new_assert(str_contains($frontend_css, '-webkit-line-clamp: 2'), 'Old2New frontend titles must clamp to 2 lines.');
hp_pm_old2new_assert(str_contains($frontend_css, '260px'), 'Old2New frontend cards must cap around 260px.');
hp_pm_old2new_assert(str_contains($frontend_css, '--hp-zen-'), 'Old2New frontend CSS must use HP-Zen token fallbacks.');

hp_pm_old2new_assert(str_contains($admin_js, 'hp-old2new-table'), 'Old2New admin JS must render the packet table.');
hp_pm_old2new_assert(str_contains($admin_js, 'old_product') && str_contains($admin_js, 'new_products'), 'Old2New admin JS must render old/new product columns.');
hp_pm_old2new_assert(str_contains($admin_js, 'Stock:'), 'Old2New admin cards must show stock labels.');
hp_pm_old2new_assert(str_contains($admin_js, 'Edit') && str_contains($admin_js, 'Delete'), 'Old2New admin JS must expose edit/delete actions.');
hp_pm_old2new_assert(str_contains($admin_js, 'confirm('), 'Old2New delete must require confirmation.');
hp_pm_old2new_assert(str_contains($admin_css, 'hp-old2new-product-card'), 'Old2New admin CSS must style product cards.');

hp_pm_old2new_assert(str_contains($contract, '"hp_old2new_packet"'), 'hp-contract must expose hp_old2new_packet CPT.');
hp_pm_old2new_assert(str_contains($contract, '"old2new_product_block"'), 'hp-contract must expose old2new_product_block shortcode.');
hp_pm_old2new_assert(str_contains($contract, 'hp-products-manager/v1/old2new-packets'), 'hp-contract must expose Old2New packet REST routes.');
hp_pm_old2new_assert(str_contains($readme, '2.0.9'), 'README release notes must include 2.0.9.');

echo "Old2New Product Manager contract passed\n";
