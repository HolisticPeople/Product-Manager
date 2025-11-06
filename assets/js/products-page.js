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
        {
            title: 'Stock',
            field: 'stock',
            width: 130,
            formatter: function (cell) {
                var value = cell.getValue();
                var klass = value > 0 ? 'hp-pm-stock-ok' : 'hp-pm-stock-low';

                if (value === null || typeof value === 'undefined') {
                    return '<span class="hp-pm-cell-muted">&mdash;</span>';
                }

                return '<span class="' + klass + '">' + value + '</span>';
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
    loadProducts({});

    var filterForm = document.getElementById('hp-products-filters');
    var resetButton = document.getElementById('hp-pm-filters-reset');

    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            loadProducts(collectFilters());
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (filterForm) {
                filterForm.reset();
            }
            loadProducts({});
        });
    }

    function collectFilters() {
        var filters = {};
        var search = document.getElementById('hp-pm-filter-search');
        var brand = document.getElementById('hp-pm-filter-brand');
        var status = document.getElementById('hp-pm-filter-status');
        var stockMin = document.getElementById('hp-pm-filter-stock-min');
        var stockMax = document.getElementById('hp-pm-filter-stock-max');

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

        if (stockMin && stockMin.value !== '') {
            filters.stock_min = stockMin.value;
        }

        if (stockMax && stockMax.value !== '') {
            filters.stock_max = stockMax.value;
        }

        return filters;
    }

    function loadProducts(query) {
        query = query || {};
        var url = new URL(config.restUrl);
        url.searchParams.set('per_page', config.perPage || 50);

        Object.keys(query).forEach(function (key) {
            if (query[key] !== '' && query[key] !== null && typeof query[key] !== 'undefined') {
                url.searchParams.set(key, query[key]);
            }
        });

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
                table.setData(payload.products || []);
                updateMetrics(payload.metrics || {});
            })
            .catch(function (error) {
                console.error('Products Manager:', error);
                table.replaceData([]);
            });
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
            catalog: 'hp-pm-metric-catalog',
            low_stock: 'hp-pm-metric-low-stock',
            hidden: 'hp-pm-metric-hidden',
            avg_margin: 'hp-pm-metric-avg-margin'
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

            if (key === 'avg_margin') {
                element.textContent = value + '%';
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
});

