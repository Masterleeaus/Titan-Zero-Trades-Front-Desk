/*
 * AI setup wizard (Front Desk > AI > AI setup). Loaded by TZFD_Setup_Wizard::enqueue_wizard_assets().
 * Handles: detect a local inference server, test the configured provider, show or hide the
 * custom API base URL. Server and model text is written with .text(), never as HTML.
 */
(function ($) {
    'use strict';

    var cfg = window.tzfdCoreInstaller || {};
    var ajaxUrl = cfg.ajaxUrl || window.ajaxurl;

    /** Replaces the result box with a notice. pieces: strings (plain text) or { b: 'bold text' }. */
    function renderNotice($box, cls, pieces) {
        var $p = $('<p>');
        $.each(pieces, function (i, piece) {
            if (typeof piece === 'string') {
                $p.append(document.createTextNode(piece));
            } else {
                $p.append($('<strong>').text(piece.b));
            }
        });
        $box.empty().append($('<div>').addClass('notice ' + cls + ' inline').append($p));
    }

    function detectLocal() {
        var $btn = $(this).prop('disabled', true).text('Detecting…');
        var $box = $btn.closest('form').find('.tzfd-test-result');

        $.post(ajaxUrl, {
            action: 'tzfd_detect_local',
            _wpnonce: cfg.detectNonce,
            base: $('#tzfd_local_base').val(),
            kind: $('#tzfd_local_kind').val()
        }, function (res) {
            var data = (res && res.data) || {};
            $btn.prop('disabled', false).text('Detect server');

            if (res && res.success && data.models) {
                var $sel = $('#tzfd_local_model').empty();
                $sel.append('<option value="">— select a model —</option>');
                data.models.forEach(function (m) {
                    var opt = document.createElement('option');
                    opt.value = m;
                    opt.textContent = m;
                    if (m === data.recommended) { opt.selected = true; }
                    $sel[0].appendChild(opt);
                });
                var n = data.models.length;
                renderNotice($box, 'notice-success', [
                    'Found ' + n + ' model' + (n === 1 ? '' : 's') + '. Recommended: ',
                    { b: String(data.recommended) }
                ]);
            } else {
                renderNotice($box, 'notice-error', [ data.error || 'Could not reach the server.' ]);
            }
        });
    }

    function testProvider() {
        var $btn = $(this).prop('disabled', true).text('Testing…');
        var $box = $btn.closest('form').find('.tzfd-test-result');

        $.post(ajaxUrl, {
            action: 'tzfd_test_provider',
            _wpnonce: cfg.testNonce
        }, function (res) {
            var data = (res && res.data) || {};
            $btn.prop('disabled', false).text('Test only');
            renderNotice($box, res && res.success ? 'notice-success' : 'notice-error', [ data.message || '' ]);
        });
    }

    function toggleCustomBase() {
        $('.tzfd-custom-base').toggle($('#tzfd_provider_pick').val() === 'custom');
    }

    $(function () {
        $('.tzfd-detect-local').on('click', detectLocal);
        $('.tzfd-test-provider').on('click', testProvider);
        $('#tzfd_provider_pick').on('change', toggleCustomBase);
        toggleCustomBase();
    });
})(jQuery);
