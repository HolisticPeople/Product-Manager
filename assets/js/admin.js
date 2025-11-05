(function () {
    'use strict';

    if (typeof window.HPProductsManager === 'undefined') {
        return;
    }

    var settings = window.HPProductsManager;
    var BUTTON_CLASS = 'hp-products-button';
    var TARGET_SELECTOR = '#eao-create-new-order, .eao-create-new-order-button';

    /**
     * Inject the Products button after the Enhanced Admin Order create button.
     *
     * @returns {boolean} True when the button has been inserted.
     */
    function injectButton() {
        var existing = document.querySelector('.' + BUTTON_CLASS);
        if (existing) {
            return true;
        }

        var anchor = document.querySelector(TARGET_SELECTOR);
        if (!anchor) {
            return false;
        }

        var button = document.createElement('a');
        button.className = 'page-title-action ' + BUTTON_CLASS;
        button.textContent = settings.buttonLabel || 'Products';
        button.href = settings.productsUrl || '#';
        button.setAttribute('role', 'button');

        anchor.insertAdjacentElement('afterend', button);
        return true;
    }

    function startObserver() {
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
        document.addEventListener('DOMContentLoaded', startObserver);
    } else {
        startObserver();
    }
})();
