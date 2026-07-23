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
  let tableOptions = null;

  const elements = new Map();
  [
    'hp-products-table',
    'hp-products-filters',
    'hp-pm-filters-reset',
    'hp-pm-filter-search',
    'hp-pm-filter-brand',
    'hp-pm-filter-status',
    'hp-pm-filter-visibility',
    'hp-pm-filter-locations',
    'hp-pm-filter-locations-summary',
    'hp-pm-filter-location-options',
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
    fetch(url, options) {
      assert.strictEqual(options.cache, 'no-store', 'inventory product requests must bypass stale HTTP caches');
      return Promise.resolve({
        ok: true,
        json() {
          return Promise.resolve({
            products: [
              {
                id: 1,
                name: 'Mega Mineral',
                sku: 'MEGA-1',
                brand: 'HP',
                status: 'Enabled',
                visibility_code: 'visible',
                stock: 7,
                stock_reserved: 1,
                stock_available: 4,
                stock_locations: [
                  { location_id: 10, location_name: 'Wilton', qoh: 5, reserved: 1, non_sellable: 0, available: 4 },
                  { location_id: 11, location_name: 'Wilton Quarantine', qoh: 2, reserved: 0, non_sellable: 0, available: 0 },
                ],
              },
              {
                id: 2,
                name: 'Daily Support',
                sku: 'DAILY-1',
                brand: 'HP',
                status: 'Enabled',
                visibility_code: 'visible',
                stock: 2,
                stock_reserved: 0,
                stock_available: 2,
                stock_locations: [
                  { location_id: 12, location_name: 'Satellite', qoh: 2, reserved: 0, non_sellable: 0, available: 2 },
                ],
              },
            ],
            locations: [
              { id: 10, name: 'Wilton', role: 'primary_fulfillment', is_sellable: true },
              { id: 11, name: 'Wilton Quarantine', role: 'quarantine', is_sellable: false },
              { id: 12, name: 'Satellite', role: 'warehouse', is_sellable: true },
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
    Tabulator: function Tabulator(element, options) {
      tableOptions = options;
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

  const locationOptions = elements.get('hp-pm-filter-location-options');
  assert.strictEqual(locationOptions.children.length, 3, 'all active locations should populate the multiselect');
  const quarantineLabel = locationOptions.children[1];
  const quarantineInput = quarantineLabel.children[0];
  assert.strictEqual(quarantineLabel.children[2].textContent, 'Quarantine', 'quarantine sublocations should be identified');

  const qohColumn = tableOptions.columns.find((column) => column.field === 'stock');
  const initialQohTooltip = qohColumn.formatter({
    getValue() { return lastTableData[0].stock; },
    getRow() { return { getData() { return lastTableData[0]; } }; },
  });
  assert(initialQohTooltip.includes('Wilton: 5'), 'unfiltered tooltip should include the primary location');
  assert(initialQohTooltip.includes('Wilton Quarantine: 2'), 'unfiltered tooltip should include quarantine');
  assert(initialQohTooltip.includes('Satellite: 0'), 'unfiltered tooltip should include active locations with zero QOH');

  quarantineInput.checked = true;
  quarantineInput.listeners.change();
  assert.deepStrictEqual(lastTableData.map((row) => row.id), [1], 'location selection should hide products without stock activity there');
  assert.strictEqual(lastTableData[0].stock, 2, 'QOH should be scoped to the selected location');
  assert.strictEqual(lastTableData[0].stock_reserved, 0, 'Reserved should be scoped to the selected location');
  assert.strictEqual(lastTableData[0].stock_available, 0, 'Available should be scoped to the selected location');
  assert.strictEqual(elements.get('hp-pm-filter-locations-summary').textContent, 'Wilton Quarantine', 'selected locations should appear in the filter summary');

  const qohTooltip = qohColumn.formatter({
    getValue() { return lastTableData[0].stock; },
    getRow() { return { getData() { return lastTableData[0]; } }; },
  });
  assert(qohTooltip.includes('QOH by location'), 'QOH should expose a location breakdown tooltip');
  assert(qohTooltip.includes('Wilton Quarantine: 2'), 'tooltip should include the selected quarantine sublocation');
  assert(!qohTooltip.includes('Wilton: 5'), 'tooltip should exclude unselected locations');

  const reservedColumn = tableOptions.columns.find((column) => column.field === 'stock_reserved');
  const availableColumn = tableOptions.columns.find((column) => column.field === 'stock_available');
  const formatterCell = (field) => ({
    getValue() { return lastTableData[0][field]; },
    getRow() { return { getData() { return lastTableData[0]; } }; },
  });
  assert(reservedColumn.formatter(formatterCell('stock_reserved')).includes('Reserved by location'), 'Reserved should expose a location breakdown tooltip');
  assert(availableColumn.formatter(formatterCell('stock_available')).includes('Available by location'), 'Available should expose a location breakdown tooltip');

  quarantineInput.checked = false;
  quarantineInput.listeners.change();
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
