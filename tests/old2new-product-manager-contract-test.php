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
    'docs/plan/old2new-product-lifecycle-roadmap.md',
];

foreach ($files as $file) {
    hp_pm_old2new_assert(file_exists($root . '/' . $file), "{$file} must exist.");
}

$frontend_css = (string) file_get_contents($root . '/assets/css/old2new-product-block.css');
$admin_css = (string) file_get_contents($root . '/assets/css/old2new-admin.css');
$admin_js = (string) file_get_contents($root . '/assets/js/old2new-admin.js');
$roadmap = (string) file_get_contents($root . '/docs/plan/old2new-product-lifecycle-roadmap.md');

hp_pm_old2new_assert(str_contains($plugin, 'Version: 2.3.1'), 'Product Manager plugin header must bump to 2.3.1.');
hp_pm_old2new_assert(str_contains($plugin, "const VERSION = '2.3.1'"), 'Product Manager VERSION constant must bump to 2.3.1.');

// 2.3.1 UPC/GTIN input validation — checksum + length gate before a value is stored.
hp_pm_old2new_assert(str_contains($plugin, 'private static function gtin_checksum_ok'), 'Server must expose a GS1 checksum validator.');
hp_pm_old2new_assert(str_contains($plugin, '!self::gtin_checksum_ok($gtin_digits)'), 'Apply handler must reject an invalid GTIN check digit before writing.');
hp_pm_old2new_assert(preg_match('/\(\(10 - \$sum % 10\) % 10\) === \$check/', $plugin) === 1, 'Checksum must use GS1 mod-10 (not Luhn).');
$pm_js_v = (string) file_get_contents($root . '/assets/js/product-detail.js');
hp_pm_old2new_assert(str_contains($pm_js_v, 'function gtinChecksumOk') && str_contains($pm_js_v, 'function validateGtin'), 'Client must validate GTIN checksum + length.');
hp_pm_old2new_assert(str_contains($pm_js_v, "if (!vg.ok) { alert(vg.error); return; }"), 'Staging must be blocked when the GTIN is invalid.');

// 2.3.0 UPC/GTIN field — must be the WC-native single source of truth, never a parallel meta key.
hp_pm_old2new_assert(str_contains($plugin, "'country_of_manufacturer', 'gtin',"), 'gtin must be in the apply allowlist (Ingredients & Mfg group).');
hp_pm_old2new_assert(str_contains($plugin, "\$product->set_global_unique_id(\$gtin_digits)"), 'gtin must be written to WC native _global_unique_id via set_global_unique_id(), NOT a plain meta key.');
hp_pm_old2new_assert(!preg_match("/update_post_meta\(\\\$id, 'gtin'/", $plugin), 'gtin must NOT be written as its own post meta (that would drift from _global_unique_id).');
hp_pm_old2new_assert(preg_match("/in_array\(strlen\(\\\$gtin_digits\), \[8, 12, 13, 14\]/", $plugin) === 1, 'gtin must be length-validated (8/12/13/14 digits) before write.');
hp_pm_old2new_assert(str_contains($plugin, "catch (\\WC_Data_Exception \$e)"), 'gtin write must catch the WC duplicate-GTIN exception so one duplicate does not abort the save.');
hp_pm_old2new_assert(str_contains($plugin, "render_acf_field('gtin'"), 'The UPC/GTIN field must render in the product editor.');
hp_pm_old2new_assert(str_contains($plugin, "'gtin'                    => get_post_meta(\$id, '_global_unique_id', true)"), 'Read/apply snapshots must expose gtin from _global_unique_id.');
$pm_js = (string) file_get_contents($root . '/assets/js/product-detail.js');
hp_pm_old2new_assert(str_contains($pm_js, "'country_of_manufacturer', 'gtin',"), 'JS metaKeys must include gtin so it hydrates and gathers.');

