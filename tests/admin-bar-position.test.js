const assert = require('assert');
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.resolve(__dirname, '../assets/js/admin.js'), 'utf8');

assert(
  source.includes('inventory'),
  'Products toolbar placement should recognize the new Inventory admin-bar button label.'
);

assert(
  source.includes('create new order'),
  'Products toolbar placement should keep Create New Order as a backward-compatible rollout anchor.'
);

console.log('admin bar positioning checks passed');
