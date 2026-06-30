# Old2New Product Lifecycle Roadmap

## Ownership

Product Manager owns Old2New packets, the `[old2new_product_block]` shortcode,
the admin editing surface, and the lifecycle policy for replacement,
discontinue, and hard redirect states.

WooCommerce remains the source of truth for product records, SKU, stock,
permalinks, thumbnails, and `total_sales`. Product Manager reads that truth and
does not become a separate product catalog.

HP-UI is no longer the Old2New owner. It should not register the shortcode,
resolve packets, inject Fibo/search behavior, or carry fallback Old2New CSS.
HPSP/ACF legacy `old2new_product_pairs` rows are migration fallback only.

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

### replace

`replace` is the current runtime behavior. The old product and the new product
pages remain accessible. Product Manager only displays the replacement banner.
The old product may still have inventory.

Redirect type: `none`.

### discontinue

`discontinue` is a future runtime slice. The old product page remains
accessible, but the canonical URL should point to the selected new product. The
banner remains visible.

Redirect type: `canonical`.

### hard_redirect

`hard_redirect` is a future runtime slice. The old product should return a 301
redirect to the selected new product. The selected new product keeps the
replacement banner visible for 90 days after `hard_redirect_started_at`. After
90 days, Product Manager should hide the banner while leaving the 301 redirect
active.

Redirect type: `301`.

## Target Selection

When a packet has multiple new products, Product Manager should select the
canonical or hard redirect target by highest WooCommerce `total_sales`.
Tie-breaks should use the packet order stored in the Old2New packet.

This target selection rule is roadmap policy until the canonical and 301
runtime slices are implemented.

## Packet Fields

Current packet records store the fields needed by the admin and shortcode:

- old product ID and old SKU
- one or more new product IDs and new SKUs
- lifecycle status: `replace`, `discontinue`, or `hard_redirect`
- `hard_redirect_started_at`

Future SEO and redirect slices should use the existing packet fields before
adding new data.

## Future Visibility Slices

- Fibo/search details panel visibility, owned by Product Manager APIs and
  renderer contracts, not by HP-UI direct coupling.
- Product category list-card layout visibility. Grid cards remain out of scope.
- Status-aware search and category list behavior for `replace`, `discontinue`,
  and `hard_redirect`.

## Guardrails

- Do not implement canonical override or 301 redirect behavior in banner polish
  slices.
- Do not move WooCommerce product truth into Product Manager packet records.
- Do not reintroduce HP-UI shortcode ownership.
- Keep legacy ACF row reads only as rollback and migration safety.
