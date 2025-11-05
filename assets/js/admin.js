(function () {
    'use strict';

    if (typeof window.HPProductsManager === 'undefined') {
        return;
    }

    var settings = window.HPProductsManager;
    var BUTTON_CLASS = 'hp-products-button';
    var TARGET_SELECTOR = '#eao-create-new-order, .eao-create-new-order-button, #wp-admin-bar-eao-create-new-order > a';
    var FALLBACK_CONTAINER = '.wrap .page-title-actions';
    var LABEL_TARGET = 'create new order';
    var ADMIN_BAR_SELECTOR = '#wpadminbar';
    var ADMIN_BAR_VIEW_NODE = '#wp-admin-bar-view';

    function buttonExists() {
        return document.querySelector('.' + BUTTON_CLASS) !== null ||
            document.querySelector('#wp-admin-bar-hp-products-manager') !== null;
    }

    function buildButtonAnchor() {
        var button = document.createElement('a');
        button.className = 'page-title-action ' + BUTTON_CLASS;
        button.textContent = settings.buttonLabel || 'Products';
        button.href = settings.productsUrl || '#';
        button.setAttribute('role', 'button');
        return button;
    }

    function buildToolbarNode() {
        var li = document.createElement('li');
        li.id = 'wp-admin-bar-hp-products-manager';
        li.className = 'menupop hp-products-toolbar-node';

        var anchor = document.createElement('a');
        anchor.className = 'ab-item hp-products-button';
        anchor.textContent = settings.buttonLabel || 'Products';
        anchor.href = settings.productsUrl || '#';
        anchor.setAttribute('role', 'menuitem');

        li.appendChild(anchor);
        return li;
    }

    function removeViewProduct() {
        var explicit = document.querySelector(ADMIN_BAR_VIEW_NODE);
        if (explicit && explicit.parentNode) {
            explicit.parentNode.removeChild(explicit);
            return;
        }

        var candidates = document.querySelectorAll(ADMIN_BAR_SELECTOR + ' li');
        Array.prototype.forEach.call(candidates, function(node) {
            if (!node.textContent) {
                return;
            }
            if (node.textContent.trim().toLowerCase() === 'view product') {
                node.parentNode.removeChild(node);
            }
        });
    }

    function insertAfter(referenceNode) {
        if (!referenceNode) {
            return false;
        }

        var inToolbar = referenceNode.closest && referenceNode.closest(ADMIN_BAR_SELECTOR);
        if (inToolbar) {
            var referenceListItem = referenceNode.closest('li');
            if (!referenceListItem || !referenceListItem.parentNode) {
                return false;
            }

            referenceListItem.parentNode.insertBefore(buildToolbarNode(), referenceListItem.nextSibling);
            return true;
        }

        referenceNode.insertAdjacentElement('afterend', buildButtonAnchor());
        return true;
    }

    function injectButton() {
        if (buttonExists()) {
            return true;
        }

        removeViewProduct();

        var referenceNode = document.querySelector(TARGET_SELECTOR);
        if (referenceNode) {
            if (insertAfter(referenceNode)) {
                return true;
            }
        }

        var labelMatch = Array.prototype.find.call(
            document.querySelectorAll('a, button'),
            function (element) {
                return element.textContent &&
                    element.textContent.trim().toLowerCase() === LABEL_TARGET;
            }
        );

        if (labelMatch) {
            if (insertAfter(labelMatch)) {
                return true;
            }
        }

        var toolbarTarget = document.querySelector(ADMIN_BAR_SELECTOR + ' li');
        if (toolbarTarget) {
            toolbarTarget.parentNode.appendChild(buildToolbarNode());
            return true;
        }

        var fallback = document.querySelector(FALLBACK_CONTAINER);
        if (fallback) {
            fallback.appendChild(buildButtonAnchor());
            return true;
        }

        return false;
    }

    function initInsertion() {
        if (injectButton()) {
            return;
        }

        var observer = new MutationObserver(function () {
            if (injectButton()) {
                observer.disconnect();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInsertion);
    } else {
        initInsertion();
    }
})();
