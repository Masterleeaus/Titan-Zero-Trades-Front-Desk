/* Vertical packs: confirm destructive POST form submissions. */
(function () {
    'use strict';
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches || !form.matches('form[data-tzfd-confirm]')) { return; }
        if (!window.confirm(form.getAttribute('data-tzfd-confirm'))) {
            event.preventDefault();
        }
    });
})();
