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
$parking_lot = (string) file_get_contents($root . '/docs/plan/parking-lot.md');

$files = [
    'assets/css/old2new-product-block.css',
    'assets/css/old2new-admin.css',
    'assets/js/old2new-admin.js',
    'assets/js/old2new-frontend.js',
    'docs/plan/old2new-product-lifecycle-roadmap.md',
];

foreach ($files as $file) {
    hp_pm_old2new_assert(file_exists($root . '/' . $file), "{$file} must exist.");
}

$frontend_css = (string) file_get_contents($root . '/assets/css/old2new-product-block.css');
$admin_css = (string) file_get_contents($root . '/assets/css/old2new-admin.css');
$admin_js = (string) file_get_contents($root . '/assets/js/old2new-admin.js');
$frontend_js = (string) file_get_contents($root . '/assets/js/old2new-frontend.js');
$roadmap = (string) file_get_contents($root . '/docs/plan/old2new-product-lifecycle-roadmap.md');

hp_pm_old2new_assert(str_contains($plugin, 'Version: 2.1.1'), 'Product Manager plugin header must bump to 2.1.1.');
hp_pm_old2new_assert(str_contains($plugin, "const VERSION = '2.1.1'"), 'Product Manager VERSION constant must bump to 2.1.1.');

hp_pm_old2new_assert(str_contains($plugin, "register_post_type('hp_old2new_packet'"), 'Product Manager must register hidden hp_old2new_packet CPT.');
hp_pm_old2new_assert(str_contains($plugin, "'public' => false"), 'Old2New packet CPT must not be public.');
hp_pm_old2new_assert(str_contains($plugin, "'show_ui' => false"), 'Old2New packet CPT UI must stay hidden behind Product Manager admin.');

hp_pm_old2new_assert(str_contains($plugin, "register_shortcode('old2new_product_block'"), 'HP-Core shortcode registry must expose old2new_product_block.');
hp_pm_old2new_assert(str_contains($plugin, "add_shortcode('old2new_product_block'"), 'Direct WordPress shortcode fallback must expose old2new_product_block.');
hp_pm_old2new_assert(str_contains($plugin, 'render_old2new_product_block'), 'Product Manager must own Old2New shortcode rendering.');

hp_pm_old2new_assert(str_contains($plugin, "'/old2new-packets'"), 'Product Manager must expose REST list/create endpoint for Old2New packets.');
hp_pm_old2new_assert(str_contains($plugin, "'/old2new-packets/(?P<id>\\d+)'"), 'Product Manager must expose REST update/delete endpoint for Old2New packets.');
hp_pm_old2new_assert(str_contains($plugin, "'/old2new-products/search'"), 'Product Manager must expose REST product lookup for Old2New editors.');
hp_pm_old2new_assert(str_contains($plugin, "'/old2new-badges'"), 'Product Manager must expose public Old2New badge lookup endpoint.');
hp_pm_old2new_assert(str_contains($plugin, "current_user_can('manage_woocommerce')"), 'Old2New write endpoints must require manage_woocommerce.');
hp_pm_old2new_assert(str_contains($plugin, 'sanitize_old2new_status'), 'Old2New lifecycle status must be allow-list sanitized.');
hp_pm_old2new_assert(str_contains($plugin, 'old2new_redirect_type'), 'Old2New response must expose derived redirect type.');
hp_pm_old2new_assert(str_contains($plugin, "'replace' => 'basic_discontinue'") && str_contains($plugin, "'discontinue' => 'canonical'"), 'Old2New must normalize legacy status values.');
hp_pm_old2new_assert(str_contains($plugin, 'maybe_migrate_old2new_statuses'), 'Old2New must migrate legacy status values idempotently.');
hp_pm_old2new_assert(str_contains($plugin, 'OLD2NEW_BANNER_WINDOW_DAYS = 180'), 'Old2New hard redirect banner window must be 180 days.');
hp_pm_old2new_assert(str_contains($plugin, 'filter_old2new_canonical_url'), 'Old2New must expose canonical filter behavior.');
hp_pm_old2new_assert(str_contains($plugin, 'maybe_redirect_old2new_product'), 'Old2New must expose 301 redirect behavior.');
hp_pm_old2new_assert(str_contains($plugin, 'old2new_select_target_product'), 'Old2New must select canonical/redirect target by total_sales.');

