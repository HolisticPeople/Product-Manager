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
  'old_product',
  'new_products',
  'redirect_type',
  'Stock:',
  'Edit',
  'Delete',
  'confirm(',
  'X-WP-Nonce',
].forEach((needle) => {
  assert(adminJs.includes(needle), `Old2New admin JS must include ${needle}`);
});

assert(adminJs.includes('escapeHtml'), 'Old2New admin JS must escape rendered packet values');
assert(adminJs.includes('safeImageUrl'), 'Old2New admin JS must sanitize product thumbnail URLs');
assert(!adminJs.includes('innerHTML = packet.old_product.name'), 'Old2New admin JS must not assign raw product names to HTML');

[
  '.hp-old2new-admin',
  '.hp-old2new-product-card',
  '.hp-old2new-product-card__thumb',
  '.hp-old2new-product-card__stock',
  '.hp-old2new-actions',
].forEach((needle) => {
  assert(adminCss.includes(needle), `Old2New admin CSS must include ${needle}`);
});

console.log('Old2New admin UI checks passed');
