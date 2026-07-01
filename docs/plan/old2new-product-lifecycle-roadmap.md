# Old2New Product Lifecycle Roadmap

## Ownership

Product Manager owns Old2New packets, the `[old2new_product_block]` shortcode,
the admin editing surface, and the lifecycle policy for basic discontinue,
canonical, and hard redirect states.

WooCommerce remains the source of truth for product records, SKU, stock,
permalinks, thumbnails, and `total_sales`. Product Manager reads that truth and
does not become a separate product catalog.

HP-UI is no longer the Old2New owner. It should not register the shortcode,
resolve packets, inject Fibo/search behavior, or carry fallback Old2New CSS.
HPSP/ACF legacy `old2new_product_pairs` rows are migration fallback only.

HP-Zen owns FiboSearch rendering, selection behavior, detail panels, and any
Old2New badge placement inside autocomplete/search results. Product Manager
remains the data/API owner for the public badge lookup route.

## Banner V1

The current single-product banner remains a top-of-page product message. It is
not a popup.

- Desktop uses copy on the left and the product flow on the right.
- The product flow keeps the old product card on the left, replacement product
  cards stacked on the right, and a minimal arrow between them.
- Product thumbnails are 60x60.
- Product cards are capped around 260px, with title text clamped to 2 lines.
- Old-product state links each new product card and uses an arrow-only CTA.
- New-product state is informational and does not link the cards.
- The phrase `new product` or `new products` is bold and gold through the
  HP-Zen token fallback family.
- Mobile stays compact and stacked.

Old-product copy:

`This product is no longer available. Follow Dr. Cousens' recommendation for this new product.`

Plural old-product copy:

`This product is no longer available. Follow Dr. Cousens' recommendations for these new products.`

New-product copy remains informational:

`This product is now replacing the previous product.`

## Lifecycle Statuses

### basic_discontinue

`basic_discontinue` is the entry runtime behavior. The old product and the new
product pages remain accessible. Product Manager displays the product-page
banner and old-product compact badges.

Redirect type: `none`.

### canonical

`canonical` keeps the old product page accessible, but the canonical URL points
to the selected new product. The product-page banner and old-product compact
badges remain visible.

Redirect type: `canonical`.

### hard_redirect

`hard_redirect` returns a 301 redirect from the old product to the selected new
product. The selected new product keeps the replacement banner visible for 180
days after `hard_redirect_started_at`. After 180 days, Product Manager hides the
banner while leaving the 301 redirect active.

Redirect type: `301`.

## Target Selection

When a packet has multiple new products, Product Manager should select the
canonical or hard redirect target by highest WooCommerce `total_sales`.
Tie-breaks should use the packet order stored in the Old2New packet.

This target selection rule is used for canonical and 301 runtime behavior.

## Packet Fields

Current packet records store the fields needed by the admin and shortcode:

- old product ID and old SKU
- one or more new product IDs and new SKUs
- lifecycle status: `basic_discontinue`, `canonical`, or `hard_redirect`
- `hard_redirect_started_at`
- custom old-product banner message
- custom new-product banner message
- compact badge text

Future SEO and redirect slices should use the existing packet fields before
adding new data.

## Future Visibility Slices

- HP-Zen owns FiboSearch visibility and rendering while consuming Product
  Manager APIs; Product Manager must not directly decorate autocomplete DOM.
- Product category list-card and grid-card layouts show the compact badge for
  old products only.
- Status-aware search and category list behavior applies to
  `basic_discontinue`, `canonical`, and `hard_redirect`.

## Guardrails

- Do not move WooCommerce product truth into Product Manager packet records.
- Do not reintroduce HP-UI shortcode ownership.
- Keep legacy ACF row reads only as rollback and migration safety.