hp_pm_old2new_assert(str_contains($plugin, 'old2new_product_pairs'), 'Legacy old2new_product_pairs meta fallback/migration must remain readable.');
hp_pm_old2new_assert(str_contains($plugin, 'maybe_import_default_old2new_packets'), 'Real QA/default packet import must be idempotent.');
hp_pm_old2new_assert(str_contains($plugin, 'NTI-O-Mega-Zen'), 'Default packet import must include NTI-O-Mega-Zen.');
hp_pm_old2new_assert(str_contains($plugin, 'NTI-O-Mega-Zen-EPA'), 'Default packet import must include NTI-O-Mega-Zen-EPA.');
hp_pm_old2new_assert(str_contains($plugin, 'WA-1650'), 'Default packet import must include WA-1650.');
hp_pm_old2new_assert(str_contains($plugin, 'HD-NCMC30') && str_contains($plugin, 'HD-NCMC60') && str_contains($plugin, 'HD-NXMC2'), 'Default packet import must include WA replacement SKUs.');

hp_pm_old2new_assert(str_contains($plugin, 'Stock:'), 'Old2New product cards must render Stock labels.');
hp_pm_old2new_assert(!str_contains($plugin, '<span class="%6$s__stock">'), 'Old2New frontend shortcode cards must not render stock labels.');
hp_pm_old2new_assert(str_contains($frontend_css, '60px'), 'Old2New frontend cards must use 60px thumbnails.');
hp_pm_old2new_assert(str_contains($frontend_css, '-webkit-line-clamp: 2'), 'Old2New frontend titles must clamp to 2 lines.');
hp_pm_old2new_assert(str_contains($frontend_css, '260px'), 'Old2New frontend cards must cap around 260px.');
hp_pm_old2new_assert(str_contains($frontend_css, '--hp-zen-'), 'Old2New frontend CSS must use HP-Zen token fallbacks.');
hp_pm_old2new_assert(str_contains($frontend_css, 'grid-template-columns: minmax(220px, 1fr) minmax(420px, auto)'), 'Old2New banner must keep desktop copy-left/product-flow-right layout.');
hp_pm_old2new_assert(str_contains($frontend_css, 'justify-content: end'), 'Old2New product flow must align to the right on desktop.');
hp_pm_old2new_assert(str_contains($frontend_css, '.old2new-product-badge'), 'Old2New compact badge CSS must exist.');
hp_pm_old2new_assert(!str_contains($frontend_css, '.old2new-product-card__stock'), 'Old2New frontend CSS must not expose public stock styling.');
hp_pm_old2new_assert(str_contains($plugin, '&rarr;'), 'Old2New CTA must be arrow-only.');
hp_pm_old2new_assert(!str_contains($plugin, 'Click here'), 'Old2New CTA copy must not reintroduce Click here text.');
hp_pm_old2new_assert(str_contains($plugin, '_hp_old2new_custom_old_message'), 'Old2New packets must store custom old banner message.');
hp_pm_old2new_assert(str_contains($plugin, '_hp_old2new_custom_new_message'), 'Old2New packets must store custom new banner message.');
hp_pm_old2new_assert(str_contains($plugin, '_hp_old2new_badge_text'), 'Old2New packets must store custom compact badge text.');
hp_pm_old2new_assert(str_contains($plugin, '{old_product}') && str_contains($plugin, '{new_products}'), 'Old2New messages must support product placeholders.');
hp_pm_old2new_assert(str_contains($plugin, 'health_warnings'), 'Old2New packet REST payload must include health warnings.');
hp_pm_old2new_assert(str_contains($plugin, 'target_product') && str_contains($plugin, 'target_reason'), 'Old2New packet REST payload must include target preview.');
hp_pm_old2new_assert(str_contains($plugin, 'banner_expires_at') && str_contains($plugin, 'banner_expired'), 'Old2New packet REST payload must include banner expiry state.');

