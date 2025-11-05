(function () {
    'use strict';

    var ADMIN_BAR = '#wpadminbar';
    var TEXT_CREATE = 'create new order';
    var VIEW_MATCHES = ['view products', 'view product', 'view', 'view site'];

    function normalize(text) {
        return (text || '').trim().toLowerCase();
    }

    function removeViewLinks() {
        var items = document.querySelectorAll(ADMIN_BAR + ' li');
        items.forEach(function (item) {
            var anchor = item.querySelector('a');
            if (!anchor) {
                return;
            }
            var text = normalize(anchor.textContent);
            if (VIEW_MATCHES.indexOf(text) !== -1) {
                item.remove();
            }
        });
    }

    function findCreateButton() {
        var anchors = document.querySelectorAll(ADMIN_BAR + ' li > a');
        for (var i = 0; i < anchors.length; i++) {
            if (normalize(anchors[i].textContent) === TEXT_CREATE) {
                return anchors[i];
            }
        }
        return null;
    }

    function repositionProductsButton() {
        var productNode = document.querySelector('#wp-admin-bar-hp-products-manager');
        if (!productNode) {
            return;
        }

        var createAnchor = findCreateButton();
        if (!createAnchor) {
            return;
        }

        var createNode = createAnchor.closest('li');
        var parent = createNode && createNode.parentNode;
        if (!parent) {
            return;
        }

        // Mirror structural classes for consistent spacing
        var productAnchor = productNode.querySelector('a');
        productNode.className = createNode.className;
        if (productAnchor && createAnchor) {
            productAnchor.className = createAnchor.className;
        }

        parent.insertBefore(productNode, createNode.nextSibling);
    }

    function init() {
        removeViewLinks();
        repositionProductsButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-run after Ajax navigation (WP 5.8 toolbar menus use partial reloads)
    document.addEventListener('wp-admin-bar-added', init);
})();
