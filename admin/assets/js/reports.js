/**
 * admin/assets/js/reports.js
 * -----------------------------------------------------------------------
 * Shared behavior for the Reports & Analytics toolbar (Week/Month/Year
 * pills are plain links — no JS needed there) plus the two dropdowns:
 * the custom date-range calendar and the PDF/XLSX export menu. Loaded
 * only by report.php + report-financial.php + report-performance.php +
 * report-guest-insights.php + report-inventory.php, the same way
 * accommodations.js is loaded only by accommodations.php.
 * -----------------------------------------------------------------------
 */
(function () {
    'use strict';

    /* ---------------------------------------------------------------
       Dropdown open/close (Custom calendar + Export menu)
    --------------------------------------------------------------- */
    var toggleBtns = document.querySelectorAll('[data-rpt-toggle]');

    function closeAllPanels(except) {
        document.querySelectorAll('[data-rpt-panel]').forEach(function (panel) {
            if (panel !== except) {
                panel.hidden = true;
            }
        });
        toggleBtns.forEach(function (btn) {
            var name = btn.getAttribute('data-rpt-toggle');
            var panel = document.querySelector('[data-rpt-panel="' + name + '"]');
            btn.setAttribute('aria-expanded', panel && !panel.hidden ? 'true' : 'false');
        });
    }

    toggleBtns.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var name = btn.getAttribute('data-rpt-toggle');
            var panel = document.querySelector('[data-rpt-panel="' + name + '"]');
            if (!panel) return;
            var willOpen = panel.hidden;
            closeAllPanels(willOpen ? panel : null);
            panel.hidden = !willOpen;
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.rpt-dropdown-wrap')) {
            closeAllPanels(null);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllPanels(null);
    });

    /* ---------------------------------------------------------------
       Custom date-range calendar
    --------------------------------------------------------------- */
    var calRoot = document.querySelector('[data-rpt-calendar]');
    if (!calRoot) return;

    var titleEl  = calRoot.querySelector('[data-rpt-cal-title]');
    var gridEl   = calRoot.querySelector('[data-rpt-cal-grid]');
    var prevBtn  = calRoot.querySelector('[data-rpt-cal-prev]');
    var nextBtn  = calRoot.querySelector('[data-rpt-cal-next]');
    var rangeEl  = document.querySelector('[data-rpt-cal-range]');
    var applyBtn = document.querySelector('[data-rpt-cal-apply]');
    var cancelBtn = document.querySelector('[data-rpt-cal-cancel]');

    var MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function parseYmd(s) {
        var parts = s.split('-').map(Number);
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }
    function fmtYmd(d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }
    function fmtNice(d) {
        return MONTH_NAMES[d.getMonth()].slice(0, 3) + ' ' + d.getDate() + ', ' + d.getFullYear();
    }
    function sameDay(a, b) {
        return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    var initialStart = calRoot.getAttribute('data-start');
    var initialEnd   = calRoot.getAttribute('data-end');
    var selStart = initialStart ? parseYmd(initialStart) : null;
    var selEnd   = initialEnd ? parseYmd(initialEnd) : null;
    var viewDate = selEnd ? new Date(selEnd.getFullYear(), selEnd.getMonth(), 1)
                          : new Date();
    viewDate.setDate(1);

    var today = new Date();
    today.setHours(0, 0, 0, 0);

    function render() {
        titleEl.textContent = MONTH_NAMES[viewDate.getMonth()] + ' ' + viewDate.getFullYear();
        gridEl.innerHTML = '';

        var firstOfMonth = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
        var startWeekday = firstOfMonth.getDay(); // 0 = Sunday
        var gridStart = new Date(firstOfMonth);
        gridStart.setDate(gridStart.getDate() - startWeekday);

        for (var i = 0; i < 42; i++) {
            var cellDate = new Date(gridStart);
            cellDate.setDate(gridStart.getDate() + i);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rpt-cal-day';
            btn.textContent = cellDate.getDate();

            if (cellDate.getMonth() !== viewDate.getMonth()) {
                btn.classList.add('is-other-month');
            }
            if (sameDay(cellDate, today)) {
                btn.classList.add('is-today');
            }
            if (selStart && sameDay(cellDate, selStart)) {
                btn.classList.add('is-range-start');
            }
            if (selEnd && sameDay(cellDate, selEnd)) {
                btn.classList.add('is-range-end');
            }
            if (selStart && selEnd && cellDate > selStart && cellDate < selEnd) {
                btn.classList.add('is-in-range');
            }

            (function (d) {
                btn.addEventListener('click', function () { pickDate(d); });
            })(cellDate);

            gridEl.appendChild(btn);
        }

        if (selStart && selEnd) {
            rangeEl.textContent = fmtNice(selStart) + ' \u2013 ' + fmtNice(selEnd);
        } else if (selStart) {
            rangeEl.textContent = fmtNice(selStart) + ' \u2013 pick an end date';
        } else {
            rangeEl.textContent = 'Pick a start and end date';
        }
    }

    function pickDate(d) {
        if (!selStart || (selStart && selEnd)) {
            selStart = d;
            selEnd = null;
        } else if (d < selStart) {
            selEnd = selStart;
            selStart = d;
        } else {
            selEnd = d;
        }
        render();
    }

    prevBtn.addEventListener('click', function () {
        viewDate.setMonth(viewDate.getMonth() - 1);
        render();
    });
    nextBtn.addEventListener('click', function () {
        viewDate.setMonth(viewDate.getMonth() + 1);
        render();
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            var panel = document.querySelector('[data-rpt-panel="calendar"]');
            if (panel) panel.hidden = true;
        });
    }

    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            if (!selStart || !selEnd) return;
            var url = new URL(window.location.href);
            url.searchParams.set('period', 'custom');
            url.searchParams.set('start', fmtYmd(selStart));
            url.searchParams.set('end', fmtYmd(selEnd));
            window.location.href = url.toString();
        });
    }

    render();
})();
