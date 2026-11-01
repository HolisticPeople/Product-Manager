# Product composition and finite reviewed import v1

Product-Manager owns these brand-neutral product source fields. This release
registers local ACF schema; it does not migrate product content or activate any
Catalog policy. Physical `supplement_form` and macro `product_type_hp` are unchanged.

## Typed fields

- `application_methods`: a list of distinct `aromatic`, `topical_external`,
  `internal`, `home_environment` values, maximum four. Evidence must describe the
  exact product's method, not a document's complete badge legend.
- `oil_preparation`: `essential_oil`, `essential_oil_blend`,
  `diluted_essential_oil`, `carrier_oil`, `nutritional_oil`, or an explicit empty
  optional selection.
- `natural_constituents`: at most fifty structured rows, each containing exactly
  `sourceName`, `canonicalIngredient`, `chemicalClass`, `amount`, `species`,
  `isomer`. Missing amount/species/isomer/class is explicitly `UNKNOWN`;
  a missing canonical identity is null. Other values are bounded source text.
  A canonical reference contains `termId`, `slug`, `name`, `reviewRef` and means a
  separately reviewed resolved identity, never membership or scientific approval.

The ACF keys are `field_hp_pm_application_methods_v1`,
`field_hp_pm_oil_preparation_v1`, `field_hp_pm_natural_constituents_v1`. The last
uses the `hp_natural_constituents` custom ACF type: a native structured array in
one value row and one ACF pointer, rather than repeater child rows. Its native
editor accepts a JSON list and rejects malformed values. Product-Manager's own
Ingredients pane displays readback and links to the native editor; its old broad
REST writer is not extended to bypass the finite reviewed import contract.

## Public source contract

`hp_pm_product_composition_schema_v1()` returns `{version:"1.0.0",fields:{...}}`,
with field key/type/choices. `hp_pm_validate_product_composition_v1(name,value)`
validates exact types and returns the same value or throws; it does not approve
source evidence. `hp_pm_product_composition_v1(productId)` returns
`{version,status,productId,fields}`. Fields are omitted when absent, malformed or
bound to a different ACF pointer. Draft/private/password products and an active
Catalog source-update boundary return `status=unavailable` and no fields.

The public summary strips `reviewRef`; a canonical reference is suppressed
unless the current term's name/slug still match and Catalog's existing
`hp_catalog_ingredient_summaries_v1` contract admits the exact reviewed public
ingredient leaf and ancestors. An unavailable/older Catalog source boundary fails closed for the entire
composition summary, so an interrupted source update cannot leak while Catalog
is disabled. If only ingredient-summary admission is unavailable, canonical
references are suppressed while source-name text remains available. No private
journal, evidence body, reviewer name or review reference enters this summary.
The summary renders from a captured set of native value/pointer rows and public
product state; it rechecks that complete snapshot and the source boundary after
canonical admission. A concurrent begin or even a full begin/apply/complete cycle
that changes the snapshot returns unavailable. Source identity alone does not
establish a naturally-contains relationship:
Catalog's separate reviewed native membership/provenance contract owns that.

## Private finite operator

Only an authenticated WP-CLI operator with `manage_woocommerce`, `edit_products`
and exact-product `edit_post` permission can use:

```text
wp hp-products-manager reviewed-import --action=preview --file=/private/packet.json
wp hp-products-manager reviewed-import --action=apply --file=/private/packet.json
wp hp-products-manager reviewed-import --action=rollback --file=/private/packet.json
```

This command has no REST route, network fetch, provider upload, policy activation,
term creation, assignment or product publication. Registering the command is not
native qualification or production execution. The Publisher must select/release
exact Product-Manager and Catalog identities before a production packet relies
on them, and the selected site/operator must pass bounded native qualification.

A packet contains exactly:

```json
{
  "schemaVersion": "hp_pm_reviewed_product_import_v1",
  "operationId": "reviewed-operation-001",
  "siteUrl": "https://exact-site.example/",
  "environment": "staging",
  "productId": 101,
  "identity": {
    "sku": "SYN-15", "manufacturer": "Synthetic", "manufacturerSku": "SYN15",
    "sizeEu": "15", "sizeEuUnits": "mL", "sizeUsa": "", "sizeUsaUnits": "",
    "status": "draft", "slug": "synthetic-oil"
  },
  "review": {
    "decision": "approved", "reference": "private/review-record",
    "reviewer": "independent reviewer", "identityApproved": true,
    "sourceMarket": "US", "holds": [],
    "finalCopySha256": "CALCULATED_FROM_ALL_FINAL_PROPOSALS",
    "sourceFidelityReviewer": "independent source reviewer",
    "sourceFidelityReference": "private/source-element-accounting"
  },
  "fields": [{
    "field": "application_methods",
    "before": {"exists": false, "value": null},
    "proposed": ["aromatic", "topical_external"],
    "referenceBefore": {"exists": false, "value": null},
    "evidence": ["private/source-register#explicit-product-badges"]
  }]
}
```

