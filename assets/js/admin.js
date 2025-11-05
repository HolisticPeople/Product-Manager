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
            productNode.classList.remove('hp-products-hidden');
            return;
        }

        var createNode = createAnchor.closest('li');
        var parent = createNode && createNode.parentNode;
        if (!parent) {
            return;
        }

        parent.insertBefore(productNode, createNode.nextSibling);
        productNode.classList.remove('hp-products-hidden');
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

    document.addEventListener('wp-admin-bar-added', init);
})();
