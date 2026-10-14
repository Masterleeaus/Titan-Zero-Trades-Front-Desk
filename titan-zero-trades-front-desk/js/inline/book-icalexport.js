/*
 * Titan Zero Trades: Front Desk, iCal export settings (admin screens).
 * Shows or hides the organizer fields and fills in the recommended time-zone offset.
 */
(function () {
    'use strict';

    function toggleOrganizer() {
        var select = document.getElementById('ical_organizer');
        var box = document.getElementById('tzfd_ical_organizerdata');
        if (!select || !box) {
            return;
        }
        box.style.display = (select.options.selectedIndex === 0 ? 'none' : '');
    }

    function showRecommendedOffset() {
        var target = document.getElementById('tzfdrectime');
        if (!target) {
            return;
        }
        var diff = -1 * parseInt(new Date().getTimezoneOffset() / 60, 10);
        if (diff > 0) {
            diff = '+' + diff;
        }
        target.textContent = String(diff);
    }

    function init() {
        showRecommendedOffset();
        toggleOrganizer();
        var select = document.getElementById('ical_organizer');
        if (select) {
            select.addEventListener('change', toggleOrganizer);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
