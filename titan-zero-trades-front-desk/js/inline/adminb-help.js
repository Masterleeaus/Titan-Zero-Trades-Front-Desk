/*
 * Front Desk, Help and FAQ (tzfd-help.inc.php). Filters the help items as the visitor types.
 */
(function () {
    'use strict';

    function filterHelp() {
        var q = document.getElementById('tzfd-help-search-input').value.trim().toLowerCase();
        var shown = 0;
        document.querySelectorAll('#tzfd-help-content .tzfd-help-item').forEach(function (el) {
            var match = q === '' || el.textContent.toLowerCase().indexOf(q) !== -1;
            el.classList.toggle('tzfd-help-hidden', !match);
            if (match) { shown++; }
            if (q !== '' && match && el.tagName === 'DETAILS') { el.open = true; }
        });
        document.querySelectorAll('#tzfd-help-content .tzfd-help-section').forEach(function (sec) {
            var visible = sec.querySelectorAll('.tzfd-help-item:not(.tzfd-help-hidden)').length > 0;
            sec.classList.toggle('tzfd-help-hidden', !visible);
            var heading = sec.previousElementSibling;
            if (heading && heading.tagName === 'H2') { heading.classList.toggle('tzfd-help-hidden', !visible); }
        });
        var empty = document.getElementById('tzfd-help-empty');
        if (empty) { empty.classList.toggle('tzfd-help-hidden', q === '' || shown > 0); }
    }

    var input = document.getElementById('tzfd-help-search-input');
    if (input) { input.addEventListener('input', filterHelp); }
})();
