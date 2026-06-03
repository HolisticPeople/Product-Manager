const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const repoRoot = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(repoRoot, 'assets/js/products-page.js'), 'utf8');

function makeElement(id) {
  return {
    id,
    checked: false,
    children: [],
    innerHTML: '',
    listeners: {},
    style: {},
    textContent: '',
    value: '',
    addEventListener(event, callback) {
      this.listeners[event] = callback;
    },
    appendChild(child) {
      this.children.push(child);
    },
    reset() {
      this.value = '';
    },
  };
}

(async function run() {
  let domReady;
  let lastTableData = null;

  const elements = new Map();
  [
    'hp-products-table',
    'hp-products-filters',
    'hp-pm-filters-reset',
    'hp-pm-filter-search',
    'hp-pm-filter-brand',
    'hp-pm-filter-status',
    'hp-pm-filter-visibility',
    'hp-pm-filter-qoh-gt0',
    'hp-pm-filter-reserved-gt0',
    'hp-pm-filter-available-lt0',
    'hp-pm-table-count',
    'hp-pm-metric-total',
    'hp-pm-metric-enabled',
    'hp-pm-metric-stock-cost',
    'hp-pm-metric-reserved',
  ].forEach((id) => {
    elements.set(id, makeElement(id));
  });

  const context = {
    console,
    document: {
      addEventListener(event, callback) {
        if (event === 'DOMContentLoaded') {
          domReady = callback;
        }
      },
      createElement() {
        return makeElement('');
      },
      getElementById(id) {
        return elements.get(id) || null;
      },
    },
    fetch() {
      return Promise.resolve({
        ok: true,
        json() {
          return Promise.resolve({
            products: [
              { id: 1, name: 'Mega Mineral', sku: 'MEGA-1', brand: 'HP', status: 'Enabled', visibility_code: 'visible', stock: 5, stock_reserved: 0, stock_available: 5 },
              { id: 2, name: 'Daily Support', sku: 'DAILY-1', brand: 'HP', status: 'Enabled', visibility_code: 'visible', stock: 2, stock_reserved: 0, stock_available: 2 },
            ],
            metrics: {},
          });
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
    Tabulator: function Tabulator() {
      return {
        clearData() {},
        replaceData(data) {
          lastTableData = data;
        },
        setData(data) {
          lastTableData = data;
        },
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
        productUrlBase: 'https://admin.example/wp-admin/admin.php?page=hp-products-manager-product&product_id=',
        restUrl: 'https://admin.example/wp-json/hp-products-manager/v1/products',
      },
      location: { origin: 'https://admin.example' },
    },
  };

  vm.runInNewContext(source, context, { filename: 'assets/js/products-page.js' });
  assert.strictEqual(typeof domReady, 'function', 'products-page DOMContentLoaded handler should be registered');
  domReady();

  for (let i = 0; i < 5; i += 1) {
    await Promise.resolve();
  }

  const form = elements.get('hp-products-filters');
  const search = elements.get('hp-pm-filter-search');
  assert.strictEqual(typeof form.listeners.submit, 'function', 'filters form should intercept submit from pressing Enter');

  search.value = 'mega';
  let prevented = false;
  form.listeners.submit({
    preventDefault() {
      prevented = true;
    },
  });

  assert.strictEqual(prevented, true, 'filters form submit should prevent native admin.php navigation');
  assert(Array.isArray(lastTableData), 'submitting filters should update the table data');
  assert.deepStrictEqual(lastTableData.map((row) => row.id), [1], 'submit should apply the current search filter');

  console.log('product filter submit checks passed');
}()).catch((error) => {
  console.error(error);
  process.exit(1);
});
