(function ($) {
    if (!$) {
        return;
    }
    $.tzfdform = $.tzfdform || {};
    $.tzfdform.markValidationMessage = function (message) {
        if (!message || typeof message.attr !== "function") {
            return message;
        }
        message.attr({
            role: "status",
            "aria-live": "polite",
            "aria-atomic": "true"
        });
        return message;
    };
})(typeof tzfdformjQuery !== "undefined"
    ? tzfdformjQuery
    : (typeof jQuery !== "undefined" ? jQuery : null));

/* Preserve donor selectors while supplying keyboard semantics to its generated controls. */
(function ($) {
    if (!$) return;
    $.tzfdform = $.tzfdform || {};
    $.tzfdform.focusPublicError = function (validator) {
        var first = validator.errorList && validator.errorList[0];
        if (!first) return;
        var field = $(first.element), target = field;
        if (field.is('[type=hidden]')) target = field.closest('.dfield').find('select:visible, button:visible, [tabindex="0"]:visible').first();
        if (target.length) target.trigger('focus');
    };
    $.tzfdform.enhanceBookingField = function (root, name) {
        if (!root || root.tzfdFrontDeskKeyboard) return;
        root.tzfdFrontDeskKeyboard = true;
        var last = null;
        function key(node) { return node && node.getAttribute('data-booking-focus'); }
        function refresh() {
            var group = $(root), calendar = group.find('.fieldCalendar');
            calendar.attr({'role':'group', 'aria-labelledby':name+'_label'});
            calendar.find('[data-handler="selectDay"] a').each(function () {
                var cell = $(this).closest('td'), day = String($(this).text()).padStart(2,'0');
                var date = cell.attr('data-year')+'-'+String(Number(cell.attr('data-month'))+1).padStart(2,'0')+'-'+day;
                $(this).attr({'role':'button','aria-label':date,'data-booking-focus':'day:'+date,
                    'aria-pressed':$(this).hasClass('ui-state-active')?'true':'false'});
            });
            calendar.find('.ui-datepicker-prev, .ui-datepicker-next').each(function () {
                var disabled = $(this).hasClass('ui-state-disabled');
                $(this).attr({'role':'button','tabindex':disabled?'-1':'0','aria-disabled':String(disabled),
                    'aria-label':$(this).attr('title') || $(this).text(),
                    'data-booking-focus':$(this).hasClass('ui-datepicker-prev')?'previous-month':'next-month'});
            });
            calendar.find('select.ui-datepicker-month').attr({'aria-label':'Month','data-booking-focus':'month-select'});
            calendar.find('select.ui-datepicker-year').attr({'aria-label':'Year','data-booking-focus':'year-select'});
            calendar.find('td.ui-state-disabled').attr('aria-disabled','true');
            group.find('.slots a').each(function () {
                var slot = $(this), parent = slot.parent(), selected = parent.hasClass('currentSelection');
                var disabled = parent.hasClass('htmlUsed') || (selected && !root.tzfdFrontDeskAllowRepeat);
                var date = slot.attr('d') || slot.closest('.slots').attr('d');
                var start = (slot.attr('h1') || parent.attr('h1'))+':'+(slot.attr('m1') || parent.attr('m1'));
                var service = slot.attr('s') || parent.attr('s');
                slot.attr({'role':'button','tabindex':'0','aria-disabled':String(disabled),'aria-pressed':String(selected),
                    'aria-label':date+' '+slot.text().trim()+(selected?' (selected)':disabled?' (unavailable)':''),
                    'data-booking-focus':'slot:'+date+':'+service+':'+start});
            });
            group.find('.usedSlots a.cancel').each(function () {
                var row = $(this).closest('.tzfd_list');
                $(this).attr({'role':'button','aria-label':$(this).text()+' '+row.find('.tzfd_list_time').text()+' '+row.find('.tzfd_list_service').text(),
                    'data-booking-focus':'cancel:'+row.attr('d')+':'+row.attr('s')+':'+row.attr('h1')+':'+row.attr('m1')});
            });
            var error = group.find('div.tzfdefb_error:visible').first();
            var service = group.find('.tzfd_field_service');
            if (error.length && error.attr('id')) {
                service.attr({'aria-invalid':'true','aria-describedby':error.attr('id')});
                calendar.attr('aria-describedby',error.attr('id'));
            } else {
                service.removeAttr('aria-invalid aria-describedby');calendar.removeAttr('aria-describedby');
            }
            if (last && !root.contains(last.node) && document.activeElement === document.body) {
                var replacement = group.find('[data-booking-focus]').filter(function(){return key(this)===last.key;}).first();
                if (!replacement.length && last.key && last.key.indexOf('cancel:')===0) {
                    var slotKey = last.key.replace('cancel:','slot:');
                    replacement = group.find('[data-booking-focus]').filter(function(){return key(this)===slotKey;}).first();
                }
                if (!replacement.length) replacement = calendar.find('[data-handler="selectDay"] a').first();
                if (!replacement.length) replacement = service;
                replacement.trigger('focus');
            }
        }
        $(root).on('focusin.tzfdFrontDesk',function(event){last={node:event.target,key:key(event.target)};});
        $(root).on('keydown.tzfdFrontDesk','a[role=button]',function(event){
            if (event.key !== ' ' && event.key !== 'Enter') return;
            event.preventDefault();
            if (event.repeat || (event.originalEvent && event.originalEvent.repeat) || $(this).attr('aria-disabled')==='true') return;
            $(this).trigger('click');
        });
        refresh();
        new MutationObserver(refresh).observe(root,{childList:true,subtree:true});
    };
})(typeof tzfdformjQuery !== 'undefined' ? tzfdformjQuery : (typeof jQuery !== 'undefined' ? jQuery : null));

