/*
 * Vertical Packs page. Loaded by TZFD_Vertical_Packs::enqueue_packs_assets().
 * Asks for confirmation before a pack is removed. The question is read from data-tzfd-confirm.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) { return; }
        var link = target.closest('a[data-tzfd-confirm]');
        if (link && !window.confirm(link.getAttribute('data-tzfd-confirm'))) {
            event.preventDefault();
        }
    });
})();
