(function () {
    'use strict';

    function removeDefaultViewLinks() {
        var selectors = [
            '#wp-admin-bar-view',
            '#wp-admin-bar-view-site',
            '#wp-admin-bar-view-product',
            '#wp-admin-bar-view-post',
            '#wp-admin-bar-view-page'
        ];

        selectors.forEach(function (selector) {
            var node = document.querySelector(selector);
            if (node && node.parentNode) {
                node.parentNode.removeChild(node);
            }
        });
    }

    function repositionProductsButton() {
        var eaoNode = document.querySelector('#wp-admin-bar-eao-create-new-order');
        var productsNode = document.querySelector('#wp-admin-bar-hp-products-manager');

        if (!eaoNode || !productsNode) {
            return;
        }

        var parent = eaoNode.parentNode;
        if (!parent) {
            return;
        }

        parent.insertBefore(productsNode, eaoNode.nextSibling);
    }

    function init() {
        removeDefaultViewLinks();
        repositionProductsButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
