(function () {
    var config = window.HPOld2NewFrontendData || {};
    var cache = {};

    if (!config.badgeUrl || !window.fetch) {
        return;
    }

    function productIdFromNode(node) {
        if (!node || !node.getAttribute) {
            return '';
        }

        var id = node.getAttribute('data-product_id')
            || node.getAttribute('data-product-id')
            || node.getAttribute('data-post-id')
            || '';
        if (id) {
            return id;
        }

        var child = node.querySelector('[data-product_id], [data-product-id], [data-post-id]');
        return child
            ? (child.getAttribute('data-product_id') || child.getAttribute('data-product-id') || child.getAttribute('data-post-id') || '')
            : '';
    }

    function badge(text, url) {
        var link = document.createElement('a');
        link.className = 'old2new-product-badge old2new-product-badge--fibo';
        link.href = url || '#';
        link.textContent = text || config.defaultText || 'See new product';
        return link;
    }

    function decorate(nodes) {
        var ids = [];
        nodes.forEach(function (node) {
            var id = productIdFromNode(node);
            if (id && !Object.prototype.hasOwnProperty.call(cache, id)) {
                ids.push(id);
            }
        });

        if (!ids.length) {
            nodes.forEach(applyBadge);
            return;
        }

        var url = new URL(config.badgeUrl, window.location.origin);
        url.searchParams.set('product_ids', ids.join(','));
        fetch(url.toString(), { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('badge lookup failed');
                return response.json();
            })
            .then(function (payload) {
                var rows = payload && payload.badges ? payload.badges : {};
                ids.forEach(function (id) {
                    cache[id] = rows[id] || false;
                });
                nodes.forEach(applyBadge);
            })
            .catch(function () {});
    }

    function applyBadge(node) {
        if (!node || node.querySelector('.old2new-product-badge')) {
            return;
        }

        var id = productIdFromNode(node);
        var row = id ? cache[id] : null;
        if (!row) {
            return;
        }

        node.appendChild(badge(row.text, row.url));
    }

    function scan() {
        var candidates = Array.prototype.slice.call(document.querySelectorAll(
            '.dgwt-wcas-suggestion, .dgwt-wcas-details-product, .fibo-search-suggestion'
        ));
        var nodes = candidates.filter(function (node) {
            return productIdFromNode(node);
        });
        if (nodes.length) {
            decorate(nodes);
        }
    }

    document.addEventListener('DOMContentLoaded', scan);
    document.addEventListener('input', function () {
        window.setTimeout(scan, 250);
    }, true);

    if (window.MutationObserver) {
        new MutationObserver(function () {
            window.setTimeout(scan, 100);
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
}());