The copy hash is `HP_PM_Reviewed_Product_Import::final_copy_hash(fields)`: SHA256
of JSON encoding an object of `field:proposed` values sorted by field name using
unescaped slashes/Unicode. It binds the final rewritten copy and typed proposals.
The independent source-fidelity review must account for all useful source units,
qualifiers, conflicts and explicit exclusions against that exact final copy.
Manufacturer prose need not be copied verbatim. Changing a proposal invalidates
the hash; a new review/hash is required. The operator checks the binding and
review metadata, not the truth of external evidence. A hash is not a signature.

`sourceMarket` is reviewed source evidence; no nonexistent native market field is
invented. Exact sellable identity and evidence still require independent review.
Unknown/held identities, unresolved species/preparation/formula conflicts and
source/rights restrictions remain operation-specific holds. A nonempty packet
holds list refuses execution. Existing native ingredient identity holds are
preserved, never silently cleared. Content completion does not override a
separate worker, source, scientific, protocol/course or rights gate.

Fields are limited to the three typed fields, `post_title`, `post_excerpt`,
`post_content`, `slogan`, `aka_product_name`, `description_long`, `ingredients`,
`ingredients_other`, `how_to_use`, `recommended_use`, `cautions`, `sku_mfr` and the
four existing bottle-size/unit fields. Each proposal requires exact native
before-state and evidence. ACF values additionally require exact pointer
before-state, current field name/key/type and owned choices. Unsupported HTML is
refused rather than silently changing an approved proposal. Uses must go in an
actually displayed field verified by the page owner; accepting `post_excerpt`
does not claim that the product template displays it.

## Journals, concurrency, repeat and recovery

A private non-autoloaded option `hp_pm_reviewed_import_<productId>_<operationHash>`
records durable operation/row intents before writes and strict native readback
after every value/pointer. Missing, present-null, empty string and empty list are
distinct. Duplicate native meta rows refuse. Native post updates use
`wp_update_post`; ACF updates use `update_field` with the exact current field key.
No raw SQL writes product content.

The required Catalog `Product_Source_Update@1` boundary owns the existing
ingredient/category/semantic per-product locks. It stays durable across a failed
or partial import and blocks ordinary processing/public source summaries until
explicit completion or a verified scoped inverse. A missing boundary refuses
writes; there is no fallback. Every native mutation and journal checkpoint
checks lease ownership. No worker is globally paused or enabled.

All unselected product meta, status/slug/password/parent, unselected post text
and every native product taxonomy assignment are guarded during the operation
and before scoped inverse. The only derived-meta exclusions are Catalog's
`_hp_catalog_ingredient_index_state`, `_hp_catalog_ingredient_index_receipt` and
`_hp_catalog_categorization_state_v1`: completion legitimately dirties these
existing services. Commerce, stock, images, source holds and editor intent remain
protected. Drafts stay drafts.

A completed identical operation checks its selected fields/ACF pointers and
identity and makes zero native, journal or lock writes. Later protected drift,
including legitimate separately owned worker assignments, returns
`fields_noop_protected_state_requires_review`, with no writes; it is not a claim
that those assignments are unchanged. No taxonomy drift is silently exempted.
An inverse still requires the original complete protected guard. The source
operation receipt and later category/ingredient worker receipts remain separate.

Preview remains strictly read-only even for an interrupted completion; its
completed readback reports whether a boundary remains. Explicit apply may finish
that same completed operation's retained boundary after all final values and
protected state verify; it rewrites no fields. An interrupted partial operation
must use scoped rollback rather than resuming arbitrary later fields. Rollback
accepts only its journal's intended before/after states, including a crash
between an ACF value and pointer write; foreign value/pointer/protected drift
refuses before inverse writes. Ownership is checked again after inverse intent
and before every row/meta restoration; native metadata APIs receive the observed
previous value wherever their conditional-value semantics support it. The
existing cooperative boundary owns product processing; this is not an atomic
transaction across arbitrary uncooperative WordPress save hooks. It restores original absence as absence, then
verifies every restored field and completes the boundary. If the restored journal persisted but boundary completion failed, explicit
rollback re-enters only that same operation, re-verifies all restored guards and
completes the boundary with zero field/journal writes. A rolled-back operation
cannot be replayed as a new import; use a new reviewed operation ID.

## Verification levels

`tests/product-composition-import-test.php` exercises synthetic API faults.
`tests/product-composition-catalog-integration-test.php --catalog-root=...`
loads the actual supplied Catalog boundary/lifecycle/category/semantic classes
while native functions remain synthetic. Neither boots WordPress or claims
native ACF behavior, served browser QA, staging content execution, production
execution or human/device acceptance. Record those levels independently.
