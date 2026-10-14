/*
 * Front Desk, Publish a booking form (tzfd-publish-wizzard.inc.php).
 * Buttons carry their address in data-tzfd-open; the selects are bound here instead of inline handlers.
 */
(function () {
    'use strict';

    function reloadCalendar(item) {
        document.location = '?page=tzfd&pwizard=1&cal=' + item.options[item.options.selectedIndex].value;
    }

    function showPublishOption(sel) {
        document.getElementById('ppage').style.display = 'none';
        document.getElementById('posttitle').style.display = 'none';
        document.getElementById('subbtnnow').style.display = '';
        if (sel.selectedIndex === 2 || sel.selectedIndex === 3) {
            document.getElementById('ppage').style.display = '';
            document.getElementById('subbtnnow').style.display = 'none';
        } else if (sel.selectedIndex === 1 || sel.selectedIndex === 0) {
            document.getElementById('posttitle').style.display = '';
        }
    }

    function bind() {
        var calendar = document.getElementById('tzfdhour_id');
        if (calendar) {
            calendar.addEventListener('change', function () { reloadCalendar(this); });
        }

        var where = document.querySelector('select[name="whereto"]');
        if (where) {
            where.addEventListener('change', function () { showPublishOption(this); });
        }

        var openers = document.querySelectorAll('[data-tzfd-open]');
        for (var i = 0; i < openers.length; i++) {
            openers[i].addEventListener('click', function () {
                window.open(this.getAttribute('data-tzfd-open'));
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
