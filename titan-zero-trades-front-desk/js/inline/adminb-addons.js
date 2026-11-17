/* Front Desk add-on changes: submit by POST; never change settings through URLs. */
(function () {
    'use strict';
    function saveAddons() {
        var form = document.getElementById('tzfd-addons-save-form');
        if (!form) { return; }
        var existing = form.querySelectorAll('input[name="tz_fd_addons_active_list[]"]');
        for (var j = 0; j < existing.length; j++) { existing[j].remove(); }
        var switches = document.getElementsByName('tz_fd_addons');
        for (var i = 0; i < switches.length; i++) {
            if (switches[i].checked && !switches[i].disabled) {
                var field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'tz_fd_addons_active_list[]';
                field.value = switches[i].value;
                form.appendChild(field);
            }
        }
        form.submit();
    }
    var buttons = document.querySelectorAll('[data-tzfd-action="save-addons"]');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', saveAddons);
    }

    // Settings and Details links point at an add-on panel: open that panel, then bring it into view.
    function openPanelFromHash() {
        var id = window.location.hash ? window.location.hash.slice(1) : '';
        if (!id || id.indexOf('tzfdad-addon-') !== 0) { return; }
        var panel = document.getElementById(id);
        if (panel && panel.tagName === 'DETAILS') {
            panel.open = true;
            panel.scrollIntoView({ block: 'start' });
        }
    }
    window.addEventListener('hashchange', openPanelFromHash);
    openPanelFromHash();
})();
