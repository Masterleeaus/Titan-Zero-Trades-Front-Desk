/*
 * Quote screens (admin): asks for confirmation before any form or button marked with data-confirm is sent.
 * The question is in the data-confirm attribute, escaped by PHP.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.hasAttribute && form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });

    document.addEventListener('click', function (e) {
        var button = e.target.closest ? e.target.closest('button[data-confirm]') : null;
        if (button && !window.confirm(button.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });
}());
