/* Titan Zero Front Desk: accessible, progressive create/edit wizards.
 * Forms remain native WordPress forms: no AJAX persistence or AI auto-save. */
(function () {
  'use strict';
  var cfg = window.tzfdWizardConfig || {}, labels = cfg.labels || {};
  function node(tag, cls, value) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (value !== undefined) e.textContent = value;
    return e;
  }
  function field(form, name) { return Array.prototype.find.call(form.querySelectorAll('[name]'), function (e) { return e.name === name; }) || null; }
  function formType(form) {
    var op = field(form, 'operation'), action = field(form, 'action');
    if (op && op.value === 'resource_save') return 'resource';
    if (op && op.value === 'location_save') return 'location';
    if (action && /^tz_fd_service_(create|update)$/.test(action.value)) return 'service';
    if (action && action.value === 'tzfd_customer_save') return 'customer';
    if (form.classList.contains('tz_fd_p_form') && document.getElementById('tzfd-admin-booking-health')) return 'booking';
    return null;
  }
  function itemsFor(form, type) {
    var items = [], current = 0;
    if (type === 'booking') {
      Array.prototype.forEach.call(form.querySelectorAll('.dfield'), function (e) {
        if (e.parentElement && e.parentElement.closest('.dfield')) return;
        if (!e.querySelector('input,select,textarea')) return;
        var label = ((e.querySelector('label') || {}).textContent || '') + ' ' + e.className;
        var names = Array.prototype.map.call(e.querySelectorAll('[name]'), function (c) { return c.name; }).join(' ');
        var step = /fapp|appointment|service|slot|date|time/i.test(label + ' ' + names) ? 1 :
          /name|email|phone|address|customer|postcode/i.test(label + ' ' + names) ? 0 : 1;
        items.push({ node: e, step: step });
      });
    } else if (type === 'resource') {
      Array.prototype.forEach.call(form.children, function (e) {
        if (e.matches('h2,h3')) {
          if (/working hours|availability|schedule/i.test(e.textContent)) current = 1;
          if (/booking|capacity|restriction|advanced/i.test(e.textContent)) current = 2;
          items.push({ node: e, step: current });
        } else if (e.matches('.tzfd-form-grid,.tzfd-days,fieldset')) {
          items.push({ node: e, step: current });
        }
      });
    } else {
      Array.prototype.forEach.call(form.querySelectorAll('.tzfd-form-grid > div, .form-table tr'), function (e) {
        var c = e.querySelector('input:not([type="hidden"]),select,textarea'), name = c ? c.name.toLowerCase() : '';
        if (!c) return;
        var step = /^(name|email|phone|id|target|kind|role|calendar)$/.test(name) ? 0 : 1;
        if (type === 'customer') step = name === 'name' ? 0 : 1;
        if (type === 'location') step = /^(id|name|phone)$/.test(name) ? 0 : 1;
        items.push({ node: e, step: step });
      });
    }
    return items;
  }
  function allControls(item) { return Array.prototype.slice.call(item.node.querySelectorAll('input,select,textarea')); }
  function safeFill(target, value) {
    if (!target || typeof value !== 'string' || !value.trim() || target.disabled || target.readOnly) return false;
    if (/^(hidden|password|file|checkbox|radio)$/.test(target.type) || String(target.value || '').trim()) return false;
    if (target.tagName === 'SELECT') {
      var option = Array.prototype.find.call(target.options, function (o) { return o.value.toLowerCase() === value.toLowerCase() || o.textContent.trim().toLowerCase() === value.toLowerCase(); });
      if (!option || option.disabled) return false;
      target.value = option.value;
    } else { target.value = value; }
    target.dispatchEvent(new Event('input', { bubbles: true }));
    target.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }
  function localDefaults(form, type) {
    var count = 0;
    function fill(k, v) { if (safeFill(field(form, k), v)) count++; }
    if (type === 'resource') {
      var kind = field(form, 'kind');
      if (kind && kind.value === 'provider') fill('role', 'Team member');
      if (kind && kind.value === 'equipment') fill('role', 'Equipment');
      if (kind && kind.value === 'room') fill('role', 'Bookable room');
      fill('start', '08:00'); fill('end', '17:00');
    }
    if (type === 'location' && window.Intl && Intl.DateTimeFormat) {
      fill('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone);
    }
    return count;
  }
  function smartPanel(form, type) {
    var panel = node('section', 'tzfd-wizard-smart'), heading = node('h3', '', labels.smart || 'Smart fill');
    panel.appendChild(heading);
    panel.appendChild(node('p', '', 'Suggestions are optional. Existing values are never overwritten. Always review before saving.'));
    var feedback = node('p', 'tzfd-wizard-feedback'); feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite');
    var defaults = node('button', 'button', 'Use suggested defaults'); defaults.type = 'button';
    defaults.addEventListener('click', function () { var n = localDefaults(form, type); feedback.textContent = n ? n + ' empty field(s) filled. Please review.' : 'No matching empty fields.'; });
    panel.appendChild(defaults);
    var description = node('textarea'); description.rows = 2; description.maxLength = 1500;
    description.placeholder = labels.notes || 'Describe what you want to add';
    description.setAttribute('aria-label', 'Description for optional AI suggestions'); panel.appendChild(description);
    var suggest = node('button', 'button', labels.suggest || 'Suggest details'); suggest.type = 'button';
    var suggestions = node('div', 'tzfd-wizard-suggestions');
    if (!cfg.aiAvailable) { suggest.disabled = true; feedback.textContent = labels.unavailable || 'AI suggestions are not configured.'; }
    suggest.addEventListener('click', function () {
      if (!description.value.trim()) { feedback.textContent = 'Describe what to suggest first.'; description.focus(); return; }
      suggest.disabled = true; feedback.textContent = 'Requesting suggestions…'; suggestions.replaceChildren();
      var post = new URLSearchParams({ action: 'tzfd_wizard_suggestions', nonce: cfg.nonce || '', type: type, description: description.value });
      fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: post.toString() })
        .then(function (r) { return r.json(); }).then(function (data) {
          if (!data.success) throw new Error((data.data && data.data.message) || 'Suggestion request failed.');
          var values = data.data.suggestions || {}, proposed = 0;
          Object.keys(values).forEach(function (key) {
            var target = field(form, key), v = values[key];
            if (!target || typeof v !== 'string' || String(target.value || '').trim()) return;
            var label = node('label', 'tzfd-wizard-choice'), check = node('input');
            check.type = 'checkbox'; check.checked = false; check.dataset.key = key;
            label.appendChild(check); label.appendChild(document.createTextNode(' ' + key + ': ' + v)); suggestions.appendChild(label); proposed++;
          });
          if (!proposed) { feedback.textContent = 'No applicable empty fields.'; return; }
          var apply = node('button', 'button button-primary', labels.apply || 'Apply selected suggestions'); apply.type = 'button';
          apply.addEventListener('click', function () {
            var applied = 0;
            Array.prototype.forEach.call(suggestions.querySelectorAll('input:checked'), function (check) {
              if (safeFill(field(form, check.dataset.key), values[check.dataset.key])) applied++;
            });
            feedback.textContent = applied + ' field(s) filled. Review before saving.'; suggestions.replaceChildren();
          });
          suggestions.appendChild(apply); feedback.textContent = 'Choose suggestions to apply; none are selected automatically.';
        }).catch(function (err) { feedback.textContent = err.message || 'Suggestion request failed.'; })
        .finally(function () { suggest.disabled = false; });
    });
    panel.appendChild(suggest); panel.appendChild(suggestions); panel.appendChild(feedback);
    return panel;
  }
  function init(form, type) {
    if (form.dataset.tzfdWizardReady) return;
    var items = itemsFor(form, type);
    if (items.length < 2 || !items.some(function (x) { return x.step === 1; })) return;
    form.dataset.tzfdWizardReady = '1'; form.classList.add('tzfd-wizard-enabled');
    var headings = type === 'booking' ? ['Customer', 'Service and time', 'Review and save'] :
      type === 'resource' ? ['Details', 'Working hours', 'Bookings and review'] :
      type === 'location' ? ['Location details', 'Availability', 'Review and save'] :
      type === 'customer' ? ['Customer', 'Contact details', 'Review and save'] : ['Service', 'Price and duration', 'Review and save'];
    var nav = node('nav', 'tzfd-wizard-header'), progress = node('ol', 'tzfd-wizard-steps');
    nav.setAttribute('aria-label', 'Form steps');
    headings.forEach(function (title, index) { var li = node('li', '', (index + 1) + '. ' + title); progress.appendChild(li); });
    nav.appendChild(progress); form.insertBefore(nav, form.firstChild);
    nav.after(smartPanel(form, type));
    var footer = node('div', 'tzfd-wizard-navigation');
    var previous = node('button', 'button', labels.previous || 'Back'); previous.type = 'button';
    var next = node('button', 'button button-primary', labels.next || 'Continue'); next.type = 'button';
    var message = node('p', 'tzfd-wizard-validation'); message.setAttribute('role', 'alert');
    footer.appendChild(previous); footer.appendChild(next); footer.appendChild(message); form.appendChild(footer);
    var submits = Array.prototype.filter.call(form.querySelectorAll('button[type="submit"],input[type="submit"],button.pbSubmit'), function (e) {
      return !e.closest('.tzfd-wizard-navigation');
    });
    var required = [];
    items.forEach(function (item) { allControls(item).forEach(function (c) { if (c.required && required.indexOf(c) < 0) required.push(c); }); });
    // Legacy booking forms create their .pbSubmit asynchronously; it is a type=button
    // with a jQuery delegated submit handler, NOT a native submit control.
    // Intercept it in capture phase until review, leaving the original saving
    // handler untouched on the final step.
    var booking = type === 'booking';
    // The appointment widget serialises selected slots into a hidden field.
    // Browser constraint validation deliberately skips hidden controls; the
    // wizard must check that field explicitly before leaving the service step
    // and again before the delegated AJAX Save handler executes.
    function bookingSelectionMissing() {
      if (!booking) return false;
      form.dispatchEvent(new Event('tzfd:sync-booking-slots', { bubbles: true }));
      var slots = form.querySelectorAll('.dfield.fapp input[summary="usedSlots"]');
      // Do not turn a general contact-only form into a mandatory booking form.
      if (!slots.length) return false;
      return Array.prototype.some.call(slots, function (slot) {
        return !slot.value || !slot.value.trim();
      });
    }
    function showMissingBookingSelection() {
      show(1, true);
      message.textContent = 'Choose a service, date and available time before continuing.';
      var appointment = form.querySelector('.dfield.fapp');
      if (appointment) {
        if (!appointment.hasAttribute('tabindex')) appointment.setAttribute('tabindex', '-1');
        appointment.focus();
      } else message.focus();
    }
    var bookingSubmit = function (event) {
      var target = event.target && event.target.closest ? event.target.closest('.pbSubmit') : null;
      if (!target || !form.contains(target)) return;
      // The vendor button invokes a delegated AJAX handler instead of a native
      // submit event. Validate here on *every* click, including Review, or the
      // browser's normal form constraint checks are bypassed entirely.
      var invalid = invalidAt(step === 2 ? 2 : step);
      if ((step === 1 || step === 2) && bookingSelectionMissing()) {
        event.preventDefault(); event.stopPropagation();
        if (event.stopImmediatePropagation) event.stopImmediatePropagation();
        showMissingBookingSelection();
        return;
      }
      if (step !== 2 || invalid) {
        event.preventDefault(); event.stopPropagation();
        if (event.stopImmediatePropagation) event.stopImmediatePropagation();
        if (invalid) {
          var owner = items.find(function (item) { return item.node.contains(invalid); });
          if (step === 2 && owner) show(owner.step, false);
          message.textContent = 'Complete the highlighted field before saving.';
          invalid.reportValidity(); invalid.focus();
        } else show(step === 0 ? 1 : 2, true);
      }
    };
    if (booking) form.addEventListener('click', bookingSubmit, true);
    var step = 0, review = node('div', 'tzfd-wizard-review');
    review.setAttribute('aria-live', 'polite'); nav.appendChild(review);
    function invalidAt(index) {
      var invalid = null;
      items.forEach(function (item) {
        if (invalid || (index < 2 && item.step !== index)) return;
        allControls(item).forEach(function (c) { if (!invalid && c.willValidate && !c.checkValidity()) invalid = c; });
      });
      return invalid;
    }
    function show(index, focus) {
      step = index; message.textContent = '';
      items.forEach(function (item) { item.node.hidden = index !== 2 && item.step !== index; });
      progress.querySelectorAll('li').forEach(function (li, i) {
        li.classList.toggle('is-current', i === index);
        if (i === index) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
      });
      previous.disabled = index === 0; next.hidden = index === 2;
      // Keep the final action immediately beneath the review, not after the full form.
      if (index === 2) nav.after(footer);
      else form.appendChild(footer);
      // Include the booking builder's buttons, which are dynamically generated.
      var saveButtons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"],input[type="submit"],button.pbSubmit'));
      saveButtons.forEach(function (button) {
        if (button.closest('.tzfd-wizard-navigation')) return;
        button.hidden = index !== 2;
        if (index === 2 && type === 'resource') {
          // Move the real submit control; preserve its native handler and attributes.
          footer.insertBefore(button, message);
        }
      });
      // Keep the builder's real save control on the Review screen only.
      // A MutationObserver below also hides delayed builder controls.

      // Disable native constraint validation only while a required field is on a hidden step.
      // Restore every required field before review/submit; never destroy its original value.
      required.forEach(function (c) { c.required = index === 2 || items.some(function (item) { return item.step === index && item.node.contains(c); }); });
      if (index === 2) {
        review.replaceChildren(); review.appendChild(node('strong', '', 'Review these details before saving'));
        if (booking) {
          var customerSelect = document.getElementById('tz_fd_booking_customer_select');
          if (customerSelect && customerSelect.selectedIndex >= 0) {
            var selected = customerSelect.options[customerSelect.selectedIndex];
            review.appendChild(node('p', '', 'Customer: ' + (selected ? selected.textContent : 'New customer')));
          }
        }
        var list = node('dl', 'tzfd-wizard-review-list'), seen = [];
        items.forEach(function (item) { allControls(item).forEach(function (c) {
          if (!c.name || seen.indexOf(c.name) !== -1 || /^(hidden|password|file|submit)$/.test(c.type)) return;
          seen.push(c.name);
          var value = c.type === 'checkbox' ? (c.checked ? 'Yes' : 'No') : (c.tagName === 'SELECT' && c.selectedIndex >= 0 ? c.options[c.selectedIndex].textContent.trim() : (c.value || '—'));
          if (c.type === 'radio' && !c.checked) return;
          var label = c.id ? form.querySelector('label[for="' + c.id.replace(/[^a-zA-Z0-9_-]/g, '') + '"]') : null;
          list.appendChild(node('dt', '', label ? label.textContent.trim() : c.name));
          list.appendChild(node('dd', '', value));
        }); });
        review.appendChild(list);
      } else review.replaceChildren();
      if (focus) nav.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    previous.addEventListener('click', function () { if (step > 0) show(step - 1, true); });
    next.addEventListener('click', function () {
      if (step === 1 && bookingSelectionMissing()) { showMissingBookingSelection(); return; }
      var invalid = invalidAt(step);
      if (invalid) { message.textContent = 'Complete the highlighted field before continuing.'; invalid.reportValidity(); invalid.focus(); return; }
      show(Math.min(step + 1, 2), true);
    });
    form.addEventListener('invalid', function (event) {
      var control = event.target, owner = items.find(function (item) { return item.node.contains(control); });
      if (owner && step !== 2 && owner.step !== step) show(owner.step, false);
      message.textContent = 'Please correct this field before saving.';
    }, true);
    form.addEventListener('submit', function (event) {
      if (bookingSelectionMissing()) { event.preventDefault(); showMissingBookingSelection(); return; }
      if (step !== 2) { event.preventDefault(); show(2, true); return; }
      var invalid = invalidAt(2);
      if (invalid) {
        event.preventDefault(); var owner = items.find(function (item) { return item.node.contains(invalid); });
        show(owner ? owner.step : 0, false); message.textContent = 'Correct the highlighted field and return to review.';
        invalid.reportValidity(); invalid.focus();
      }
    });
    show(0, false);
    if (booking) {
      // The vendor form builder inserts its final button AFTER our wizard is
      // initialised. Never expose that bypass while on an earlier step.
      // Observe the form itself: older templates do not always have fieldlist_
      // when the wizard is initialised. New controls must never bypass Review.
      if (window.MutationObserver) {
        var buttonObserver = new MutationObserver(function () {
          Array.prototype.forEach.call(form.querySelectorAll('button.pbSubmit'), function (button) {
            button.hidden = step !== 2;
          });
        });
        buttonObserver.observe(form, { childList: true, subtree: true });
      }
    }
  }
  function scan() { Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) { var type = formType(form); if (type) init(form, type); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
  // Booking fields are populated after the initial DOM ready event.
  // Observe only the Front Desk form root; no permanent whole-admin observer.
  function watchBooking() {
    if (!window.MutationObserver) return;
    Array.prototype.forEach.call(document.querySelectorAll('form.tz_fd_p_form'), function (form) {
      if (form.dataset.tzfdWizardWatching) return;
      form.dataset.tzfdWizardWatching = '1';
      var fieldList = form.querySelector('[id^="fieldlist_"]');
      if (!fieldList) return;
      var observer = new MutationObserver(function () {
        if (!form.dataset.tzfdWizardReady) init(form, 'booking');
        if (form.dataset.tzfdWizardReady) observer.disconnect();
      });
      observer.observe(fieldList, { childList: true, subtree: true });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watchBooking);
  else watchBooking();
  var attempts = 0, retry = window.setInterval(function () {
    scan(); watchBooking(); if (++attempts >= 60) window.clearInterval(retry);
  }, 400);
}());
