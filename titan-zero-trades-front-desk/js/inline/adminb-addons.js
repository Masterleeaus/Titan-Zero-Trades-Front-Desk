/*
 * Front Desk, Add-ons screen (tzfd-addons.inc.php).
 * Settings come from window.tzfdAdminBAddons (wp_localize_script). The save button is bound here.
 */
(function () {
    'use strict';

    var cfg = window.tzfdAdminBAddons || {};

    /** Saves the ticked add-ons: the page reloads with the switches in the query string. */
    function saveAddons() {
        var switches = document.getElementsByName('tzfd_addons');
        var active = [];
        for (var i = 0, h = switches.length; i < h; i++) {
            if (switches[i].checked && !switches[i].disabled) {
                active.push('tzfd_addons_active_list[]=' + encodeURIComponent(switches[i].value));
            }
        }
        document.location = 'admin.php?page=' + cfg.page + '&anonce=' + encodeURIComponent(cfg.nonce) + '&b=1&r=' + Math.random() + (active.length ? '&' + active.join('&') : '') + '#addons-section';
    }

    var buttons = document.querySelectorAll('[data-tzfd-action="save-addons"]');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', saveAddons);
    }

    // After a save, the page reloads once so the menus pick up the new list.
    if (cfg.redirect) {
        document.location.replace(cfg.redirect);
    }
})();
