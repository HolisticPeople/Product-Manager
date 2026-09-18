document.addEventListener('DOMContentLoaded', function () {
    var config = window.HPOld2NewAdminData || {};
    var table = document.getElementById('hp-old2new-table');
    var form = document.getElementById('hp-old2new-form');
    var modal = document.getElementById('hp-old2new-modal');
    var addButton = document.getElementById('hp-old2new-add');
    var cancelButton = document.getElementById('hp-old2new-cancel');
    var closeButton = document.getElementById('hp-old2new-close');
    var selectedOld = document.getElementById('hp-old2new-selected-old');
    var statusNode = document.getElementById('hp-old2new-status');
    var oldInput = document.getElementById('hp-old2new-old-product');
    var newInput = document.getElementById('hp-old2new-new-products');
    var productList = document.getElementById('hp-old2new-products-list');
    var selectedNewProducts = document.getElementById('hp-old2new-selected-new-products');
    var statusSelect = document.getElementById('hp-old2new-status-select');
    var targetSelect = document.getElementById('hp-old2new-target-select');
    var bannerWindowInput = document.getElementById('hp-old2new-banner-window');
    var redirectType = document.getElementById('hp-old2new-redirect-type');
    var packetId = document.getElementById('hp-old2new-packet-id');
    var startedAt = document.getElementById('hp-old2new-hard-redirect-started-at');
    var oldMessage = document.getElementById('hp-old2new-custom-old-message');
    var newMessage = document.getElementById('hp-old2new-custom-new-message');
    var badgeText = document.getElementById('hp-old2new-badge-text');
    var preview = document.getElementById('hp-old2new-message-preview');
    var saveButton = document.getElementById('hp-old2new-save');

    if (!table || !form) {
        return;
    }

    var products = Object.create(null);
    var searchRequest = 0;
    var packets = [];
    // One packet still owns ONE old product; the form collects several so a
    // single save can fan out into one packet per old product.
    var oldProducts = [];
    var newProducts = [];
    var originalStatus = '';

    var statusLabels = {
        basic_discontinue: 'Basic Discontinue',
        canonical: 'Canonical',
        hard_redirect: 'Hard Redirect',
        replace: 'Basic Discontinue',
        discontinue: 'Canonical'
    };

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
            return '<span class="hp-old2new-product-card hp-old2new-product-card--missing">Missing product</span>';
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

    function warnings(packet) {
        var rows = Array.isArray(packet.health_warnings) ? packet.health_warnings : [];
        if (!rows.length) {
            return '<span class="hp-old2new-health hp-old2new-health--ok">OK</span>';
        }

        return '<ul class="hp-old2new-health hp-old2new-health--warn">'
            + rows.map(function (row) { return '<li>' + escapeHtml(row) + '</li>'; }).join('')
            + '</ul>';
    }

    function bannerWindow(packet) {
        if (packet.status !== 'hard_redirect' || !packet.banner_expires_at) {
            return '';
        }
        if (packet.banner_expired) {
            return '<br><small class="hp-old2new-window hp-old2new-window--expired">Banner hidden (window over); 301 stays active</small>';
        }
        var expires = new Date(packet.banner_expires_at + 'T00:00:00Z');
        var days = Math.ceil((expires.getTime() - Date.now()) / 86400000);
        if (!isFinite(days)) {
            return '';
        }
        return '<br><small class="hp-old2new-window">New-product banner ends in ' + days + ' day' + (days === 1 ? '' : 's')
            + ' (' + escapeHtml(packet.banner_expires_at) + ')</small>';
    }

    function viewLinks(packet) {
        var links = '';
        var oldUrl = packet.old_product ? safeImageUrl(packet.old_product.permalink) : '';
        if (oldUrl) {
            links += '<a class="button" href="' + escapeHtml(oldUrl) + '" target="_blank" rel="noopener">View old</a>';
        }
        // "View new" opens the target with the o2n referral so the admin sees
        // the replacement message exactly as a referred customer would.
        var target = packet.target_product || (packet.new_products || [])[0];
        var newUrl = target ? safeImageUrl(target.permalink) : '';
        if (newUrl && packet.old_product) {
            newUrl += (newUrl.indexOf('?') > -1 ? '&' : '?') + 'o2n=' + encodeURIComponent(packet.old_product.id);
            links += '<a class="button" href="' + escapeHtml(newUrl) + '" target="_blank" rel="noopener">View new</a>';
        }
        return links;
    }

    function renderTable() {
        if (!packets.length) {
            table.innerHTML = '<p>' + escapeHtml((config.i18n && config.i18n.empty) || 'No Old2New packets yet.') + '</p>';
            return;
        }

        table.innerHTML = packets.map(function (packet) {
            var target = packet.target_product;
            var targetHtml = target
                ? '<span class="hp-old2new-target">' + escapeHtml(target.name) + '<br><small>' + escapeHtml(packet.target_reason || '') + '</small></span>'
                : '<span class="hp-old2new-target">No target</span>';
            return '<div class="hp-old2new-row" data-packet-id="' + escapeHtml(packet.id) + '">'
                + '<div>' + productCard(packet.old_product) + '</div>'
                + '<div class="hp-old2new-product-stack">' + (packet.new_products || []).map(productCard).join('') + '</div>'
                + '<div><strong>Status</strong><br><span class="hp-old2new-status-badge hp-old2new-status-badge--' + escapeHtml(packet.status || 'basic_discontinue') + '">' + escapeHtml(statusLabels[packet.status] || packet.status || 'Basic Discontinue') + '</span>' + bannerWindow(packet) + '</div>'
                + '<div><strong>Redirect</strong><br>' + escapeHtml(packet.redirect_type || 'none') + '</div>'
                + '<div><strong>Target</strong><br>' + targetHtml + '</div>'
                + '<div><strong>Health</strong><br>' + warnings(packet) + '</div>'
                + '<div class="hp-old2new-actions">'
                + viewLinks(packet)
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

    function normalizeStatus(value) {
        if (value === 'replace') return 'basic_discontinue';
        if (value === 'discontinue') return 'canonical';
        return value || 'basic_discontinue';
    }

    function renderTemplate(template) {
        // Each packet renders its own old product server-side; the preview
        // joins the selection so the admin sees every name they picked.
        var oldName = oldProducts.map(function (product) { return product.name || ''; }).filter(Boolean).join(', ');
        var names = newProducts.map(function (product) { return product.name || ''; }).filter(Boolean);
        return String(template || '')
            .replace(/\{old_product\}/g, oldName)
            .replace(/\{new_product\}/g, names[0] || '')
            .replace(/\{new_products\}/g, names.join(', '))
            .replace(/\{new_product_count\}/g, String(newProducts.length));
    }

    function oldProductInStock() {
        // Any still-sellable old product means the "limited stock remains"
        // wording is the honest default for the preview.
        return oldProducts.some(function (product) {
            if (!product) return false;
            if (product.stock !== null && product.stock !== undefined) {
                return Number(product.stock) > 0;
            }
            return product.stock_status === 'instock';
        });
    }

    function updatePreview() {
        if (!preview) return;
        var defaultOld;
        if (oldProductInStock()) {
            defaultOld = newProducts.length > 1
                ? 'This product is being discontinued — limited stock remains. Dr. Cousens recommends these {new_products} going forward.'
                : 'This product is being discontinued — limited stock remains. Dr. Cousens recommends this {new_product} going forward.';
        } else {
            defaultOld = newProducts.length > 1
                ? "This product is no longer available. Follow Dr. Cousens' recommendations for these {new_products}."
                : "This product is no longer available. Follow Dr. Cousens' recommendation for this {new_product}.";
        }
        var defaultNew = 'This product is now replacing the previous product.';
        var oldValue = oldMessage && oldMessage.value ? oldMessage.value : defaultOld;
        var newValue = newMessage && newMessage.value ? newMessage.value : defaultNew;
        var badgeValue = badgeText && badgeText.value ? badgeText.value : ((config.i18n && config.i18n.defaultBadge) || 'See new product');
        preview.innerHTML = '<strong>Old banner:</strong> ' + escapeHtml(renderTemplate(oldValue))
            + '<br><strong>New banner:</strong> ' + escapeHtml(renderTemplate(newValue))
            + '<br><strong>Compact badge:</strong> ' + escapeHtml(badgeValue);
    }

    function showForm(packet) {
        searchRequest++;
        productList.innerHTML = '';
        products = Object.create(null);
        openModal();
        packetId.value = packet && packet.id ? String(packet.id) : '';
        originalStatus = packet && packet.status ? normalizeStatus(packet.status) : '';
        oldProducts = packet && packet.old_product ? [packet.old_product] : [];
        newProducts = packet && Array.isArray(packet.new_products) ? packet.new_products.slice() : [];
        oldProducts.concat(newProducts).forEach(function (product) {
            if (product) products[product.id] = product;
        });
        oldInput.value = '';
        newInput.value = '';
        statusSelect.value = normalizeStatus(packet && packet.status ? packet.status : 'basic_discontinue');
        startedAt.value = packet && packet.hard_redirect_started_at ? packet.hard_redirect_started_at : '';
        if (oldMessage) oldMessage.value = packet && packet.custom_old_message ? packet.custom_old_message : '';
        if (newMessage) newMessage.value = packet && packet.custom_new_message ? packet.custom_new_message : '';
        if (badgeText) badgeText.value = packet && packet.badge_text ? packet.badge_text : '';
        if (bannerWindowInput) bannerWindowInput.value = packet && packet.banner_window_days ? String(packet.banner_window_days) : '';
        updateRedirectType();
        renderSelectedOld();
        renderSelectedNewProducts(packet ? packet.target_product_id : 0);
        updatePreview();
    }

    function updateRedirectType() {
        var type = statusSelect.value === 'canonical' ? 'canonical' : (statusSelect.value === 'hard_redirect' ? '301' : 'none');
        redirectType.textContent = type;
        updatePreview();
    }

    function openModal() {
        if (modal) modal.hidden = false;
    }

    function closeModal() {
        searchRequest++;
        if (modal) modal.hidden = true;
    }

    function productChip(product, removeAttribute) {
        var img = safeImageUrl(product.image);
        return '<span class="hp-old2new-chip">'
            + (img ? '<img class="hp-old2new-chip__thumb" src="' + escapeHtml(img) + '" alt="" loading="lazy">' : '')
            + escapeHtml(product.name) + ' (' + escapeHtml(product.sku || '') + ')'
            + (removeAttribute ? '<button type="button" class="button-link" ' + removeAttribute + '="' + escapeHtml(product.id) + '">x</button>' : '')
            + '</span>';
    }

    function renderSelectedOld() {
        if (!selectedOld) return;
        selectedOld.innerHTML = oldProducts.map(function (product) {
            return productChip(product, 'data-remove-old');
        }).join('');
        updatePreview();
    }

    function renderTargetOptions(selectedId) {
        if (!targetSelect) return;
        var current = selectedId !== undefined ? String(selectedId || 0) : targetSelect.value;
        targetSelect.innerHTML = '<option value="0">Auto — highest total sales</option>'
            + newProducts.map(function (product) {
                return '<option value="' + escapeHtml(product.id) + '">' + escapeHtml(product.name + ' [' + (product.sku || '') + ']') + '</option>';
            }).join('');
        // Keep the choice if that product is still in the packet; else Auto.
        targetSelect.value = newProducts.some(function (p) { return String(p.id) === current; }) ? current : '0';
    }

    function renderSelectedNewProducts(selectedTargetId) {
        selectedNewProducts.innerHTML = newProducts.map(function (product) {
            return productChip(product, 'data-remove-new');
        }).join('');
        renderTargetOptions(selectedTargetId);
        updatePreview();
    }

    function productLabel(product) {
        return product.name + ' [' + (product.sku || '') + ']';
    }

    function searchProducts(input) {
        var term = input.value;
        var request = ++searchRequest;
        if (!term || term.length < 2) {
            productList.innerHTML = '';
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
                if (request !== searchRequest || input.value !== term) return;
                var rows = Array.isArray(payload.products) ? payload.products : [];
                productList.innerHTML = rows.map(function (product) {
                    products[product.id] = product;
                    return '<option value="' + escapeHtml(productLabel(product)) + '" data-id="' + escapeHtml(product.id) + '"></option>';
                }).join('');
            })
            .catch(function () {});
    }

    function productFromInput(input) {
        // Both fields share suggestions, but a selection must survive their replacement.
        var id = Object.keys(products).find(function (key) {
            return productLabel(products[key]) === input.value;
        });
        return id ? products[id] : null;
    }

    function selectProduct(input) {
        var product = productFromInput(input);
        if (product) {
            var bucket = input === oldInput ? oldProducts : newProducts;
            if (!bucket.some(function (item) { return String(item.id) === String(product.id); })) {
                bucket.push(product);
            }
            input.value = '';
            if (input === oldInput) {
                renderSelectedOld();
            } else {
                renderSelectedNewProducts();
            }
        }
        if (product) {
            searchRequest++;
            productList.innerHTML = '';
        }
        return product;
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

    cancelButton.addEventListener('click', closeModal);
    if (closeButton) closeButton.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });
    }

    statusSelect.addEventListener('change', updateRedirectType);
    [oldInput, newInput].forEach(function (input) {
        input.addEventListener('input', function () {
            // Native datalist selection emits input before change (which may wait for blur).
            if (!selectProduct(input)) searchProducts(input);
        });
        input.addEventListener('change', function () { selectProduct(input); });
    });
    [oldMessage, newMessage, badgeText].forEach(function (field) {
        if (field) field.addEventListener('input', updatePreview);
    });

    selectedNewProducts.addEventListener('click', function (event) {
        var id = event.target && event.target.getAttribute ? event.target.getAttribute('data-remove-new') : '';
        if (!id) return;
        newProducts = newProducts.filter(function (product) { return String(product.id) !== String(id); });
        renderSelectedNewProducts();
    });

    if (selectedOld) {
        selectedOld.addEventListener('click', function (event) {
            var id = event.target && event.target.getAttribute ? event.target.getAttribute('data-remove-old') : '';
            if (!id) return;
            oldProducts = oldProducts.filter(function (product) { return String(product.id) !== String(id); });
            renderSelectedOld();
        });
    }

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
            }).then(function (response) {
                if (!response.ok) {
                    setStatus('Unable to delete Old2New packet.');
                }
                return loadPackets();
            });
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!oldProducts.length || !newProducts.length) {
            setStatus('Choose at least one old product and at least one new product.');
            return;
        }

        if (statusSelect.value === 'hard_redirect' && originalStatus !== 'hard_redirect') {
            // Preview-first for the one transition that takes a live page down:
            // name every page this save is about to 301.
            var oldUrls = oldProducts.map(function (product) {
                return product.permalink || (product.name + ' [' + (product.sku || '') + ']');
            }).join('\n');
            if (!confirm('Hard Redirect takes ' + (oldProducts.length > 1 ? 'these old product pages' : 'the old product page') + ' down:\n\n'
                + oldUrls + '\n\n' + (oldProducts.length > 1 ? 'They' : 'It') + ' will return a 301 straight to the selected new product. '
                + 'Customers can no longer reach the old page or buy remaining stock.\n\nContinue?')) {
                return;
            }
        }

        if (oldProducts.length > 1 && !confirm('Save creates ' + oldProducts.length + ' separate Old2New packets — one per old product, all pointing at the '
            + newProducts.length + ' replacement' + (newProducts.length === 1 ? '' : 's') + ' you picked.\n\nContinue?')) {
            return;
        }

        var id = packetId.value;
        var method = id ? 'PUT' : 'POST';
        var url = id ? (config.packetsUrl + '/' + encodeURIComponent(id)) : config.packetsUrl;
        var body = {
            old_product_ids: oldProducts.map(function (product) { return product.id; }),
            new_product_ids: newProducts.map(function (product) { return product.id; }),
            status: statusSelect.value,
            hard_redirect_started_at: startedAt.value,
            target_product_id: targetSelect ? parseInt(targetSelect.value, 10) || 0 : 0,
            banner_window_days: bannerWindowInput && bannerWindowInput.value ? parseInt(bannerWindowInput.value, 10) || 180 : 180,
            custom_old_message: oldMessage ? oldMessage.value : '',
            custom_new_message: newMessage ? newMessage.value : '',
            badge_text: badgeText ? badgeText.value : ''
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
                if (!response.ok) {
                    // Surface the REST error message (e.g. duplicate old SKU)
                    // instead of a generic failure.
                    return response.json()
                        .catch(function () { return {}; })
                        .then(function (error) {
                            throw new Error(error && error.message ? error.message : '');
                        });
                }
                return response.json();
            })
            .then(function () {
                closeModal();
                loadPackets();
            })
            .catch(function (error) {
                setStatus(error && error.message ? error.message : 'Unable to save Old2New packet.');
            })
            .finally(function () {
                if (saveButton) {
                    saveButton.disabled = false;
                }
            });
    });

    loadPackets();
});