// 2.1.9 production-review fixes.
// F1: variations must be covered by purchasability + parent-SKU fallback.
hp_pm_old2new_assert(str_contains($plugin, "add_filter('woocommerce_variation_is_purchasable', [\$this, 'filter_old2new_is_purchasable']"), 'Variation purchasability must be filtered too.');
hp_pm_old2new_assert(preg_match('/function old2new_is_old_product.{0,900}get_parent_id\(\)/s', $plugin) === 1, 'Old-product check must fall back to the variation parent SKU.');
// F2: canonical fallback must never double-print.
hp_pm_old2new_assert(str_contains($plugin, "has_action('wp_head', 'rel_canonical')"), 'Canonical fallback must yield to core rel_canonical.');
hp_pm_old2new_assert(str_contains($plugin, 'old2new_seo_canonical_emitted'), 'Canonical fallback must yield when an SEO plugin printed a canonical.');
// F3: hard-redirect loop guard.
hp_pm_old2new_assert(preg_match('/function maybe_redirect_old2new_product.{0,3000}target_packet\[\'status\'\] === \'hard_redirect\'.{0,80}return;/s', $plugin) === 1, 'Redirect must bail when the target is itself hard-redirected (loop guard).');
hp_pm_old2new_assert(str_contains($plugin, 'Redirect loop risk'), 'Health must warn on redirect chains.');
// F4: price/add-to-cart blanking limited to real frontend renders.
hp_pm_old2new_assert(str_contains($plugin, 'function old2new_is_frontend_render'), 'Price blanking must be scoped to frontend renders.');
hp_pm_old2new_assert(preg_match('/old2new_is_frontend_render.{0,300}wp_doing_cron.{0,200}WP_CLI.{0,200}REST_REQUEST/s', $plugin) === 1, 'Feeds, CLI, and REST must keep real price markup.');
// F5: serialized-array SKU match must be exact, not substring.
hp_pm_old2new_assert(str_contains($plugin, "'value' => '\"' . \$new_sku . '\"'"), 'New-SKU lookup must match the quote-delimited serialized value.');
hp_pm_old2new_assert(str_contains($plugin, '!in_array($new_sku, $packet_new_skus, true)'), 'New-SKU resolver must verify the SKU against the resolved packet.');
// F6: the SKU index must not silently cap enforcement.
hp_pm_old2new_assert(preg_match('/old2new_old_sku_index\(\): array.{0,600}\'posts_per_page\' => -1/s', $plugin) === 1, 'SKU index must load all packets, not a capped page.');
// F8: delete failures must be surfaced.
hp_pm_old2new_assert(str_contains($admin_js, 'Unable to delete Old2New packet.'), 'Admin must surface failed deletes.');

// 2.1.9 human-friendly guidelines: plain-language, per-status explanations.
hp_pm_old2new_assert(str_contains($plugin, 'The old product keeps selling until its stock runs out'), 'Guidelines must explain Basic Discontinue in plain language.');
hp_pm_old2new_assert(str_contains($plugin, 'The old page is taken down'), 'Guidelines must explain Hard Redirect in plain language.');
hp_pm_old2new_assert(str_contains($plugin, 'Shoppers who follow an Old2New link or redirect'), 'Guidelines must explain the referral-gated banner.');

// 2.1.8 canonical fallback: live QA showed no SEO plugin prints rel=canonical
// on this site's product pages, so Product Manager must emit its own tag for
// canonical-status old products (registered on wp_head, gated to singular
// product + canonical packet + valid target).
hp_pm_old2new_assert(str_contains($plugin, "add_action('wp_head', [\$this, 'output_old2new_canonical_tag']"), 'Canonical fallback must hook wp_head.');
hp_pm_old2new_assert(
    preg_match('/function output_old2new_canonical_tag.{0,1600}status.{0,40}!== \'canonical\'.{0,400}rel="canonical"/s', $plugin) === 1,
    'Canonical fallback must bail for non-canonical packets and print the rel=canonical tag.'
);

