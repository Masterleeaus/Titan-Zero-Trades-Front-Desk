/*
 * Quote print view: the "Save as PDF / Print" button opens the browser print dialog.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var button = e.target.closest ? e.target.closest('[data-tzfd-print]') : null;
        if (button) {
            window.print();
        }
    });
}());
