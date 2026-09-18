const assert = require('assert');
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const adminJsPath = path.join(repoRoot, 'assets/js/old2new-admin.js');
const adminCssPath = path.join(repoRoot, 'assets/css/old2new-admin.css');

assert(fs.existsSync(adminJsPath), 'Old2New admin JavaScript file must exist');
assert(fs.existsSync(adminCssPath), 'Old2New admin CSS file must exist');

const adminJs = fs.readFileSync(adminJsPath, 'utf8');
const adminCss = fs.readFileSync(adminCssPath, 'utf8');

[
  'hp-old2new-table',
  'hp-old2new-form',
  'hp-old2new-old-product',
  'hp-old2new-new-products',
  'hp-old2new-status',
  'hp-old2new-save',
  'hp-old2new-cancel',
  'custom_old_message',
  'custom_new_message',
  'badge_text',
  'health_warnings',
  'target_product',
  'Basic Discontinue',
  'Canonical',
  'Hard Redirect',
  'old_product',
  'old_product_ids',
  'new_products',
  'data-remove-old',
  'data-remove-new',
  'redirect_type',
  'Stock:',
  'Edit',
  'Delete',
  'confirm(',
  'X-WP-Nonce',
].forEach((needle) => {
  assert(adminJs.includes(needle), `Old2New admin JS must include ${needle}`);
});

// Multi-old selection: the form collects several old products as chips and
// must never fall back to a single-value field that silently drops the rest.
assert(!/\boldProduct\b\s*=/.test(adminJs), 'Old2New admin JS must keep old products in a list, not a single slot');
assert(adminJs.includes('oldProducts.length > 1'), 'Old2New admin JS must confirm a multi-packet save before sending it');

assert(adminJs.includes('escapeHtml'), 'Old2New admin JS must escape rendered packet values');
assert(adminJs.includes('safeImageUrl'), 'Old2New admin JS must sanitize product thumbnail URLs');
assert(!adminJs.includes('innerHTML = packet.old_product.name'), 'Old2New admin JS must not assign raw product names to HTML');

[
  '.hp-old2new-admin',
  '.hp-old2new-guidelines',
  '.hp-old2new-product-card',
  '.hp-old2new-product-card__thumb',
  '.hp-old2new-product-card__stock',
  '.hp-old2new-health',
  '.hp-old2new-preview',
  '.hp-old2new-hint',
  '.hp-old2new-actions',
  '.hp-old2new-form select option',
  '--hp-admin-input-bg',
  '--hp-admin-text',
].forEach((needle) => {
  assert(adminCss.includes(needle), `Old2New admin CSS must include ${needle}`);
});

console.log('Old2New admin UI checks passed');
