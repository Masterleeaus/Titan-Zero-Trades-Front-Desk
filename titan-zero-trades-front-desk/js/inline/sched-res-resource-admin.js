/**
 * Titan Zero Trades: Front Desk, Workers and locations screens.
 * Asks for confirmation before a remove button submits. Replaces the inline onsubmit attribute.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.getAttribute || !form.hasAttribute('data-tzfd-confirm')) {
            return;
        }
        if (!window.confirm(form.getAttribute('data-tzfd-confirm'))) {
            event.preventDefault();
        }
    });
})();
