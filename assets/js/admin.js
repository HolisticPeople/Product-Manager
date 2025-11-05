(function () {
    'use strict';

    if (typeof window.HPProductsManager === 'undefined') {
        return;
    }

    var settings = window.HPProductsManager;
    var BUTTON_CLASS = 'hp-products-button';
    var TARGET_SELECTOR = '#eao-create-new-order, .eao-create-new-order-button';
    var FALLBACK_CONTAINER = '.wrap .page-title-actions';
    var LABEL_TARGET = 'create new order';

    function buttonExists() {
        return document.querySelector('.' + BUTTON_CLASS) !== null;
    }

    function buildButton() {
        var button = document.createElement('a');
        button.className = 'page-title-action ' + BUTTON_CLASS;
        button.textContent = settings.buttonLabel || 'Products';
        button.href = settings.productsUrl || '#';
        button.setAttribute('role', 'button');
        return button;
    }

    function injectButton() {
        if (buttonExists()) {
            return true;
        }

        var referenceNode = document.querySelector(TARGET_SELECTOR);
        if (referenceNode && referenceNode.parentElement) {
            referenceNode.insertAdjacentElement('afterend', buildButton());
            return true;
        }

        var labelMatch = Array.prototype.find.call(
            document.querySelectorAll('a, button'),
            function (element) {
                return element.textContent &&
                    element.textContent.trim().toLowerCase() === LABEL_TARGET;
            }
        );

        if (labelMatch && labelMatch.parentElement) {
            labelMatch.insertAdjacentElement('afterend', buildButton());
            return true;
        }

        var fallback = document.querySelector(FALLBACK_CONTAINER);
        if (fallback) {
            fallback.appendChild(buildButton());
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
