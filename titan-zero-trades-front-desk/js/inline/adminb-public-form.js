/*
 * Titan Zero Trades: Front Desk, booking form (tzfd-public-int.inc.php), front end and admin preview.
 * The form carries its validation function name in data-tzfd-validate. Forms that a security
 * plugin (Anti-Spam by CleanTalk) can overwrite are re-wired every two seconds (data-tzfd-apbct).
 */
(function ($) {
    'use strict';

    /** Submit runs the form's validation. Its name is looked up at submit time, as the inline handler did. */
    function wireForm(form) {
        var validate = form.getAttribute('data-tzfd-validate');
        if (!validate) { return; }
        form.onsubmit = function () {
            var check = window[validate];
            return typeof check === 'function' ? check(this) : true;
        };
    }

    /** Admin options: ignore the required-field check for this booking. */
    function ignoreValidation() {
        $('.required').removeClass('required');
        $('#wptsremoveval').hide();
        $('#wptsremovedval').show();
    }

    var forms = document.querySelectorAll('form[data-tzfd-validate]');
    for (var i = 0; i < forms.length; i++) {
        wireForm(forms[i]);
        if (forms[i].hasAttribute('data-tzfd-apbct')) {
            (function (form) {
                window.setInterval(function () { wireForm(form); }, 2000);
            })(forms[i]);
        }
    }

    $(document).on('click', '[data-tzfd-action="ignore-validation"]', ignoreValidation);
})(jQuery);
