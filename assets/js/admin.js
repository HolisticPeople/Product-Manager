(function () {
    'use strict';

    var ADMIN_BAR = document.getElementById('wpadminbar');
    var PRODUCT_NODE_ID = 'wp-admin-bar-hp-products-manager';

    if (!ADMIN_BAR) {
        return;
    }

    function normalize(text) {
        return (text || '').trim().toLowerCase();
    }

    function removeViewLinks() {
        var viewTexts = ['view products', 'view product', 'view', 'view site'];
        ADMIN_BAR.querySelectorAll('li').forEach(function (item) {
            var anchor = item.querySelector('a');
            if (!anchor) {
                return;
            }
            if (viewTexts.indexOf(normalize(anchor.textContent)) !== -1) {
                item.remove();
            }
        });
    }

    function placeProductsButton() {
        var productNode = document.getElementById(PRODUCT_NODE_ID);
        if (!productNode) {
            return;
        }

        var anchors = ADMIN_BAR.querySelectorAll('li > a');
        var createAnchor = null;

        anchors.forEach(function (anchor) {
            if (normalize(anchor.textContent) === 'create new order') {
                createAnchor = anchor;
            }
        });

        if (createAnchor) {
            var targetLi = createAnchor.closest('li');
            if (targetLi && targetLi.parentNode) {
                targetLi.parentNode.insertBefore(productNode, targetLi.nextSibling);
            }
        }

        productNode.classList.remove('hp-products-hidden');
    }

    function init() {
        removeViewLinks();
        placeProductsButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('wp-admin-bar-added', init);
})();
