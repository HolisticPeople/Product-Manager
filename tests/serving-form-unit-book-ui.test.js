'use strict';

const fs = require('fs');
const path = require('path');

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const root = path.resolve(__dirname, '..');
const plugin = fs.readFileSync(path.join(root, 'products-manager.php'), 'utf8');
const runtime = fs.readFileSync(path.join(root, 'assets/js/product-detail.js'), 'utf8');

assert(plugin.includes('data-hp-pm-product-types='), 'Product Manager must render the product-type restriction contract.');
assert(plugin.includes('[HP_PM_Serving_Form_Unit_Registry::SUPPLEMENT_TYPE]'), 'Serving Form Unit must be supplement-only in the editor.');
assert(plugin.includes('guard_changes_for_product_type($apply, $effective_product_type)'), 'The REST apply path must enforce the Book guard.');
assert(runtime.includes("querySelectorAll('[data-hp-pm-product-types]')"), 'The editor must reconcile restricted rows when product type changes.');
assert(runtime.includes('row.hidden = !available'), 'Unavailable Book rows must be absent from the visual editor.');
assert(runtime.includes('control.disabled = !available'), 'Unavailable Book controls must be non-submittable.');
assert(runtime.includes('if (!el || el.disabled) return;'), 'Change collection must ignore disabled restricted controls.');
assert(runtime.includes("productType === 'book_type'"), 'The client guard must recognize the governed Book type.');
assert(runtime.includes('delete staged.serving_form_unit'), 'Changing to Book must purge a stale staged serving-unit change.');
assert(runtime.includes("metaEls.product_type_hp.addEventListener('change'"), 'Product-type changes must immediately resynchronize the editor.');

console.log('Serving-form Book UI contract test passed.');
