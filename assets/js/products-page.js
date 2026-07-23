document.addEventListener('DOMContentLoaded', function () {
    var config = window.HPProductsManagerData || {};
    var tableElement = document.getElementById('hp-products-table');

    if (!tableElement || typeof Tabulator === 'undefined') {
        return;
    }

    var locale = (config.locale || navigator.language || 'en-US').replace(/_/g, '-');
    var currencyCode = config.currency || 'USD';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safeImageUrl(value) {
        if (!value) {
            return '';
        }
        if (/[<>"']/.test(String(value))) {
            return '';
        }
        try {
            var parsed = new URL(String(value), window.location && window.location.origin ? window.location.origin : undefined);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                return '';
            }
            return parsed.href;
        } catch (e) {
            return '';
        }
    }

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
                var url = safeImageUrl(cell.getValue());
                if (url) {
                    return '<img src="' + escapeHtml(url) + '" alt="" loading="lazy" decoding="async" class="hp-pm-thumb">';
                }
                return '<div class="hp-pm-thumb hp-pm-thumb--placeholder"></div>';
            },
            headerSort: false
        },
        { title: 'Name', field: 'name', minWidth: 250, formatter: nameLinkFormatter },
        { title: 'SKU', field: 'sku', width: 140, formatter: textFormatter },
        { title: 'Cost', field: 'cost', width: 110, hozAlign: 'right', formatter: currencyFormatter },
        { title: 'Price', field: 'price', width: 110, hozAlign: 'right', formatter: currencyFormatter },
        { title: 'Margin', field: 'margin', width: 90, hozAlign: 'right', formatter: marginFormatter },
        { title: 'Brand', field: 'brand', width: 170, formatter: textFormatter },
        {
            title: 'QOH',
            field: 'stock',
            width: 80,
            hozAlign: 'right',
            formatter: function (cell) {
                return locationQuantityFormatter(cell, 'qoh', 'QOH');
            }
        },
        {
            title: 'Reserved',
            field: 'stock_reserved',
            width: 90,
            hozAlign: 'right',
            formatter: function (cell) {
                return locationQuantityFormatter(cell, 'reserved', 'Reserved');
            }
        },
        {
            title: 'Available',
            field: 'stock_available',
            width: 110,
            hozAlign: 'right',
            formatter: function (cell) {
                return locationQuantityFormatter(cell, 'available', 'Available', true);
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

    var allProducts = [];
    var allLocations = [];
    var locationOptionInputs = [];
    var restoredLocationIds = [];

    populateBrands(config.brands || []);
    restoreFilters();
    updateMetrics(config.metrics || {});
    loadAllOnce();

    function restoreFilters() {
        var saved = sessionStorage.getItem('hp_pm_last_filters');
        if (!saved) return;
        try {
            var filters = JSON.parse(saved);
            var search = document.getElementById('hp-pm-filter-search');
            var brand = document.getElementById('hp-pm-filter-brand');
            var status = document.getElementById('hp-pm-filter-status');
            var visibility = document.getElementById('hp-pm-filter-visibility');
            var qohGt0 = document.getElementById('hp-pm-filter-qoh-gt0');
            var reservedGt0 = document.getElementById('hp-pm-filter-reserved-gt0');
            var availableLt0 = document.getElementById('hp-pm-filter-available-lt0');

            if (filters.search && search) search.value = filters.search;
            if (filters.brand_tax && filters.brand_slug && brand) {
                brand.value = filters.brand_tax + ':' + filters.brand_slug;
            }
            if (filters.status && status) status.value = filters.status;
            if (filters.visibility && visibility) visibility.value = filters.visibility;
            if (filters.qoh_gt0 && qohGt0) qohGt0.checked = true;
            if (filters.res_gt0 && reservedGt0) reservedGt0.checked = true;
            if (filters.avail_lt0 && availableLt0) availableLt0.checked = true;
            if (Array.isArray(filters.location_ids)) {
                restoredLocationIds = filters.location_ids.map(String);
            }
        } catch (e) {
            console.warn('Failed to restore filters:', e);
        }
    }

    var filterForm = document.getElementById('hp-products-filters');
    var resetButton = document.getElementById('hp-pm-filters-reset');

    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            applyFilters();
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (filterForm) {
                filterForm.reset();
            }
            locationOptionInputs.forEach(function (input) {
                input.checked = false;
            });
            applyFilters();
        });
    }

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

        var locationIds = locationOptionInputs
            .filter(function (input) { return input.checked; })
            .map(function (input) { return String(input.value); });
        if (locationIds.length) {
            filters.location_ids = locationIds;
        }

        return filters;
    }

    function loadAllOnce() {
        var url = new URL(config.restUrl);
        url.searchParams.set('per_page', 'all');

        table.clearData();

        fetch(url.toString(), {
            cache: 'no-store',
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
                allLocations = Array.isArray(payload.locations) ? payload.locations : [];
                populateLocations(allLocations);
                updateMetrics(payload.metrics || {});
                wireLiveFilters();
                applyFilters(); // Initial filter apply from restored session
            })
            .catch(function (error) {
                console.error('Products Manager:', error);
                allProducts = [];
                table.replaceData([]);
            });
    }

    function applyFilters() {
        var filters = collectFilters();
        sessionStorage.setItem('hp_pm_last_filters', JSON.stringify(filters));

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
        var selectedLocationIds = Array.isArray(filters.location_ids) ? filters.location_ids.map(String) : [];

        var filtered = allProducts.map(function (row) {
            return scopeRowToLocations(row, selectedLocationIds);
        }).filter(function (row) {
            if (selectedLocationIds.length && !row._has_selected_location_activity) {
                return false;
            }

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
        updateLocationSummary(selectedLocationIds);
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

    function populateLocations(locations) {
        var container = document.getElementById('hp-pm-filter-location-options');
        var details = document.getElementById('hp-pm-filter-locations');
        locationOptionInputs = [];
        if (!container || !details) {
            return;
        }

        container.innerHTML = '';
        if (!Array.isArray(locations) || !locations.length) {
            details.hidden = true;
            return;
        }
        details.hidden = false;

        locations.forEach(function (location) {
            if (!location || !location.id || !location.name) {
                return;
            }

            var label = document.createElement('label');
            var input = document.createElement('input');
            var name = document.createElement('span');
            var meta = document.createElement('small');
            input.type = 'checkbox';
            input.value = String(location.id);
            input.checked = restoredLocationIds.indexOf(input.value) !== -1;
            input.addEventListener('change', applyFilters);
            name.textContent = location.name;
            meta.textContent = location.location_type === 'quarantine' || location.role === 'quarantine'
                ? 'Quarantine'
                : '';
            label.appendChild(input);
            label.appendChild(name);
            label.appendChild(meta);
            container.appendChild(label);
            locationOptionInputs.push(input);
        });
    }

    function updateLocationSummary(selectedLocationIds) {
        var summary = document.getElementById('hp-pm-filter-locations-summary');
        if (!summary) {
            return;
        }
        if (!selectedLocationIds.length) {
            summary.textContent = (config.i18n && config.i18n.allLocations) || 'All locations';
            return;
        }

        var selectedNames = allLocations
            .filter(function (location) {
                return selectedLocationIds.indexOf(String(location.id)) !== -1;
            })
            .map(function (location) { return location.name; });
        summary.textContent = selectedNames.length < 3
            ? selectedNames.join(', ')
            : selectedNames.length + ' locations';
    }

    function scopeRowToLocations(row, selectedLocationIds) {
        var scopedRow = Object.assign({}, row);
        var positions = Array.isArray(row.stock_locations) ? row.stock_locations : [];
        if (!positions.length || !allLocations.length) {
            scopedRow.stock_location_breakdown = [];
            scopedRow._has_selected_location_activity = selectedLocationIds.length === 0;
            return scopedRow;
        }

        var positionsByLocation = {};
        positions.forEach(function (position) {
            positionsByLocation[String(position.location_id)] = position;
        });

        var scopedLocations = allLocations.filter(function (location) {
            return !selectedLocationIds.length || selectedLocationIds.indexOf(String(location.id)) !== -1;
        });
        var breakdown = scopedLocations.map(function (location) {
            var position = positionsByLocation[String(location.id)] || {};
            return {
                location_id: String(location.id),
                location_name: location.name,
                qoh: Number(position.qoh || 0),
                reserved: Number(position.reserved || 0),
                available: Number(position.available || 0),
                non_sellable: Number(position.non_sellable || 0)
            };
        });

        scopedRow.stock = breakdown.reduce(function (sum, position) { return sum + position.qoh; }, 0);
        scopedRow.stock_reserved = breakdown.reduce(function (sum, position) { return sum + position.reserved; }, 0);
        scopedRow.stock_available = breakdown.reduce(function (sum, position) { return sum + position.available; }, 0);
        scopedRow.stock_location_breakdown = breakdown;
        scopedRow._has_selected_location_activity = selectedLocationIds.length === 0 || breakdown.some(function (position) {
            return position.qoh !== 0 || position.reserved !== 0 || position.non_sellable !== 0;
        });
        return scopedRow;
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
        return escapeHtml(value);
    }

    function nameLinkFormatter(cell) {
        var value = cell.getValue();
        var data = cell.getRow().getData();
        if (!value) {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }
        if (!data || !data.id || !config.productUrlBase) {
            return escapeHtml(value);
        }
        var href = config.productUrlBase + encodeURIComponent(data.id);
        return '<a href="' + escapeHtml(href) + '">' + escapeHtml(value) + '</a>';
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

    function locationQuantityFormatter(cell, metric, label, colorAvailable) {
        var value = cell.getValue();
        if (value === null || typeof value === 'undefined') {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }
        var num = parseFloat(value);
        if (Number.isNaN(num)) {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }

        var data = cell.getRow().getData();
        var breakdown = data && Array.isArray(data.stock_location_breakdown)
            ? data.stock_location_breakdown
            : [];
        var klass = colorAvailable ? (num > 0 ? 'hp-pm-stock-ok' : 'hp-pm-stock-low') : '';
        if (!breakdown.length) {
            return '<span class="' + klass + '">' + num + '</span>';
        }

        var tooltip = label + ' by location\n' + breakdown.map(function (position) {
            return position.location_name + ': ' + position[metric];
        }).join('\n');
        return '<span class="hp-pm-location-quantity ' + klass + '" tabindex="0" title="'
            + escapeHtml(tooltip) + '" aria-label="' + escapeHtml(tooltip) + '">' + num + '</span>';
    }

    function marginFormatter(cell) {
        var value = cell.getValue();
        if (value === null || typeof value === 'undefined') {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }
        var num = parseFloat(value);
        if (Number.isNaN(num)) {
            return '<span class="hp-pm-cell-muted">&mdash;</span>';
        }
        var klass = num >= 0 ? 'hp-pm-stock-ok' : 'hp-pm-stock-low';
        return '<span class="' + klass + '">' + num.toFixed(1) + '%</span>';
    }
});

