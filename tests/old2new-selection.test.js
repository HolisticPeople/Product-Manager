const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.OLD2NEW_TEST_SOURCE || path.join(__dirname, '../assets/js/old2new-admin.js'), 'utf8');
const oldProduct = { id: 43591, name: 'Ultimate Green Tea Extract, 2 fl. oz., Chi Tea, Viva Herbals', sku: 'VH-GT2', stock: 36 };
const secondOldProduct = { id: 133638, name: 'O-Mega-Zen EPA', sku: 'NTI-O-Mega-Zen-EPA', stock: 0 };
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
    const confirms = [];
    vm.runInNewContext(source, {
        URL, console,
        // Multi-old saves and hard redirects confirm first; the harness always
        // accepts so the assertions below cover the resulting request.
        confirm(message) { confirms.push(message); return true; },
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
    return { get, fire, input, searches, saves, confirms };
}

// Commit one product into a field the way the admin does: type a term, take
// the suggestions, then pick the exact label.
async function pick(h, field, product, term) {
    h.input(field, term || product.sku);
    h.searches[h.searches.length - 1].respond([product]);
    await flush();
    h.input(field, label(product));
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
    assert.deepEqual(h.saves[0].old_product_ids, [oldProduct.id]);
    assert.deepEqual(h.saves[0].new_product_ids, [replacement.id], 'replacement selection must stay unique');
});

test('several old products commit as chips and save as one group', async () => {
    const h = await harness();
    await pick(h, 'old-product', oldProduct);
    await pick(h, 'old-product', secondOldProduct);
    assert.equal(h.get('old-product').value, '', 'committing an old product must clear the search field');
    assert.match(h.get('selected-old').innerHTML, /VH-GT2/);
    assert.match(h.get('selected-old').innerHTML, /NTI-O-Mega-Zen-EPA/, 'a second old product must not replace the first');
    await pick(h, 'new-products', replacement);
    h.fire('form', 'submit');
    assert.equal(h.confirms.length, 1, 'a multi-old save must be confirmed first');
    assert.match(h.confirms[0], /2 separate Old2New packets/);
    assert.deepEqual(h.saves[0].old_product_ids, [oldProduct.id, secondOldProduct.id]);
    assert.deepEqual(h.saves[0].new_product_ids, [replacement.id]);
});

test('the same old product cannot be added twice', async () => {
    const h = await harness();
    await pick(h, 'old-product', oldProduct);
    await pick(h, 'old-product', oldProduct);
    await pick(h, 'new-products', replacement);
    h.fire('form', 'submit');
    assert.equal(h.confirms.length, 0, 'one distinct old product is not a multi-packet save');
    assert.deepEqual(h.saves[0].old_product_ids, [oldProduct.id]);
});

test('removing an old chip drops it from the save', async () => {
    const h = await harness();
    await pick(h, 'old-product', oldProduct);
    await pick(h, 'old-product', secondOldProduct);
    h.fire('selected-old', 'click', { target: {
        getAttribute: name => (name === 'data-remove-old' ? String(secondOldProduct.id) : '')
    } });
    assert.doesNotMatch(h.get('selected-old').innerHTML, /NTI-O-Mega-Zen-EPA/);
    await pick(h, 'new-products', replacement);
    h.fire('form', 'submit');
    assert.deepEqual(h.saves[0].old_product_ids, [oldProduct.id]);
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

test('unmatched search text never becomes a selection, and saving needs a committed chip', async () => {
    const h = await harness();
    h.input('old-product', 'VH-GT2');
    h.searches[0].respond([oldProduct]);
    await flush();
    // Typed text that matches no suggestion must not commit anything: chips,
    // not the field, are the only source of truth for what gets saved.
    h.input('old-product', 'another product');
    assert.equal(h.get('selected-old').innerHTML, '');
    h.input('new-products', 'EGC');
    h.searches[h.searches.length - 1].respond([replacement]);
    await flush();
    h.input('new-products', label(replacement));
    h.fire('form', 'submit');
    assert.equal(h.saves.length, 0, 'no old product means no save');
    h.input('old-product', '');
    h.searches[h.searches.length - 1].respond([oldProduct]);
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
    assert.deepEqual(h.saves[0].old_product_ids, [oldProduct.id]);
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
