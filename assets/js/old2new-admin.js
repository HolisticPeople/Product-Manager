document.addEventListener('DOMContentLoaded', function () {
    var config = window.HPOld2NewAdminData || {};
    var table = document.getElementById('hp-old2new-table');
    var form = document.getElementById('hp-old2new-form');
    var addButton = document.getElementById('hp-old2new-add');
    var cancelButton = document.getElementById('hp-old2new-cancel');
    var statusNode = document.getElementById('hp-old2new-status');
    var oldInput = document.getElementById('hp-old2new-old-product');
    var newInput = document.getElementById('hp-old2new-new-products');
    var productList = document.getElementById('hp-old2new-products-list');
    var selectedNewProducts = document.getElementById('hp-old2new-selected-new-products');
    var statusSelect = document.getElementById('hp-old2new-status-select');
    var redirectType = document.getElementById('hp-old2new-redirect-type');
    var packetId = document.getElementById('hp-old2new-packet-id');
    var startedAt = document.getElementById('hp-old2new-hard-redirect-started-at');
    var saveButton = document.getElementById('hp-old2new-save');

    if (!table || !form) {
        return;
    }

    var products = {};
    var packets = [];
    var oldProduct = null;
    var newProducts = [];

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safeImageUrl(value) {
        if (!value || /[<>"']/.test(String(value))) {
            return '';
        }
        try {
            var parsed = new URL(String(value), window.location.origin);
            return (parsed.protocol === 'http:' || parsed.protocol === 'https:') ? parsed.href : '';
        } catch (error) {
            return '';
        }
    }

    function headers() {
        return {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-WP-Nonce': config.nonce || ''
        };
    }

    function setStatus(message) {
        if (statusNode) {
            statusNode.textContent = message || '';
        }
    }

    function productCard(product) {
        if (!product) {
            return '';
        }
        var image = safeImageUrl(product.image);
        var thumb = image
            ? '<img src="' + escapeHtml(image) + '" alt="" loading="lazy" decoding="async">'
            : '';
        var url = product.admin_url || ((config.productUrlBase || '') + encodeURIComponent(product.id || ''));
        return '<a class="hp-old2new-product-card" href="' + escapeHtml(url) + '">'
            + '<span class="hp-old2new-product-card__thumb">' + thumb + '</span>'
            + '<span class="hp-old2new-product-card__body">'
            + '<span class="hp-old2new-product-card__title">' + escapeHtml(product.name) + '</span>'
            + '<span class="hp-old2new-product-card__meta">SKU: ' + escapeHtml(product.sku || '') + '</span>'
            + '<span class="hp-old2new-product-card__stock">' + escapeHtml(product.stock_label || 'Stock: --') + '</span>'
            + '</span>'
            + '</a>';
    }

    function renderTable() {
        if (!packets.length) {
            table.innerHTML = '<p>' + escapeHtml((config.i18n && config.i18n.empty) || 'No Old2New packets yet.') + '</p>';
            return;
        }

        table.innerHTML = packets.map(function (packet) {
            return '<div class="hp-old2new-row" data-packet-id="' + escapeHtml(packet.id) + '">'
                + '<div>' + productCard(packet.old_product) + '</div>'
                + '<div class="hp-old2new-product-stack">' + (packet.new_products || []).map(productCard).join('') + '</div>'
                + '<div><strong>Status</strong><br>' + escapeHtml(packet.status || 'replace') + '</div>'
                + '<div><strong>Redirect</strong><br>' + escapeHtml(packet.redirect_type || 'none') + '</div>'
                + '<div class="hp-old2new-actions">'
                + '<button type="button" class="button" data-action="edit">Edit</button>'
                + '<button type="button" class="button" data-action="delete">Delete</button>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    function loadPackets() {
        setStatus((config.i18n && config.i18n.loading) || 'Loading Old2New packets...');
        return fetch(config.packetsUrl, {
            headers: headers(),
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) throw new Error('load failed');
                return response.json();
            })
            .then(function (payload) {
                packets = Array.isArray(payload.packets) ? payload.packets : [];
                renderTable();
                setStatus('');
            })
            .catch(function () {
                setStatus('Unable to load Old2New packets.');
            });
    }

    function showForm(packet) {
        form.hidden = false;
        packetId.value = packet && packet.id ? String(packet.id) : '';
        oldProduct = packet ? packet.old_product : null;
        newProducts = packet && Array.isArray(packet.new_products) ? packet.new_products.slice() : [];
        oldInput.value = oldProduct ? (oldProduct.name + ' [' + oldProduct.sku + ']') : '';
        newInput.value = '';
        statusSelect.value = packet && packet.status ? packet.status : 'replace';
        startedAt.value = packet && packet.hard_redirect_started_at ? packet.hard_redirect_started_at : '';
        updateRedirectType();
        renderSelectedNewProducts();
    }

    function updateRedirectType() {
        var type = statusSelect.value === 'discontinue' ? 'canonical' : (statusSelect.value === 'hard_redirect' ? '301' : 'none');
        redirectType.textContent = type;
    }

    function renderSelectedNewProducts() {
        selectedNewProducts.innerHTML = newProducts.map(function (product) {
            return '<span class="hp-old2new-chip">'
                + escapeHtml(product.name) + ' (' + escapeHtml(product.sku || '') + ')'
                + '<button type="button" class="button-link" data-remove-new="' + escapeHtml(product.id) + '">x</button>'
                + '</span>';
        }).join('');
    }

    function searchProducts(term) {
        if (!term || term.length < 2) {
            return;
        }
        var url = new URL(config.searchUrl);
        url.searchParams.set('search', term);
        fetch(url.toString(), {
            headers: headers(),
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) throw new Error('search failed');
                return response.json();
            })
            .then(function (payload) {
                var rows = Array.isArray(payload.products) ? payload.products : [];
                productList.innerHTML = rows.map(function (product) {
                    products[product.id] = product;
                    return '<option value="' + escapeHtml(product.name + ' [' + product.sku + ']') + '" data-id="' + escapeHtml(product.id) + '"></option>';
                }).join('');
            })
            .catch(function () {});
    }

    function productFromInput(input) {
        var value = input.value;
        var options = Array.prototype.slice.call(productList.querySelectorAll('option'));
        var option = options.find(function (candidate) { return candidate.value === value; });
        var id = option ? option.getAttribute('data-id') : '';
        return id && products[id] ? products[id] : null;
    }

    document.querySelectorAll('[data-hp-pm-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            var tab = button.getAttribute('data-hp-pm-tab');
            document.querySelectorAll('[data-hp-pm-tab]').forEach(function (tabButton) {
                tabButton.classList.toggle('nav-tab-active', tabButton === button);
            });
            document.querySelectorAll('[data-hp-pm-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-hp-pm-panel') !== tab;
            });
        });
    });

    addButton.addEventListener('click', function () {
        showForm(null);
    });

    cancelButton.addEventListener('click', function () {
        form.hidden = true;
    });

    statusSelect.addEventListener('change', updateRedirectType);
    oldInput.addEventListener('input', function () { searchProducts(oldInput.value); });
    newInput.addEventListener('input', function () { searchProducts(newInput.value); });

    oldInput.addEventListener('change', function () {
        oldProduct = productFromInput(oldInput);
    });

    newInput.addEventListener('change', function () {
        var product = productFromInput(newInput);
        if (product && !newProducts.some(function (item) { return String(item.id) === String(product.id); })) {
            newProducts.push(product);
            newInput.value = '';
            renderSelectedNewProducts();
        }
    });

    selectedNewProducts.addEventListener('click', function (event) {
        var id = event.target && event.target.getAttribute ? event.target.getAttribute('data-remove-new') : '';
        if (!id) return;
        newProducts = newProducts.filter(function (product) { return String(product.id) !== String(id); });
        renderSelectedNewProducts();
    });

    table.addEventListener('click', function (event) {
        var action = event.target && event.target.getAttribute ? event.target.getAttribute('data-action') : '';
        if (!action) return;
        var row = event.target.closest('[data-packet-id]');
        var id = row ? row.getAttribute('data-packet-id') : '';
        var packet = packets.find(function (item) { return String(item.id) === String(id); });
        if (!packet) return;

        if (action === 'edit') {
            showForm(packet);
            return;
        }

        if (action === 'delete' && confirm((config.i18n && config.i18n.deleteConfirm) || 'Delete this Old2New packet?')) {
            fetch(config.packetsUrl + '/' + encodeURIComponent(id), {
                method: 'DELETE',
                headers: headers(),
                credentials: 'same-origin'
            }).then(loadPackets);
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!oldProduct || !newProducts.length) {
            setStatus('Choose one old product and at least one new product.');
            return;
        }

        var id = packetId.value;
        var method = id ? 'PUT' : 'POST';
        var url = id ? (config.packetsUrl + '/' + encodeURIComponent(id)) : config.packetsUrl;
        var body = {
            old_product_id: oldProduct.id,
            new_product_ids: newProducts.map(function (product) { return product.id; }),
            status: statusSelect.value,
            hard_redirect_started_at: startedAt.value
        };

        if (saveButton) {
            saveButton.disabled = true;
        }

        fetch(url, {
            method: method,
            headers: headers(),
            credentials: 'same-origin',
            body: JSON.stringify(body)
        })
            .then(function (response) {
                if (!response.ok) throw new Error('save failed');
                return response.json();
            })
            .then(function () {
                form.hidden = true;
                loadPackets();
            })
            .catch(function () {
                setStatus('Unable to save Old2New packet.');
            })
            .finally(function () {
                if (saveButton) {
                    saveButton.disabled = false;
                }
            });
    });

    loadPackets();
});
