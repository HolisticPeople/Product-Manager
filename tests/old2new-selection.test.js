const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.OLD2NEW_TEST_SOURCE || path.join(__dirname, '../assets/js/old2new-admin.js'), 'utf8');
const oldProduct = { id: 43591, name: 'Ultimate Green Tea Extract, 2 fl. oz., Chi Tea, Viva Herbals', sku: 'VH-GT2', stock: 36 };
const replacement = { id: 144787, name: 'EGCg Green Tea Extract, 90 Veg Capsules', sku: 'EGC' };
const label = product => `${product.name} [${product.sku}]`;
const flush = () => new Promise(resolve => setImmediate(resolve));

async function harness(packets = []) {
    const elements = new Map();
    const get = name => {
        const id = name.startsWith('hp-') ? name : `hp-old2new-${name}`;
        if (!elements.has(id)) elements.set(id, {
            value: '', innerHTML: '', textContent: '', hidden: false, listeners: {},
            addEventListener(type, callback) { this.listeners[type] = callback; },
            querySelectorAll() {
                return [...this.innerHTML.matchAll(/<option value="([^"]*)" data-id="([^"]*)">/g)]
                    .map(match => ({ value: match[1], getAttribute: () => match[2] }));
            }
        });
        return elements.get(id);
    };
    let ready;
    const searches = [];
    const saves = [];
    vm.runInNewContext(source, {
        URL, console,
        window: { location: { origin: 'https://example.test' }, HPOld2NewAdminData: {
            packetsUrl: 'https://example.test/packets', searchUrl: 'https://example.test/search'
        } },
        document: {
            getElementById: get,
            querySelectorAll: () => [],
            addEventListener(type, callback) { if (type === 'DOMContentLoaded') ready = callback; }
        },
        fetch(url, options) {
            if (String(url).includes('/search?')) {
                return new Promise(resolve => searches.push({
                    term: new URL(url).searchParams.get('search'),
                    respond(products) { resolve({ ok: true, json: async () => ({ products }) }); }
                }));
            }
            if (options.body) saves.push(JSON.parse(options.body));
            return Promise.resolve({ ok: true, json: async () => ({ packets }) });
        }
    });
    ready();
    await flush();
    const fire = (name, type, event = {}) => get(name).listeners[type]({ preventDefault() {}, ...event });
    const input = (name, value) => { get(name).value = value; fire(name, 'input'); };
    fire('add', 'click');
    return { get, fire, input, searches, saves };
}

test('selected old product commits on input, survives blur and replacement search, and saves correct IDs', async () => {
    const h = await harness();
    h.input('old-product', 'VH-GT2');
    h.searches[0].respond([oldProduct]);
    await flush();
    h.input('old-product', label(oldProduct));
    assert.match(h.get('selected-old').innerHTML, /VH-GT2/, 'selection must not wait for blur');
    assert.equal(h.searches.length, 1, 'selecting a label must not search the full display string');
    h.input('new-products', 'EGC');
    h.searches[1].respond([replacement]);
    await flush();
    h.fire('old-product', 'change');
    assert.match(h.get('selected-old').innerHTML, /VH-GT2/, 'other field results must not erase old assignment');
    h.input('new-products', label(replacement));
    h.fire('new-products', 'change');
    h.input('new-products', label(replacement));
    h.fire('form', 'submit');
    assert.equal(h.saves[0].old_product_id, oldProduct.id);
    assert.deepEqual(h.saves[0].new_product_ids, [replacement.id], 'replacement selection must stay unique');
});

test('out-of-order search responses cannot replace latest suggestions', async () => {
    const h = await harness();
    h.input('old-product', 'tea');
    h.input('old-product', 'VH-GT2');
    h.searches[1].respond([oldProduct]);
    await flush();
    h.searches[0].respond([replacement]);
    await flush();
    assert.match(h.get('products-list').innerHTML, /VH-GT2/);
    assert.doesNotMatch(h.get('products-list').innerHTML, /EGC/);
});

test('typing or clearing old product removes assignment and prevents saving a stale ID', async () => {
    const h = await harness();
    h.input('old-product', 'VH-GT2');
    h.searches[0].respond([oldProduct]);
    await flush();
    h.input('old-product', label(oldProduct));
    h.input('new-products', 'EGC');
    h.searches[1].respond([replacement]);
    await flush();
    h.input('new-products', label(replacement));
    h.input('old-product', 'another product');
    assert.equal(h.get('selected-old').innerHTML, '');
    h.fire('form', 'submit');
    assert.equal(h.saves.length, 0);
    h.input('old-product', '');
    h.searches[2].respond([oldProduct]);
    await flush();
    assert.equal(h.get('products-list').innerHTML, '');
    assert.equal(h.get('selected-old').innerHTML, '');
});

test('editing an existing packet retains its old product without repeating a search', async () => {
    const packet = { id: 77, old_product: oldProduct, new_products: [replacement], status: 'canonical' };
    const h = await harness([packet]);
    h.fire('table', 'click', { target: {
        getAttribute: () => 'edit', closest: () => ({ getAttribute: () => '77' })
    } });
    h.fire('old-product', 'change');
    h.fire('form', 'submit');
    assert.equal(h.saves[0].old_product_id, oldProduct.id);
    assert.equal(h.saves[0].status, 'canonical');
    assert.equal(h.searches.length, 0);
});

test('closing/reopening the form invalidates pending results and resets selection', async () => {
    const h = await harness();
    h.input('old-product', 'VH-GT2');
    h.fire('cancel', 'click');
    h.fire('add', 'click');
    h.searches[0].respond([oldProduct]);
    await flush();
    assert.equal(h.get('products-list').innerHTML, '');
    assert.equal(h.get('selected-old').innerHTML, '');
});