// 2.1.7 admin editor UX: full-width popup, product thumbnails, dual view links.
hp_pm_old2new_assert(str_contains($plugin, 'hp-old2new-modal'), 'Packet editor must render inside the full-width modal.');
hp_pm_old2new_assert(str_contains($plugin, 'hp-old2new-selected-old'), 'Packet form must show the selected old product chip.');
hp_pm_old2new_assert(str_contains($admin_css, '.hp-old2new-modal'), 'Admin CSS must style the packet editor modal overlay.');
hp_pm_old2new_assert(str_contains($admin_css, '.hp-old2new-chip__thumb'), 'Product chips must show thumbnails.');
hp_pm_old2new_assert(str_contains($admin_js, 'function productChip'), 'Admin JS must render product chips with thumbnails.');
hp_pm_old2new_assert(str_contains($admin_js, 'function viewLinks'), 'Admin rows must render View old / View new links.');
hp_pm_old2new_assert(str_contains($admin_js, 'View old') && str_contains($admin_js, 'View new'), 'Row actions must offer both View old and View new.');
hp_pm_old2new_assert(
    preg_match('/function viewLinks.{0,900}o2n=/s', $admin_js) === 1,
    'View new must carry the o2n referral so the admin sees the replacement message.'
);
hp_pm_old2new_assert(str_contains($admin_js, "event.key === 'Escape'"), 'Modal must close on Escape.');

// 2.1.6 admin-configurable lifecycle: per-packet banner window and explicit
// target selection (validated against the packet's new products, bounded,
// fail-closed to defaults).
hp_pm_old2new_assert(str_contains($plugin, '_hp_old2new_banner_window_days'), 'Banner window days must persist per packet.');
hp_pm_old2new_assert(str_contains($plugin, 'function old2new_sanitize_banner_window'), 'Banner window input must be bounded and fail closed.');
hp_pm_old2new_assert(
    preg_match('/old2new_sanitize_banner_window\(\$days\).{0,200}\$days < 1 \|\| \$days > 3650.{0,120}return self::OLD2NEW_BANNER_WINDOW_DAYS;/s', $plugin) === 1,
    'Banner window must clamp to 1..3650 days and fall back to the 180-day default.'
);
hp_pm_old2new_assert(str_contains($plugin, '_hp_old2new_target_product_id'), 'Admin-selected target must persist per packet.');
hp_pm_old2new_assert(
    preg_match('/\$target_product_id > 0 && !in_array\(\$target_product_id, \$new_product_ids, true\).{0,80}\$target_product_id = 0;/s', $plugin) === 1,
    'Admin-selected target must be one of the packet new products or fall back to auto.'
);
hp_pm_old2new_assert(str_contains($plugin, 'old2new_select_target_product(array $new_products, int $explicit_target_id = 0)'), 'Target selection must honor the explicit admin choice before the total_sales auto-pick.');
hp_pm_old2new_assert(str_contains($plugin, "__('Selected by admin.', 'hp-products-manager')"), 'Target reason must say when the admin picked the target.');
hp_pm_old2new_assert(str_contains($plugin, 'hp-old2new-target-select'), 'Packet form must expose the target selector.');
hp_pm_old2new_assert(str_contains($plugin, 'hp-old2new-banner-window'), 'Packet form must expose the banner window field.');
hp_pm_old2new_assert(str_contains($admin_js, 'renderTargetOptions'), 'Admin JS must rebuild target options from the selected new products.');
hp_pm_old2new_assert(str_contains($admin_js, 'banner_window_days'), 'Admin JS must submit the banner window.');

// 2.1.6 admin table spill regression: grid children must be allowed to wrap.
hp_pm_old2new_assert(
    preg_match('/\.hp-old2new-row > div \{[^}]*min-width: 0;[^}]*overflow-wrap: break-word;/s', $admin_css) === 1,
    'Row cells must wrap long health/status text instead of spilling over the actions.'
);

