# Product editorial-gallery source contract

Product Manager exposes the exact ACF/Woo source identity for a Book editorial
gallery through HP-Core's fail-soft provider registry.

## Descriptor

- Provider: `hp_product_manager_editorial_gallery_source`
- Capability: `product_editorial_gallery_source`
- Schema: `hp_product_editorial_gallery_source_v1`
- Contract version: `1.0.0`
- Consumer: `hp-catalog`

The payload contains only:

- public Book product identity;
- exact field key `field_679f5a3cce9a1`, name
  `product_magazine_gallery`, and group `group_6062fd00d4314`;
- normalized, ordered positive attachment IDs;
- a deterministic SHA-256 fingerprint of that canonical source envelope.

Before reading the product value, the provider verifies that ACF still resolves
the exact field key, field name, `gallery` type, and governed group key. A
missing or drifted registry fails soft rather than publishing stale provenance.

It does not expose attachment URLs, captions, review decisions, arbitrary meta,
admin URLs, private paths, or a write API. It does not validate attachment
existence or public eligibility. HP-Catalog owns attachment validation, review,
accessibility policy, and the public `hp_product_editorial_gallery_v1`
projection.

## Resolution

```php
$payload = HP_Core\get_contract_provider_payload(
    'hp_product_manager_editorial_gallery_source',
    'product_editorial_gallery_source',
    [
        'consumer' => 'hp-catalog',
        'product_id' => 109731,
    ]
);
```

Missing, malformed, duplicate, oversized, unpublished, non-product, and
non-Book sources return typed `WP_Error` results. A missing/empty field returns
a valid packet with an empty `attachment_ids` list. Consumers must fail soft.