/* Acceptance terms are modal where native <dialog> is available. Older browsers
 * receive an inline disclosure with the same content and close/focus controls. */
(function ($) {
    if (!$) return;
    $.tzfdform = $.tzfdform || {};
    $.tzfdform.enhanceAcceptanceDialog = function (root) {
        if (!root || root.tzfdFrontDeskDialog) return;
        var opener = root.querySelector('.cff-open-dlg');
        var dialog = root.querySelector('.cff-dialog');
        var closeButton = root.querySelector('.cff-close-dlg');
        if (!opener || !dialog || !closeButton) return;
        root.tzfdFrontDeskDialog = true;
        var nativeModal = typeof dialog.showModal === 'function';
        var restoreAfterClose = true;
        if (!nativeModal) {
            dialog.classList.add('cff-dialog-inline');
            dialog.setAttribute('role', 'region');
            opener.removeAttribute('aria-haspopup');
        }
        function finish(restore) {
            dialog.classList.add('hide');
            opener.setAttribute('aria-expanded', 'false');
            if (restore && opener.isConnected && opener.getClientRects().length) opener.focus();
        }
        function close(restore) {
            restoreAfterClose = restore;
            if (nativeModal && dialog.open) dialog.close();
            else dialog.removeAttribute('open');
            finish(restore);
        }
        function open(event) {
            event.preventDefault();
            if (dialog.hasAttribute('open')) return;
            restoreAfterClose = true;
            dialog.classList.remove('hide');
            if (nativeModal) dialog.showModal();
            else dialog.setAttribute('open', '');
            opener.setAttribute('aria-expanded', 'true');
            closeButton.focus();
        }
        opener.addEventListener('click', open);
        opener.addEventListener('keydown', function (event) {
            if (event.key === ' ' || (event.key === 'Enter' && event.repeat)) event.preventDefault();
            if (event.key === ' ' && !event.repeat) open(event);
        });
        closeButton.addEventListener('click', function () { close(true); });
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(true); });
        dialog.addEventListener('close', function () {
            // A queued close event must not hide a subsequent reopen.
            if (!dialog.hasAttribute('open')) finish(restoreAfterClose);
        });
        dialog.addEventListener('keydown', function (event) {
            if (!nativeModal && event.key === 'Escape') { event.preventDefault(); close(true); }
            if (nativeModal && dialog.open && event.key === 'Tab') {
                var targets = Array.prototype.slice.call(dialog.querySelectorAll('a[href],button,input,select,textarea,[tabindex],summary,[contenteditable=true]'))
                    .filter(function (el) { return el.tabIndex >= 0 && !el.matches(':disabled') && !el.closest('[inert]') && el.getClientRects().length && root.ownerDocument.defaultView.getComputedStyle(el).visibility !== 'hidden'; })
                    .sort(function (a, b) { return (a.tabIndex || Infinity) - (b.tabIndex || Infinity); });
                var first = targets[0] || closeButton, last = targets[targets.length - 1] || closeButton;
                if (event.shiftKey && root.ownerDocument.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && root.ownerDocument.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        // No history entries are created by opening or closing terms. Navigating
        // away closes them so Back/Forward cannot restore a stranded modal.
        root.ownerDocument.defaultView.addEventListener('pagehide', function () { close(false); });
    };
    $.tzfdform.markCaptchaError = function (field, error) {
        if (!field || !error) return;
        var descriptions = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (descriptions.indexOf(error.id) === -1) descriptions.push(error.id);
        field.setAttribute('aria-describedby', descriptions.join(' '));
        field.setAttribute('aria-invalid', 'true');
        field.focus();
    };
    $.tzfdform.clearCaptchaError = function (field) {
        if (!field || !field.getAttribute) return;
        var errorId = field.getAttribute('data-booking-captcha-error');
        if (!errorId) return;
        var error = field.ownerDocument.getElementById(errorId);
        if (error) error.style.display = 'none';
        var descriptions = (field.getAttribute('aria-describedby') || '').split(/\s+/)
            .filter(function (id) { return id && id !== errorId; });
        if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' '));
        else field.removeAttribute('aria-describedby');
        field.setAttribute('aria-invalid', 'false');
    };
    if (typeof document !== 'undefined' && !document.tzfdFrontDeskCaptchaInput) {
        document.tzfdFrontDeskCaptchaInput = true;
        document.addEventListener('input', function (event) {
            $.tzfdform.clearCaptchaError(event.target);
        });
    }
})(typeof tzfdformjQuery !== 'undefined' ? tzfdformjQuery : (typeof jQuery !== 'undefined' ? jQuery : null));
