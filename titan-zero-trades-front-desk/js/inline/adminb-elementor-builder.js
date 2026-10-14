/*
 * Titan Zero Trades: Front Desk, Elementor edit mode (controllers/elementor/elementorwidget_class.inc.php).
 * Each widget preview carries its counter in data-tzfd-builder. The form builder is loaded into the
 * preview once the form script is ready; the attempt is repeated every 100 ms until it works.
 */
(function ($) {
    'use strict';

    function loadBuilder(counter) {
        var id = String(counter);
        var objString = '{"pub":true,"identifier":"_' + id + '","messages": {}}';

        try {
            var f = $('#tzfdform_' + id).tzfdform(JSON.parse(objString));
            f.fBuild.loadData('form_structure_' + id);
        } catch (e) {
            setTimeout(function () { loadBuilder(counter); }, 100);
        }
    }

    var nodes = document.querySelectorAll('[data-tzfd-builder]');
    for (var i = 0; i < nodes.length; i++) {
        loadBuilder(nodes[i].getAttribute('data-tzfd-builder'));
    }
})(jQuery);
