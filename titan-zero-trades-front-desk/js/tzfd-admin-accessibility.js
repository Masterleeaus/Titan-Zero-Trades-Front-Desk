/* Essential schedule controls retain donor behavior while gaining keyboard semantics. */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root && root.document) api.start(root.document, root.MutationObserver);
})(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    function labelFor(node) {
        var titled = node.querySelector ? node.querySelector('[title]') : null;
        return (node.getAttribute('title') || (titled && titled.getAttribute('title')) || node.textContent || '').trim();
    }

    function activateOnKey(event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        if (event.repeat || event.currentTarget.getAttribute('aria-disabled') === 'true') return;
        event.currentTarget.click();
        var toolbar = event.currentTarget.closest ? event.currentTarget.closest('.ctoolbar') : null;
        if (toolbar) Array.prototype.forEach.call(toolbar.querySelectorAll('.fbutton'), enhanceAction);
    }

    function enhanceAction(node) {
        var disabled = (node.classList && (node.classList.contains('non-navigate') || node.classList.contains('ui-state-disabled') || node.classList.contains('fbuttonoff')));
        var id = node.id || node.getAttribute('id') || '';
        var viewAction = /^(?:showdaybtn|shownDaysbtn|showweekbtn|showmonthbtn|showrowMonthbtn|shownMonthbtn|shownListbtn)/.test(id);
        node.setAttribute('role', 'button');
        node.setAttribute('tabindex', disabled ? '-1' : '0');
        node.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        if (viewAction) node.setAttribute('aria-pressed', node.classList.contains('ui-state-active') ? 'true' : 'false');
        if (!node.getAttribute('aria-label')) node.setAttribute('aria-label', labelFor(node));
        if (node.addEventListener && !node.getAttribute('data-tzfd-keyboard')) {
            node.setAttribute('data-tzfd-keyboard', 'true');
            node.addEventListener('keydown', activateOnKey);
        }
    }

    function enhanceRegion(node, label) {
        node.setAttribute('role', 'region');
        node.setAttribute('tabindex', '0');
        if (!node.getAttribute('aria-label')) node.setAttribute('aria-label', label);
    }

    function enhance(document) {
        var list = document.querySelector('#dex_printable_contents');
        var calendar = document.querySelector('#multicalendar');
        if (list) enhanceRegion(list, 'Booking schedule list');
        if (calendar) enhanceRegion(calendar, 'Booking schedule calendar');
        Array.prototype.forEach.call(document.querySelectorAll('#multicalendar .ctoolbar .fbutton'), enhanceAction);
    }

    function start(document, MutationObserverClass) {
        function ready() {
            enhance(document);
            if (MutationObserverClass && document.body) {
                new MutationObserverClass(function () { enhance(document); }).observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['class']});
            }
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, {once: true});
        else ready();
    }

    return {activateOnKey: activateOnKey, enhanceAction: enhanceAction, enhanceRegion: enhanceRegion, enhance: enhance, start: start};
});
