/*
 * Titan Zero Trades: Front Desk, single-day selection settings (admin screens).
 * Shows the display options only when the alternative interface is set to Yes.
 */
(function () {
    'use strict';

    function toggleOptions() {
        var select = document.getElementById('sds_autosel_enable');
        var box = document.getElementById('sds_autoselhide_yes');
        if (!select || !box) {
            return;
        }
        box.style.display = (select.selectedIndex === 1 ? '' : 'none');
    }

    function init() {
        var select = document.getElementById('sds_autosel_enable');
        if (select) {
            select.addEventListener('change', toggleOptions);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
