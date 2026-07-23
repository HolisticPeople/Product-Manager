# Products Manager

Lightweight WooCommerce admin helper that adds a blue **Products** quick-access button next to the _Inventory_ action in the admin toolbar. The plugin only loads on wp-admin to avoid front-end overhead.

## Development

1. Clone the repository into `wp-content/plugins/products-manager`.
2. Use the `dev` branch for staging-ready work; merge to `main` only when production-ready.
3. Run `composer install` or `npm install` if and when those toolchains are introduced (none required yet).

## Deployment

- Pushes to `dev` trigger the staging deploy workflow.
- Manual `workflow_dispatch` runs can target either staging or production.
- Production deploys are manual-only (run the workflow from GitHub).

### Required GitHub secrets

This repository reuses the HolisticPeople organization secrets:

| Secret | Purpose |
| ------ | ------- |
| `KINSTA_HOST`, `KINSTA_PORT`, `KINSTA_USER`, `KINSTA_SSH_KEY` | Staging SSH connection |
| `KINSTA_PLUGINS_BASE` | Base staging plugins directory (workflow appends `/products-manager`) |
| `KINSTAPROD_HOST`, `KINSTAPROD_PORT`, `KINSTAPROD_USER`, `KINSTAPROD_SSH_KEY` | Production SSH connection |
| `KINSTAPROD_PLUGINS_BASE` | Base production plugins directory (workflow appends `/products-manager`) |

Ensure the associated public SSH keys are installed on both Kinsta environments.

## Runtime Requirements

- PHP 8.5+

## Release Notes

### 2.4.4

- Wait for Tabulator's `tableBuilt` lifecycle event before loading product
  data. This prevents location-filter changes from leaving the product grid on
  its loading placeholder in production browsers.

### 2.4.3

- Preserve HP-Inventory's authoritative per-location Available value when
  aggregating product positions. This keeps quarantine QOH visible while its
  non-sellable availability remains zero.

### 2.4.2

- Force the products/location REST request to bypass browser and intermediary
  caches so QOH, Reserved, Available, and their location tooltips always use
  the current HP-Inventory positions after adjustments or deployments.

### 2.4.1

- Consume HP-Inventory's dedicated, fail-soft
  `hp-inventory/v1/location-positions` contract instead of the heavyweight
  dashboard/settings combination. This keeps the products table available
  when unrelated dashboard metrics fail and preserves the explicit quarantine
  parent/child location model.

### 2.4.0

- Add a persistent multi-select Locations filter to the products table using
  HP-Inventory's active locations, including non-sellable quarantine
  locations. Selecting locations scopes the visible products and recomputes
  QOH, Reserved, and Available from those locations.
- Add per-location hover/focus breakdowns to the QOH, Reserved, and Available
  values. With no location filter, the breakdown covers every active location;
  with a filter, it shows only the selected locations. The integration remains
  fail-soft when HP-Inventory is absent or its REST contracts are unavailable.

### 2.3.6

- Replace the HP-Inventory-owned Quantity input with a plain read-only stock
  value in the product editor. The inventory-levels v1 contract now supplies
  the same per-location position summary shown by HP-Inventory (for example,
  `12 position, 5 in Wilton, 7 in Seattle`) beneath the total. If the contract
  is unavailable, Product-Manager keeps the stock total visible and reports
  that no location breakdown is available; when HP-Inventory is absent, the
  original editable quantity input remains available.

### 2.3.3

- Fix the 2.3.2 brand-prefix advisory: PHP coerces numeric-string array keys to
  integers, so the derived prefix list came back as ints (`[736313]`) and the
  strict `in_array()` / JS `indexOf()` comparison against the string prefix
  always failed — firing the "unusual prefix" advisory even when the prefix
  matched. Prefixes are now normalized to strings (`array_map('strval', …)`).

### 2.3.2

