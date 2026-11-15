/*
 * Front Desk, Bookings list (tzfd-admin-int-message-list.inc.php).
 * Settings and translated strings come from window.tzfdAdminBList (wp_localize_script).
 * Actions are bound through data-tzfd-action attributes, not inline event handlers.
 */
(function ($) {
    'use strict';

    var cfg = window.tzfdAdminBList || {};
    var str = cfg.strings || {};

    /** Read-only route plus a POST body for all booking mutations. */
    function listUrl() {
        return 'admin.php?page=' + encodeURIComponent(cfg.page) + '&cal=' + encodeURIComponent(cfg.cal) + '&list=1';
    }
    function mutation(fields) {
        fields.anonce = cfg.nonce;
        return $.post(listUrl(), fields);
    }
    function postForm(fields) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = listUrl();
        Object.keys(fields).forEach(function (key) {
            var control = document.createElement('input');
            control.type = 'hidden';
            control.name = key;
            control.value = String(fields[key]);
            form.appendChild(control);
        });
        document.body.appendChild(form);
        form.submit();
    }

    /** Saves a new status for one booking from its status dropdown. */
    function updateStatus(id) {
        var selectBox = document.getElementById('sb' + id);
        if (!selectBox) { return; }
        var status = selectBox.options[selectBox.selectedIndex].value;

        selectBox.disabled = true;
        mutation({ ud: id, status: status }).done(function () {
            selectBox.disabled = false;
            var $tr = $(selectBox).closest('tr');
            var lowerStatus = status.toLowerCase();

            if (lowerStatus === 'cancelled' || lowerStatus === 'cancelled by customer') {
                $tr.addClass('tz_fd_cancelled');
            } else {
                $tr.removeClass('tz_fd_cancelled');
            }

            var $check = $('<span style="color:green; margin-left:5px; font-weight:bold;">&#10003;</span>');
            $(selectBox).after($check);
            $check.fadeOut(2000, function () { $(this).remove(); });
        }).fail(function () { selectBox.disabled = false; });
    }

    /** Sends the confirmation emails for one booking again. */
    function resend(id, el) {
        if (!window.confirm(str.confirmResend)) { return; }
        var $link = $(el);
        var originalText = $link.text();

        $link.text(str.sending).css({ 'pointer-events': 'none', 'opacity': '0.5' });
        mutation({ resend: id }).done(function () {
            $link.text(str.sent).css('color', 'green');
            setTimeout(function () {
                $link.text(originalText).css({ 'pointer-events': 'auto', 'opacity': '1', 'color': '' });
            }, 2000);
        }).fail(function () {
            $link.text(originalText).css({ 'pointer-events': 'auto', 'opacity': '1', 'color': '' });
        });
    }

    /** Deletes one booking and removes its row. */
    function deleteRow(id, el) {
        if (!window.confirm(str.confirmDelete)) { return; }
        var $tr = $(el).closest('tr');

        $tr.css('opacity', '0.5');
        mutation({ ld: id }).done(function () {
            $tr.fadeOut(400, function () { $(this).remove(); });
        }).fail(function () { $tr.css('opacity', '1'); });
    }

    /** Ticks or clears every row checkbox. */
    function markAll(checked) {
        $('#dex_table_form input[type="checkbox"]').filter(function () {
            return /^c\d+$/.test(this.name);
        }).prop('checked', checked);
    }

    function applyMarkedStatus() {
        if (!window.confirm('Are you sure that you want to change the status of the marked items?')) { return; }
        var form = document.dex_table_form;
        var box = document.getElementById('statusbox_markeditems');
        form.delmark.value = '';
        form.statusmark.value = '1';
        form.sbmi.value = box.options[box.selectedIndex].value;
        form.submit();
    }

    function deleteMarked() {
        if (window.confirm('Are you sure that you want to delete the marked items?')) {
            document.dex_table_form.submit();
        }
    }

    function deleteAll() {
        if (!window.confirm('Are you sure that you want to delete ALL bookings for this form?')) { return; }
        if (!window.confirm('Please note that this action cannot be undone. ALL THE BOOKINGS of this form will be DELETED. Are you sure that you want to delete ALL bookings for this form?')) { return; }
        postForm({ del: 'all', anonce: cfg.nonce });
    }

    function printList() {
        var w = window.open();
        // DOM copy instead of document.write(innerHTML): the page's nodes are cloned, nothing is re-parsed as HTML.
        var printStyle = w.document.createElement('style');
        printStyle.textContent = ".tzfdnoprint, .check-column, .tzfdnopr{display:none;} table{border-collapse:collapse;width:100%;font-family:sans-serif;} th, td{border:1px solid #ddd;padding:8px;text-align:left;} th{background-color:#f4f4f4;}";
        w.document.head.appendChild(printStyle);
        Array.prototype.forEach.call(document.getElementById('dex_printable_contents').childNodes, function (node) {
            w.document.body.appendChild(node.cloneNode(true));
        });
        w.print();
        w.close();
    }

    // Status dropdowns on each row (ids sb<booking id>). The bulk dropdown (statusbox_markeditems) is not matched.
    $(document).on('change', 'select[id^="sb"]', function () {
        var match = /^sb(\d+)$/.exec(this.id);
        if (match) { updateStatus(match[1]); }
    });

    // Buttons, links and the select-all checkbox, each marked with data-tzfd-action.
    $(document).on('click', '[data-tzfd-action]', function (event) {
        var action = this.getAttribute('data-tzfd-action');
        var id = parseInt(this.getAttribute('data-id'), 10);

        if (this.tagName === 'A') { event.preventDefault(); }

        switch (action) {
            case 'resend':        resend(id, this); break;
            case 'delete-row':    deleteRow(id, this); break;
            case 'mark-all':      markAll(this.checked); break;
            case 'apply-status':  applyMarkedStatus(); break;
            case 'delete-marked': deleteMarked(); break;
            case 'delete-all':    deleteAll(); break;
            case 'print':         printList(); break;
            default: break;
        }
    });
})(jQuery);
