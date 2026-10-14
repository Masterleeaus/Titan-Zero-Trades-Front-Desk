/*
 * Public quote page: approve with a name and the terms, or decline with an optional reason.
 * Settings come from wp_localize_script() as window.tzfdQuoteApprove: token, approveUrl, declineUrl, i18n.
 */
(function ($) {
    'use strict';

    var cfg = window.tzfdQuoteApprove || {};
    var t = cfg.i18n || {};
    var STYLE_OK = 'background:#d1fae5;padding:12px;border-radius:6px;margin-top:12px;';
    var STYLE_ERROR = 'background:#fee2e2;padding:12px;border-radius:6px;margin-top:12px;';
    var STYLE_DECLINED = 'background:#fee2e2;padding:14px;border-radius:6px;';

    /* Builds a message box. Text is set with .text() so server messages are never read as HTML. */
    function message(style, strongText, text) {
        var $box = $('<div>').attr('style', style);
        if (strongText) {
            $box.append($('<strong>').text(strongText));
        }
        if (text) {
            $box.append(document.createTextNode((strongText ? ' ' : '') + text));
        }
        return $box;
    }

    $(function () {
        var $form = $('#tzfd-quote-approve-form');
        var $result = $('#tzfd-quote-result');
        var $actions = $('#tzfd-quote-actions');

        $form.on('submit', function (e) {
            e.preventDefault();
            $result.empty().append($('<em>').text(t.processing));

            $.post(cfg.approveUrl, {
                token: cfg.token,
                name: $form.find('input[name=signer_name]').val(),
                email: $form.find('input[name=signer_email]').val(),
                terms: $form.find('input[name=terms]').is(':checked') ? 1 : 0
            }, function (res) {
                if (res && res.ok) {
                    $result.empty().append(message(STYLE_OK, t.approvedTitle, t.approvedBody));
                    $form.find('input, button').prop('disabled', true);
                } else {
                    $result.empty().append(message(STYLE_ERROR, '', (res && res.message) || t.error));
                }
            }).fail(function (jqXHR) {
                // The server sends { ok: false, message } with an error status; show that text when it is there.
                var json = jqXHR && jqXHR.responseJSON;
                var text;
                if (json) {
                    text = (typeof json.message === 'string' && json.message) ? json.message : t.error;
                } else {
                    text = t.networkError;
                }
                $result.empty().append(message(STYLE_ERROR, '', text));
            });
        });

        $('#tzfd-quote-decline').on('click', function () {
            var reason = window.prompt(t.declinePrompt);
            if (reason === null) { return; }
            $.post(cfg.declineUrl, {
                token: cfg.token,
                reason: reason || ''
            }, function () {
                $actions.empty().append(message(STYLE_DECLINED, '', t.declined));
            });
        });
    });
}(jQuery));