// 2.1.5 referral gating: only visitors following an Old2New link see the
// new-product replacement banner; organic visitors never do.
hp_pm_old2new_assert(str_contains($plugin, "OLD2NEW_REFERRAL_PARAM = 'o2n'"), 'Old2New must define the o2n referral param.');
hp_pm_old2new_assert(
    preg_match('/if \(\$state === \'new\'\) \{.{0,700}OLD2NEW_REFERRAL_PARAM.{0,700}return \'\';/s', $plugin) === 1,
    'New-state banner must bail out for visitors without a valid o2n referral.'
);
hp_pm_old2new_assert(
    preg_match('/function maybe_redirect_old2new_product.{0,2000}add_query_arg\(self::OLD2NEW_REFERRAL_PARAM/s', $plugin) === 1,
    'Hard 301 redirect must tag its target URL with the o2n referral param.'
);
hp_pm_old2new_assert(
    preg_match('/function render_old2new_product_card.{0,900}add_query_arg\(self::OLD2NEW_REFERRAL_PARAM/s', $plugin) === 1,
    'Clickable banner cards must carry the o2n referral param.'
);
hp_pm_old2new_assert(
    !str_contains($plugin, "add_query_arg(self::OLD2NEW_REFERRAL_PARAM, \$old_product_id, \$canonical"),
    'Canonical URLs must stay clean of the o2n referral param.'
);

// 2.1.5 recommended target chip on multi-replacement banners.
hp_pm_old2new_assert(str_contains($plugin, "esc_html__('Recommended', 'hp-products-manager')"), 'Multi-replacement banners must flag the recommended target card.');
hp_pm_old2new_assert(str_contains($frontend_css, '.old2new-product-card__flag'), 'Frontend CSS must style the Recommended flag.');

// 2.1.5 admin: hard-redirect banner-window countdown, consequence confirm on
// promotion to hard_redirect, and a live View link per packet row.
hp_pm_old2new_assert(str_contains($admin_js, 'bannerWindow'), 'Admin rows must show the hard-redirect banner window countdown.');
hp_pm_old2new_assert(str_contains($admin_js, "originalStatus !== 'hard_redirect'"), 'Admin must confirm consequences before promoting a packet to Hard Redirect.');
hp_pm_old2new_assert(str_contains($admin_js, 'target="_blank" rel="noopener"'), 'Admin rows must link to the live old product page.');

// 2.1.4 commerce policy: discontinued old products never backorder and lose
// price/add-to-cart everywhere once sold out.
hp_pm_old2new_assert(str_contains($plugin, "add_filter('woocommerce_product_get_backorders', [\$this, 'filter_old2new_backorders']"), 'Old2New must force backorders off for old products.');
hp_pm_old2new_assert(str_contains($plugin, "add_filter('woocommerce_product_variation_get_backorders', [\$this, 'filter_old2new_backorders']"), 'Old2New backorder policy must also cover variations.');
hp_pm_old2new_assert(str_contains($plugin, "add_filter('woocommerce_is_purchasable', [\$this, 'filter_old2new_is_purchasable']"), 'Sold-out old products must stop being purchasable.');
hp_pm_old2new_assert(str_contains($plugin, "add_filter('woocommerce_get_price_html', [\$this, 'filter_old2new_price_html']"), 'Sold-out old products must hide their price.');
hp_pm_old2new_assert(str_contains($plugin, "add_filter('woocommerce_loop_add_to_cart_link', [\$this, 'filter_old2new_loop_add_to_cart_link']"), 'Sold-out old products must hide the loop add-to-cart button.');
hp_pm_old2new_assert(
    preg_match('/function filter_old2new_backorders[^}]*return \'no\';/s', $plugin) === 1,
    'Backorder filter must return "no" for Old2New old products.'
);
hp_pm_old2new_assert(str_contains($plugin, 'old2new_old_sku_index'), 'Commerce filters must use the per-request old-SKU index, not per-product queries.');

// 2.1.4 stock-aware copy: while old stock remains sellable the banner must not
// claim the product is "no longer available".
hp_pm_old2new_assert(str_contains($plugin, 'This product is being discontinued'), 'Old-product banner must have a stock-aware "being discontinued" variant.');
hp_pm_old2new_assert(str_contains($plugin, 'old2new_summary_in_stock($old_products[0])'), 'Banner copy must branch on the old product stock state.');