- **UPC/GTIN brand-prefix verification (advisory).** Beyond the checksum
  (which only proves a barcode is well-formed, not that it's the right
  product), the field now checks the GTIN's GS1 **company prefix** against the
  prefixes already used by the **brand's other products** — learned
  automatically from the catalog, no map to maintain. On a mismatch it warns
  (live under the field and after save) that the barcode may have been copied
  from the wrong manufacturer, while still saving the value (advisory, not a
  block — a brand can legitimately use more than one prefix). This is the
  check that catches a valid-but-wrong UPC (e.g. a magnesium barcode from a
  different manufacturer that passes the checksum but starts `884926` instead
  of the brand's `736313`). Real product-identity verification still requires
  the physical label or the manufacturer's official UPC list.

### 2.3.1

- **UPC/GTIN input validation.** The barcode field now validates **length
  (8/12/13/14 digits) and the GS1 mod-10 check digit** before a value is
  accepted — client-side (live green/red hint under the field, and staging is
  blocked with a clear message on an invalid entry) and server-side (the apply
  handler rejects a bad checksum with a warning, never storing it). Non-digits
  are stripped so `0 12345 67890 5` normalizes to `012345678905`. Catches
  mis-typed/wrong-length barcodes before they reach the GMC feed. Note:
  checksum validity confirms the number is a well-formed barcode, not that it
  belongs to this product — for that, verify against the physical label or the
  manufacturer's official UPC list, and cross-check the GS1 company prefix
  against the brand's other products.

### 2.3.0

- **UPC / GTIN field** added to the product editor's **Ingredients & Mfg →
  Manufacturer Details** section. It reads and writes WooCommerce's NATIVE
  GTIN field (`_global_unique_id`) — the single source of truth, the same
  value shown in the core WC Inventory tab. Editing it propagates with zero
  extra config to: the **GMC product feed** (hp-gmc-manager
  `ProductDataFeed::getGtin()` reads `get_global_unique_id()` first), **Product
  JSON-LD** (HP-Core `ProductStructuredDataService` probes `_global_unique_id`
  first → emits `gtin8/12/13/14`), and the **HP-Agent-Gateway** agent product
  payload. Input is normalized to digits on save; WooCommerce enforces
  cross-product uniqueness and a duplicate/invalid-length barcode surfaces as
  a warning instead of corrupting data or aborting the rest of the save. Also
  wired into the comprehensive-update MCP tool.

### 2.2.0

- Stock quantity is now read-only in the product editor when HP-Inventory is
  active: HP-Inventory owns stock truth (ledger, locations, batches,
  allocations), and the Quantity field links to the product's Inventory
  Levels row (`levels_search` deep link) where "Add transaction" records the
  change with a reason and full propagation. The apply endpoint rejects
  stock_quantity writes with an explicit 409 while ownership is active
  (fail visibly, never silently). Fail-soft: with HP-Inventory absent,
  editing works exactly as before. Filter: `hp_pm_stock_editing_disabled`.

### 2.1.9

- Rewrite the Old2New admin guidelines in plain language: one entry per
  status describing what shoppers actually experience, plus how target
  picking, referral-gated banners, and custom wording work.
- Production-review fixes: variations now respect the sold-out
  purchasability block (variation filter + parent-SKU fallback); the
  canonical fallback yields to core/SEO-plugin canonicals so a page never
  carries two tags; hard redirects refuse to start a loop when the target
  is itself hard-redirected (with a health warning for redirect chains);
  price/add-to-cart blanking no longer leaks into feeds, WP-CLI, or REST;
  the new-SKU resolver matches exact SKUs instead of substrings; the SKU
  index loads all packets (no 500-packet enforcement cliff); failed
  deletes are reported in the admin.

### 2.1.8

- Canonical status now works regardless of SEO-plugin configuration: live QA
  showed this site prints no rel=canonical on product pages (Yoast active but
  its canonical output disabled), so Product Manager emits its own canonical
  tag on canonical-status old product pages, pointing at the packet target.
  The existing SEO-plugin filters remain, and both paths resolve the same URL,
  so a site that does print canonicals cannot conflict with ours.

### 2.1.7

- Old2New packet editor now opens as a full-width popup with a 12-column grid
  sized to each field (product pickers half-width, status/target/date/window a
  quarter each, messages side by side, compact badge next to the live
  preview); closes on Cancel, X, Escape, or backdrop click.
- Product chips in the form (selected old product and each replacement) show
  product thumbnails.
- Packet rows replace the single "View" action with "View old" and "View new";
  View new carries the o2n referral so the admin sees the replacement message
  exactly as a referred customer would.

### 2.1.6

- Banner window is now admin-configurable per packet (default 180 days,
  bounded 1-3650, fails closed to the default). The hard-redirect countdown,
  expiry, and health warning all use the packet's own window.
- The canonical/301 target is now admin-selectable per packet ("Canonical /
  redirect target" in the form; default Auto keeps the highest-total_sales
  pick). An invalid selection falls back to Auto; the row's Target cell says
  "Selected by admin" vs the auto reason, and the frontend "Recommended" chip
  follows the effective target.
- Fix admin packet-row text spill: long health/status text now wraps inside
  its column instead of running under the action buttons; warning text uses a
  readable light red on the dark admin surface.

### 2.1.5

- The new-product "now replacing" banner shows only to visitors who followed an
  Old2New link: the hard 301 redirect and old-page banner card clicks carry an
  `?o2n=<old-product-id>` referral param, the banner renders only when it
  matches a known old product (narrowed to that product), and organic visitors
  never see replacement history. Canonical URLs stay clean.
- Multi-replacement banners flag the computed target (highest `total_sales`)
  with a gold "Recommended" chip.
- Old2New admin: hard-redirect rows show the banner-window countdown ("ends in
  N days" / "hidden, 301 stays active"); promoting a packet to Hard Redirect
  asks for confirmation with the exact consequence (old URL goes down, 301 to
  target); each row gains a "View" link to the live old product page.

### 2.1.4

- Old2New commerce policy for discontinued (old) products: backorders are
  forced off so remaining stock sells through but never oversells; once sold
  out, the product loses its price and add-to-cart everywhere (single product
  page, shop/category loops, purchasability).
- Stock-aware banner copy: while sellable stock remains, the old-product banner
  says "being discontinued — limited stock remains" instead of contradicting
  the live add-to-cart with "no longer available"; the sold-out copy is
  unchanged. The admin form preview mirrors the same stock-aware default.
- Stock-aware admin health warnings: flag stranded sellable stock on Canonical
  and Hard Redirect packets, and suggest promotion once the old product sells
  out.
- Fix `&amp;` double-escaping in the Old2New admin product cards (summary names
  now decode stored entities before the JS-side escape).
- Surface real REST error messages in the Old2New admin (e.g. "An Old2New
  packet already exists for this old SKU") instead of a generic save failure.

### 2.1.3

- Fix Old2New admin packet list styling on the dark HP-Zen admin surface: the
  status capsule no longer renders as a white pill with invisible text, and the
  product cards show one clean outline instead of boxing every text line
  (HP-Zen's `[class*="-card"]` treatment was matching our BEM child elements).
- Replace remaining hard-coded light backgrounds (thumb well, target preview,
  selected-product chips, missing-product card) with HP-Zen admin tokens so
  they read correctly on dark themes and fail dark, not white.

### 2.1.2

- Hand FiboSearch Old2New badge rendering to HP-Zen; Product Manager now keeps the badge REST endpoint and product-loop badges only.

### 2.1.1

- Add Product Manager thumbnail fallbacks for SO-1 and SO-2 so special-order
  products show purpose-specific icons when no WooCommerce product image exists.
- Hide Old2New stock labels from frontend product replacement cards while keeping backend admin stock labels visible.
- Fix Old2New admin packet form inputs, selects, and select options for dark HP admin themes.

### 2.1.0

- Add Old2New lifecycle statuses, canonical target selection, hard 301 redirects, and a 180-day hard-redirect banner window.
- Add compact old-product badges for product loops and expose the badge endpoint consumed by HP-Zen-owned FiboSearch rendering.
- Add packet custom text fields, admin guidelines, target previews, and health warnings.

### 2.0.9

- Move Old2New product replacement packets and `[old2new_product_block]` ownership into Product Manager.
- Add the Old2New admin tab with editable packet records, product cards, status, redirect type, and stock labels.

### 2.0.8

- Cover list-backed product search inputs without explicit input types and stabilize wrapped product-detail tabs under HP-Zen styling.

### 2.0.7

- Align Product Manager product-edit tabs, inputs, selects, and filter controls with the HP-Zen admin runtime instead of hard-coded light backgrounds.

### 2.0.6

- Guard legacy ERP schema installs with a stored schema version to avoid repeated `dbDelta()` ALTER work on admin requests.

### 2.0.5

- Prevent the Products Manager filter form from leaving the admin page when search is submitted with Enter.

### 2.0.4

- Keep the Products toolbar shortcut positioned after the renamed Inventory button while preserving rollout compatibility with the old Create New Order label.

### 2.0.3

- Bound Product Manager apply-endpoint enum fields to known WooCommerce status, visibility, backorder, and tax-status values.
- Retire legacy ERP movement persistence and rebuild endpoints once HP Inventory marks Product-Manager demand history as migrated.

### 2.0.2

- Opts Product Manager list and product detail admin screens into the shared HP-Zen admin runtime.

### 2.0.1

- Harden Product Manager admin rendering so catalog and ERP values are escaped or assigned through text/attribute APIs instead of concatenated into HTML.

### 2.0.0

- Major production promotion for the PHP 8.5 baseline and staged Product Manager compatibility work.
