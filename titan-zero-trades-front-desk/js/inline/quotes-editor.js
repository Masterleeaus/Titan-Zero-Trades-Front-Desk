/*
 * Quote editor (admin): keeps the totals box and the line totals in step while typing.
 * The server recalculates on save. Values come from wp_localize_script() as window.tzfdQuoteEditor: tpl, dec, next.
 */
(function () {
    'use strict';

    var cfg = window.tzfdQuoteEditor || {};
    var form = document.getElementById('tzfd-quote-form');
    var tbody = document.getElementById('tzfd-quote-rows');
    var tplEl = document.getElementById('tzfd-quote-row-template');
    if (!form || !tbody || !tplEl) {
        return;
    }

    var tpl = String(cfg.tpl || '%s');
    var dec = parseInt(cfg.dec, 10) || 0;
    var next = parseInt(cfg.next, 10) || 0;

    function num(v) {
        var n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }

    function fmt(n) {
        var s = Math.abs(n).toFixed(dec).split('.'), out = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        if (dec > 0) {
            out += '.' + s[1];
        }
        return (n < 0 ? '-' : '') + tpl.replace('%s', out);
    }

    function recalc() {
        var sub = 0, taxable = 0, rows = tbody.querySelectorAll('tr.tzfd-quote-item');
        rows.forEach(function (tr) {
            var line = num(tr.querySelector('.tzfd-qty').value) * num(tr.querySelector('.tzfd-price').value);
            tr.querySelector('.tzfd-line-total').textContent = fmt(line);
            sub += line;
            if (tr.querySelector('.tzfd-taxable').checked) {
                taxable += line;
            }
        });
        var disc = num(document.getElementById('tzfd_q_disc').value), rate = num(document.getElementById('tzfd_q_tax').value);
        var taxableAfter = Math.max(0, taxable - disc * (taxable / Math.max(sub, 0.01)));
        var tax = taxableAfter * rate / 100, total = Math.max(0, sub - disc + tax);
        document.getElementById('tzfd-t-sub').textContent = fmt(sub);
        document.getElementById('tzfd-t-disc').textContent = fmt(-disc);
        document.getElementById('tzfd-t-tax').textContent = fmt(tax);
        document.getElementById('tzfd-t-total').textContent = fmt(total);
        document.getElementById('tzfd-t-dep').textContent = fmt(num(document.querySelector('input[name=deposit]').value));
    }

    document.getElementById('tzfd-quote-add-row').addEventListener('click', function () {
        var html = tplEl.innerHTML.split('__I__').join(String(next++));
        var wrap = document.createElement('tbody');
        wrap.innerHTML = html;
        tbody.appendChild(wrap.firstElementChild);
        recalc();
    });

    tbody.addEventListener('click', function (e) {
        var b = e.target.closest('.tzfd-row-remove');
        if (b) {
            b.closest('tr').remove();
            recalc();
        }
    });

    form.addEventListener('input', function (e) {
        if (e.target.classList.contains('tzfd-calc') || e.target.id === 'tzfd_q_disc' || e.target.id === 'tzfd_q_tax' || e.target.name === 'deposit') {
            recalc();
        }
    });
    form.addEventListener('change', recalc);

    recalc();
}());