hp_pm_old2new_assert(str_contains($admin_js, 'hp-old2new-table'), 'Old2New admin JS must render the packet table.');
hp_pm_old2new_assert(str_contains($admin_js, 'old_product') && str_contains($admin_js, 'new_products'), 'Old2New admin JS must render old/new product columns.');
hp_pm_old2new_assert(str_contains($admin_js, 'Stock:'), 'Old2New admin cards must show stock labels.');
hp_pm_old2new_assert(str_contains($admin_js, 'Edit') && str_contains($admin_js, 'Delete'), 'Old2New admin JS must expose edit/delete actions.');
hp_pm_old2new_assert(str_contains($admin_js, 'confirm('), 'Old2New delete must require confirmation.');
hp_pm_old2new_assert(str_contains($admin_js, 'health_warnings'), 'Old2New admin JS must render health warnings.');
hp_pm_old2new_assert(str_contains($admin_js, 'target_product'), 'Old2New admin JS must render target preview.');
hp_pm_old2new_assert(str_contains($admin_js, 'custom_old_message') && str_contains($admin_js, 'custom_new_message') && str_contains($admin_js, 'badge_text'), 'Old2New admin JS must save custom text fields.');
hp_pm_old2new_assert(str_contains($admin_css, 'hp-old2new-product-card'), 'Old2New admin CSS must style product cards.');
hp_pm_old2new_assert(str_contains($admin_css, 'hp-old2new-guidelines'), 'Old2New admin CSS must style the guidelines panel.');
hp_pm_old2new_assert(str_contains($admin_css, '.hp-old2new-form select option'), 'Old2New admin CSS must style select options for dark themes.');
hp_pm_old2new_assert(str_contains($admin_css, '--hp-admin-input-bg'), 'Old2New admin form controls must consume HP admin input tokens.');
hp_pm_old2new_assert(str_contains($frontend_js, 'HPOld2NewFrontendData'), 'Old2New frontend JS must use Product Manager badge config.');
hp_pm_old2new_assert(str_contains($frontend_js, 'old2new-badges') || str_contains($frontend_js, 'badgeUrl'), 'Old2New frontend JS must call the Product Manager badge endpoint.');
hp_pm_old2new_assert(str_contains($frontend_js, 'dgwt-wcas') || str_contains($frontend_js, 'fibo'), 'Old2New frontend JS must decorate Fibo/autocomplete surfaces.');

hp_pm_old2new_assert(str_contains($contract, '"hp_old2new_packet"'), 'hp-contract must expose hp_old2new_packet CPT.');
hp_pm_old2new_assert(str_contains($contract, '"old2new_product_block"'), 'hp-contract must expose old2new_product_block shortcode.');
hp_pm_old2new_assert(str_contains($contract, 'hp-products-manager/v1/old2new-packets'), 'hp-contract must expose Old2New packet REST routes.');
hp_pm_old2new_assert(str_contains($contract, 'hp-products-manager/v1/old2new-badges'), 'hp-contract must expose Old2New badge REST route.');
hp_pm_old2new_assert(str_contains($readme, '2.1.1'), 'README release notes must include 2.1.1.');
hp_pm_old2new_assert(str_contains($parking_lot, 'old2new-product-lifecycle-roadmap.md'), 'Product Manager parking lot must point to the Old2New lifecycle roadmap.');
hp_pm_old2new_assert(str_contains($roadmap, 'Product Manager owns'), 'Old2New lifecycle roadmap must name Product Manager ownership.');
hp_pm_old2new_assert(str_contains($roadmap, 'HP-UI'), 'Old2New lifecycle roadmap must document that HP-UI is no longer the owner.');
hp_pm_old2new_assert(str_contains($roadmap, 'basic_discontinue') && str_contains($roadmap, 'canonical') && str_contains($roadmap, 'hard_redirect'), 'Old2New lifecycle roadmap must capture all lifecycle statuses.');
hp_pm_old2new_assert(str_contains($roadmap, 'canonical') && str_contains($roadmap, '301'), 'Old2New lifecycle roadmap must capture future canonical and 301 behavior.');
hp_pm_old2new_assert(str_contains($roadmap, 'total_sales'), 'Old2New lifecycle roadmap must capture total_sales target selection.');
hp_pm_old2new_assert(str_contains($roadmap, '180 days'), 'Old2New lifecycle roadmap must capture the 180-day new-product banner window.');
hp_pm_old2new_assert(str_contains($roadmap, 'Fibo/search') && str_contains($roadmap, 'category list'), 'Old2New lifecycle roadmap must capture future search and category-list visibility slices.');

echo "Old2New Product Manager contract passed\n";
