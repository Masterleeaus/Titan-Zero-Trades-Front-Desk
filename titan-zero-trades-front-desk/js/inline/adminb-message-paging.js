/*
 * Front Desk, Bookings list: pressing Enter in the page box opens that page.
 * The address to build on is in the box's data-base-url attribute.
 */
(function () {
    'use strict';

    var box = document.getElementById('current-page-selector');
    if (!box) { return; }

    box.addEventListener('keydown', function (event) {
        if (event.keyCode !== 13) { return; }
        event.preventDefault();
        window.location.href = this.getAttribute('data-base-url') + '&p=' + encodeURIComponent(this.value);
    });
})();
