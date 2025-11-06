document.addEventListener('DOMContentLoaded', function () {
    var config = window.HPProductsManagerData || {};
    var tableElement = document.getElementById('hp-products-table');

    if (!tableElement || typeof Tabulator === 'undefined') {
        return;
    }

    var locale = (config.locale || navigator.language || 'en-US').replace(/_/g, '-');
    var currencyCode = config.currency || 'USD';

    var columns = [
        {
            formatter: 'rowSelection',
            titleFormatter: 'rowSelection',
            hozAlign: 'center',
            vertAlign: 'middle',
            width: 40,
            headerSort: false
        },
        {
            title: '',
            field: 'image',
            width: 60,
            hozAlign: 'center',
            cssClass: 'hp-pm-cell-thumb',
            formatter: function (cell) {
                var url = cell.getValue();
                if (url) {
                    return '<img src="' + url + '" alt="" loading="lazy" decoding="async" class="hp-pm-thumb">';
                }
                return '<div class="hp-pm-thumb hp-pm-thumb--placeholder"></div>';
            },
            headerSort: false
        },
        { title: 'Name', field: 'name', minWidth: 250, formatter: 'textarea' },
        { title: 'SKU', field: 'sku', width: 140, formatter: textFormatter },
        { title: 'Cost', field: 'cost', width: 110, hozAlign: 'right', formatter: currencyFormatter },
        { title: 'Price', field: 'price', width: 110, hozAlign: 'right', formatter: currencyFormatter },
        { title: 'Brand', field: 'brand', width: 170, formatter: textFormatter },
        { title: 'QOH', field: 'stock', width: 80, hozAlign: 'right', formatter: quantityFormatter },
        { title: 'Reserved', field: 'stock_reserved', width: 90, hozAlign: 'right', formatter: quantityFormatter },
        {
            title: 'Available',
            field: 'stock_available',
            width: 110,
            hozAlign: 'right',
            formatter: function (cell) {
                var value = cell.getValue();
                if (value === null || typeof value === 'undefined') {
                    return '<span class="hp-pm-cell-muted">&mdash;</span>';
                }
                var num = parseFloat(value);
                var klass = num > 0 ? 'hp-pm-stock-ok' : 'hp-pm-stock-low';
                return '<span class="' + klass + '">' + num + '</span>';
            }
        },
        { title: 'Status', field: 'status', width: 130, formatter: textFormatter },
        { title: 'Visibility', field: 'visibility', minWidth: 160, formatter: textFormatter }
    ];

    var table = new Tabulator(tableElement, {
        data: [],
        layout: 'fitColumns',
        height: '620px',
        selectable: true,
        columns: columns,
        placeholder: (config.i18n && config.i18n.loading) || 'Loading products...'
    });

    populateBrands(config.brands || []);
    updateMetrics(config.metrics || {});
    loadAllOnce();

    var filterForm = document.getElementById('hp-products-filters');
    var resetButton = document.getElementById('hp-pm-filters-reset');

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (filterForm) {
                filterForm.reset();
            }
            applyFilters();
        });
    }

    var allProducts = [];

    function collectFilters() {
        var filters = {};
        var search = document.getElementById('hp-pm-filter-search');
        var brand = document.getElementById('hp-pm-filter-brand');
        var status = document.getElementById('hp-pm-filter-status');
        var visibility = document.getElementById('hp-pm-filter-visibility');
        var qohGt0 = document.getElementById('hp-pm-filter-qoh-gt0');
        var reservedGt0 = document.getElementById('hp-pm-filter-reserved-gt0');
        var availableLt0 = document.getElementById('hp-pm-filter-available-lt0');

        if (search && search.value.trim() !== '') {
            filters.search = search.value.trim();
        }

        if (brand && brand.value) {
            var parts = brand.value.split(':');
            filters.brand_tax = parts[0];
            filters.brand_slug = parts[1] || '';
        }

        if (status && status.value) {
            filters.status = status.value;
        }

        if (visibility && visibility.value) {
            filters.visibility = visibility.value;
        }

        if (qohGt0 && qohGt0.checked) filters.qoh_gt0 = true;
        if (reservedGt0 && reservedGt0.checked) filters.res_gt0 = true;
        if (availableLt0 && availableLt0.checked) filters.avail_lt0 = true;

        return filters;
    }

    function loadAllOnce() {
        var url = new URL(config.restUrl);
        url.searchParams.set('per_page', 'all');

        table.clearData();

        fetch(url.toString(), {
            headers: {
                'X-WP-Nonce': config.nonce || '',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error((config.i18n && config.i18n.loadError) || 'Request failed');
                }
                return response.json();
            })
            .then(function (payload) {
                allProducts = Array.isArray(payload.products) ? payload.products : [];
                table.setData(allProducts);
                updateMetrics(payload.metrics || {});
                wireLiveFilters();
                updateCount(allProducts.length);
            })
            .catch(function (error) {
                console.error('Products Manager:', error);
                allProducts = [];
                table.replaceData([]);
            });
    }

    function applyFilters() {
        var filters = collectFilters();

        // Map brand slug to name for comparison
        var slugToName = {};
        (config.brands || []).forEach(function (term) {
            if (term && term.slug) {
                slugToName[term.slug] = term.name || term.slug;
            }
        });

        var searchValue = (filters.search || '').toLowerCase();
        var brandName = filters.brand_slug ? (slugToName[filters.brand_slug] || '').toLowerCase() : '';
        var statusValue = filters.status || '';
        var visibilityCode = filters.visibility || '';
        var qohFlag = !!filters.qoh_gt0;
        var resFlag = !!filters.res_gt0;
        var availFlag = !!filters.avail_lt0;

        var filtered = allProducts.filter(function (row) {
            // Search in name or SKU
            if (searchValue) {
                var name = (row.name || '').toLowerCase();
                var sku = (row.sku || '').toLowerCase();
                if (name.indexOf(searchValue) === -1 && sku.indexOf(searchValue) === -1) {
                    return false;
                }
            }

            // Brand contains selected term name
            if (brandName) {
                var brand = (row.brand || '').toLowerCase();
                if (brand.indexOf(brandName) === -1) {
                    return false;
                }
            }

            // Status match
            if (statusValue) {
                var rowStatus = (row.status || '').toLowerCase();
                if (statusValue === 'enabled' && rowStatus !== 'enabled') return false;
                if (statusValue === 'disabled' && rowStatus !== 'disabled') return false;
            }

            // Visibility
            if (visibilityCode) {
                if ((row.visibility_code || '').toLowerCase() !== visibilityCode) return false;
            }

            // Stock flags
            if (qohFlag && !(row.stock > 0)) return false;
            if (resFlag && !(row.stock_reserved > 0)) return false;
            if (availFlag && !(row.stock_available < 0)) return false;

            return true;
        });

        table.setData(filtered);
        updateCount(filtered.length);
    }

    function wireLiveFilters() {
        var search = document.getElementById('hp-pm-filter-search');
        var brand = document.getElementById('hp-pm-filter-brand');
        var status = document.getElementById('hp-pm-filter-status');
        var visibility = document.getElementById('hp-pm-filter-visibility');
        var qoh = document.getElementById('hp-pm-filter-qoh-gt0');
        var res = document.getElementById('hp-pm-filter-reserved-gt0');
        var avail = document.getElementById('hp-pm-filter-available-lt0');

        function debounce(fn, delay) {
            var t;
            return function () {
                var args = arguments;
                clearTimeout(t);
                t = setTimeout(function () { fn.apply(null, args); }, delay);
            };
        }

        if (search) {
            search.addEventListener('input', debounce(applyFilters, 200));
        }
        if (brand) brand.addEventListener('change', applyFilters);
        if (status) status.addEventListener('change', applyFilters);
        if (visibility) visibility.addEventListener('change', applyFilters);
        if (qoh) qoh.addEventListener('change', applyFilters);
        if (res) res.addEventListener('change', applyFilters);
        if (avail) avail.addEventListener('change', applyFilters);
    }

    function updateCount(count) {
        var el = document.getElementById('hp-pm-table-count');
        if (!el) return;
        el.textContent = 'Showing ' + count + ' products';
    }

    function populateBrands(brands) {
        var select = document.getElementById('hp-pm-filter-brand');
        if (!select || !Array.isArray(brands)) {
            return;
        }

        select.innerHTML = '';
        var defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = (config.i18n && config.i18n.allBrands) || 'All brands';
        select.appendChild(defaultOption);

        brands.forEach(function (term) {
            if (!term || !term.slug) {
                return;
            }
            var option = document.createElement('option');
            option.value = term.taxonomy + ':' + term.slug;
            option.textContent = term.name;
            select.appendChild(option);
        });
    }

    function updateMetrics(metrics) {
        var mapping = {
            total: 'hp-pm-metric-total',
            enabled: 'hp-pm-metric-enabled',
            stock_cost: 'hp-pm-metric-stock-cost',
            reserved: 'hp-pm-metric-reserved'
        };

        Object.keys(mapping).forEach(function (key) {
            var element = document.getElementById(mapping[key]);
            if (!element) {
                return;
            }

            var value = metrics[key];
            if (value === null || typeof value === 'undefined') {
                element.textContent = '--';
                return;
            }

            if (key === 'stock_cost') {
                try {
                    var formatter = new Intl.NumberFormat(locale, {
                        style: 'currency',
                        currency: currencyCode,
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    element.textContent = formatter.format(parseFloat(value));
                } catch (e) {
                    element.textContent = parseFloat(value).toFixed(2);
                }
                return;
            }

            element.textContent = value;
        });
    }

    function textFormatter(cell) {
        var value = cell.getValue();
        if (!value) {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }
        return value;
    }

    function currencyFormatter(cell) {
        var amount = cell.getValue();
        if (amount === null || typeof amount === 'undefined' || amount === '') {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }

        var number = parseFloat(amount);
        if (Number.isNaN(number)) {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }

        try {
            var formatter = new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: currencyCode,
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            return formatter.format(number);
        } catch (error) {
            return number.toFixed(2);
        }
    }

    function quantityFormatter(cell) {
        var value = cell.getValue();
        if (value === null || typeof value === 'undefined') {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }
        var num = parseFloat(value);
        if (Number.isNaN(num)) {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }
        return String(num);
    }
});