// 2.1.4 admin health: stock-aware lifecycle guidance.
hp_pm_old2new_assert(str_contains($plugin, 'Old product still has sellable stock'), 'Health warnings must flag stranded stock on canonical/hard_redirect.');
hp_pm_old2new_assert(str_contains($plugin, 'Old product is sold out. Consider promoting'), 'Health warnings must suggest promotion once the old product sells out.');

// 2.1.4 bug regressions: entity double-escape + swallowed REST errors.
hp_pm_old2new_assert(str_contains($plugin, 'wp_specialchars_decode(wp_strip_all_tags((string) $product->get_name()), ENT_QUOTES)'), 'Product summary names must decode stored entities to avoid &amp; double-escaping in the admin.');
hp_pm_old2new_assert(str_contains($admin_js, 'error && error.message'), 'Admin JS must surface REST error messages instead of a generic save failure.');
hp_pm_old2new_assert(str_contains($admin_js, 'oldProductInStock'), 'Admin preview must branch default copy on old product stock.');

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
hp_pm_old2new_assert(!file_exists($root . '/assets/js/old2new-frontend.js'), 'Product Manager must not ship a frontend Fibo/autocomplete decorator.');
hp_pm_old2new_assert(!str_contains($plugin, 'old2new-frontend.js'), 'Product Manager must not enqueue an Old2New Fibo/autocomplete decorator.');
hp_pm_old2new_assert(!str_contains($plugin, 'HPOld2NewFrontendData'), 'Product Manager must not localize Fibo badge frontend config.');
hp_pm_old2new_assert(!str_contains($plugin, 'dgwt-wcas') && !str_contains($plugin, 'fibo-search-suggestion'), 'Product Manager must not query or mutate FiboSearch DOM.');

hp_pm_old2new_assert(str_contains($contract, '"hp_old2new_packet"'), 'hp-contract must expose hp_old2new_packet CPT.');
hp_pm_old2new_assert(str_contains($contract, '"old2new_product_block"'), 'hp-contract must expose old2new_product_block shortcode.');
hp_pm_old2new_assert(str_contains($contract, 'hp-products-manager/v1/old2new-packets'), 'hp-contract must expose Old2New packet REST routes.');
hp_pm_old2new_assert(str_contains($contract, 'hp-products-manager/v1/old2new-badges'), 'hp-contract must expose Old2New badge REST route.');
hp_pm_old2new_assert(str_contains($readme, '2.1.9'), 'README release notes must include 2.1.9.');
hp_pm_old2new_assert(str_contains($parking_lot, 'old2new-product-lifecycle-roadmap.md'), 'Product Manager parking lot must point to the Old2New lifecycle roadmap.');
hp_pm_old2new_assert(str_contains($roadmap, 'Product Manager owns'), 'Old2New lifecycle roadmap must name Product Manager ownership.');
hp_pm_old2new_assert(str_contains($roadmap, 'HP-UI'), 'Old2New lifecycle roadmap must document that HP-UI is no longer the owner.');
hp_pm_old2new_assert(str_contains($roadmap, 'basic_discontinue') && str_contains($roadmap, 'canonical') && str_contains($roadmap, 'hard_redirect'), 'Old2New lifecycle roadmap must capture all lifecycle statuses.');
hp_pm_old2new_assert(str_contains($roadmap, 'canonical') && str_contains($roadmap, '301'), 'Old2New lifecycle roadmap must capture future canonical and 301 behavior.');
hp_pm_old2new_assert(str_contains($roadmap, 'total_sales'), 'Old2New lifecycle roadmap must capture total_sales target selection.');
hp_pm_old2new_assert(str_contains($roadmap, '180 days'), 'Old2New lifecycle roadmap must capture the 180-day new-product banner window.');
hp_pm_old2new_assert(str_contains($roadmap, 'HP-Zen owns FiboSearch') && str_contains($roadmap, 'category list'), 'Old2New lifecycle roadmap must capture HP-Zen FiboSearch ownership and category-list visibility slices.');

echo "Old2New Product Manager contract passed\n";
