/*
 * AI setup wizard (Front Desk > AI > AI setup). Loaded by TZFD_Setup_Wizard::enqueue_wizard_assets().
 * Handles: test the configured provider, show or hide the custom API base URL.
 * Server and model text is written with .text(), never as HTML.
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

    function testProvider() {
        var $btn = $(this).prop('disabled', true).text('Testing…');
        var $box = $btn.closest('form').find('.tzfd-test-result');

        $.post(ajaxUrl, {
            action: 'tz_fd_test_provider',
            _wpnonce: cfg.testNonce
        }, function (res) {
            var data = (res && res.data) || {};
            $btn.prop('disabled', false).text('Test only');
            renderNotice($box, res && res.success ? 'notice-success' : 'notice-error', [ data.message || '' ]);
        }).fail(function () {
            $btn.prop('disabled', false).text('Test only');
            renderNotice($box, 'notice-error', [ 'The test request failed. Please try again.' ]);
        });
    }

    function toggleCustomBase() {
        $('.tzfd-custom-base').toggle($('#tz_fd_provider_pick').val() === 'custom');
    }

    $(function () {
        $('.tzfd-test-provider').on('click', testProvider);
        $('#tz_fd_provider_pick').on('change', toggleCustomBase);
        toggleCustomBase();
    });
})(jQuery);
