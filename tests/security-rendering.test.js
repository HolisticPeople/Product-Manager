const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const repoRoot = path.resolve(__dirname, '..');

function makeElement() {
  return {
    appendChild() {},
    addEventListener() {},
    classList: { add() {}, remove() {}, contains() { return false; } },
    dataset: {},
    options: [],
    setAttribute() {},
    style: {},
    textContent: '',
    value: '',
  };
}

function loadProductListColumns() {
  const source = fs.readFileSync(path.join(repoRoot, 'assets/js/products-page.js'), 'utf8');
  let domReady;
  let tabulatorOptions;

  const context = {
    console,
    document: {
      addEventListener(event, callback) {
        if (event === 'DOMContentLoaded') {
          domReady = callback;
        }
      },
      createElement: makeElement,
      getElementById(id) {
        return id === 'hp-products-table' ? makeElement() : null;
      },
    },
    fetch() {
      return Promise.resolve({
        ok: true,
        json() {
          return Promise.resolve({ products: [], metrics: {} });
        },
      });
    },
    Intl,
    navigator: { language: 'en-US' },
    Number,
    sessionStorage: {
      getItem() { return null; },
      setItem() {},
    },
    Tabulator: function Tabulator(_element, options) {
      tabulatorOptions = options;
      return {
        clearData() {},
        replaceData() {},
        setData() {},
      };
    },
    URL,
    window: {
      HPProductsManagerData: {
        brands: [],
        currency: 'USD',
        i18n: {},
        locale: 'en-US',
        metrics: {},
        productUrlBase: 'https://admin.example/product_id=',
        restUrl: 'https://admin.example/wp-json/hp-products-manager/v1/products',
      },
      location: { origin: 'https://admin.example' },
    },
  };

  vm.runInNewContext(source, context, { filename: 'assets/js/products-page.js' });
  assert.strictEqual(typeof domReady, 'function', 'products-page DOMContentLoaded handler should be registered');
  domReady();
  assert(tabulatorOptions && Array.isArray(tabulatorOptions.columns), 'Tabulator columns should be configured');
  return tabulatorOptions.columns;
}

function makeCell(value, rowData) {
  return {
    getValue() {
      return value;
    },
    getRow() {
      return {
        getData() {
          return rowData || { id: 123 };
        },
      };
    },
  };
}

const payload = '<img src=x onerror=alert(1)>';
const attributePayload = 'x" onerror="alert(1)';
const columns = loadProductListColumns();

const imageColumn = columns.find((column) => column.field === 'image');
const nameColumn = columns.find((column) => column.field === 'name');
const skuColumn = columns.find((column) => column.field === 'sku');
const brandColumn = columns.find((column) => column.field === 'brand');

assert(imageColumn, 'image column should exist');
assert(nameColumn, 'name column should exist');
assert(skuColumn, 'sku column should exist');
assert(brandColumn, 'brand column should exist');

const imageHtml = imageColumn.formatter(makeCell(attributePayload));
assert(!String(imageHtml).includes('onerror'), 'image formatter must not emit event-handler attributes from image URLs');

const nameHtml = nameColumn.formatter(makeCell(payload, { id: 123 }));
assert(!String(nameHtml).includes(payload), 'name formatter must not emit raw product names as HTML');
assert(String(nameHtml).includes('&lt;img'), 'name formatter should preserve product names as escaped text');

const skuHtml = skuColumn.formatter(makeCell(payload));
assert(!String(skuHtml).includes(payload), 'SKU formatter must not emit raw SKU values as HTML');

const brandHtml = brandColumn.formatter(makeCell(payload));
assert(!String(brandHtml).includes(payload), 'brand formatter must not emit raw term labels as HTML');

const detailSource = fs.readFileSync(path.join(repoRoot, 'assets/js/product-detail.js'), 'utf8');
assert(
  !detailSource.includes("span.innerHTML = '<span>' + label"),
  'term token labels must be rendered with text nodes, not concatenated HTML'
);
assert(
  !detailSource.includes('d.innerHTML = \'<img src="\' + url'),
  'gallery URLs must be assigned through DOM attributes, not concatenated HTML'
);
assert(
  !detailSource.includes("tr.innerHTML = '<td>' + (m.created_at || '')"),
  'ERP movement rows must render fields with textContent, not concatenated HTML'
);

console.log('security rendering checks passed');
