<?php
/**
 * admin/includes/reports-toolbar.php
 * -----------------------------------------------------------------------
 * Markup helpers for the Reports & Analytics toolbar: the Week/Month/
 * Year/Custom period switcher and the PDF/XLSX export menu that appear
 * in the topbar on every report page. Rendered into topbar.php's
 * optional $topbar_extra_right slot (see includes/topbar.php) so the
 * shared topbar itself stays untouched for every other admin page.
 *
 * All period switching is done via full page reloads with query-string
 * params (?period=week / ?start=&end=&period=custom), the same pattern
 * admin/payments.php already uses for its status/search filters — no
 * JSON endpoint needed. The interactive bits (opening the calendar
 * dropdown, picking a range, opening the export menu) live in
 * assets/js/reports.js.
 * -----------------------------------------------------------------------
 */

/**
 * A couple of extra inline icons the reports toolbar needs that aren't
 * in the shared admin/includes/icons.php registry. Same "local extras,
 * fall through to the shared icon() helper" approach admin/expenses.php
 * and admin/inventory.php already use for their own pages.
 */
function rpt_icon(string $name, string $class = 'icon'): string
{
    $extra = [
        'chevron-down' => '<polyline points="6 9 12 15 18 9"></polyline>',
        'printer'      => '<polyline points="6 9 6 3 18 3 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="7"></rect>',
        'chevron-left' => '<polyline points="15 18 9 12 15 6"></polyline>',
        'trophy'       => '<path d="M8 4h8v5a4 4 0 0 1-8 0V4z"></path><path d="M8 5H5a2 2 0 0 0 0 4h2.3"></path><path d="M16 5h3a2 2 0 0 1 0 4h-2.3"></path><line x1="12" y1="13" x2="12" y2="17"></line><path d="M8 21h8"></path><path d="M9 21v-2.5a3 3 0 0 1 6 0V21"></path>',
    ];
    if (isset($extra[$name])) {
        $safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        return '<svg class="' . $safeClass . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
             . $extra[$name] . '</svg>';
    }
    return icon($name, $class);
}

/**
 * Builds a URL to the current page with the given query-string params
 * overridden (null values remove that key entirely). Used for the
 * Week/Month/Year links and the Export PDF/XLSX links.
 */
function reports_query_url(array $overrides): string
{
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return basename($_SERVER['PHP_SELF']) . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Renders the Week / Month / Year / Custom / Export controls that sit
 * in the topbar on every report page. $reportSlug is one of
 * financial|performance|guest-insights|inventory, used to build the
 * export links; pass null on the Reports Overview page itself, which
 * hides the toolbar (there's nothing to export there).
 */
function render_reports_topbar_controls(?string $reportSlug, array $period): string
{
    if ($reportSlug === null) {
        return '';
    }

    $period_ = $period['period'];
    $start   = $period['start'];
    $end     = $period['end'];

    $pillHref = function (string $p) {
        return htmlspecialchars(reports_query_url(['period' => $p, 'start' => null, 'end' => null]), ENT_QUOTES, 'UTF-8');
    };
    $pillClass = fn(string $p) => 'period-pill' . ($period_ === $p ? ' is-active' : '');

    $exportBase = 'reports-export.php?report=' . urlencode($reportSlug) . '&period=' . urlencode($period_)
                . '&start=' . urlencode($start) . '&end=' . urlencode($end);

    ob_start();
    ?>
    <div class="rpt-toolbar" data-rpt-toolbar>
        <div class="period-toggle rpt-period-toggle" role="group" aria-label="Report period">
            <a href="<?php echo $pillHref('week'); ?>" class="<?php echo $pillClass('week'); ?>">Week</a>
            <a href="<?php echo $pillHref('month'); ?>" class="<?php echo $pillClass('month'); ?>">Month</a>
            <a href="<?php echo $pillHref('year'); ?>" class="<?php echo $pillClass('year'); ?>">Year</a>
        </div>

        <div class="rpt-dropdown-wrap">
            <button type="button" class="btn btn-secondary rpt-toolbar-btn" data-rpt-toggle="calendar">
                <?php echo icon('calendar', 'icon-sm'); ?>
                <span><?php echo $period_ === 'custom' ? htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8') : 'Custom'; ?></span>
                <?php echo rpt_icon('chevron-down', 'icon-sm rpt-caret'); ?>
            </button>
            <div class="rpt-dropdown-panel rpt-calendar-panel" data-rpt-panel="calendar" hidden>
                <div class="rpt-calendar" data-rpt-calendar
                     data-start="<?php echo htmlspecialchars($start, ENT_QUOTES, 'UTF-8'); ?>"
                     data-end="<?php echo htmlspecialchars($end, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="rpt-cal-header">
                        <button type="button" class="rpt-cal-nav" data-rpt-cal-prev aria-label="Previous month"><?php echo rpt_icon('chevron-left', 'icon-sm'); ?></button>
                        <span class="rpt-cal-title" data-rpt-cal-title></span>
                        <button type="button" class="rpt-cal-nav" data-rpt-cal-next aria-label="Next month"><?php echo icon('chevron-right', 'icon-sm'); ?></button>
                    </div>
                    <div class="rpt-cal-weekdays">
                        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                    </div>
                    <div class="rpt-cal-grid" data-rpt-cal-grid></div>
                </div>
                <div class="rpt-cal-footer">
                    <span class="rpt-cal-range" data-rpt-cal-range>Pick a start and end date</span>
                    <div class="rpt-cal-actions">
                        <button type="button" class="btn btn-secondary btn-sm" data-rpt-cal-cancel>Cancel</button>
                        <button type="button" class="btn btn-primary btn-sm" data-rpt-cal-apply data-rpt-report="<?php echo htmlspecialchars($reportSlug, ENT_QUOTES, 'UTF-8'); ?>">Apply</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="rpt-dropdown-wrap">
            <button type="button" class="btn btn-primary rpt-toolbar-btn" data-rpt-toggle="export">
                <?php echo icon('download', 'icon-sm'); ?>
                <span>Export</span>
                <?php echo rpt_icon('chevron-down', 'icon-sm rpt-caret'); ?>
            </button>
            <div class="rpt-dropdown-panel rpt-export-panel" data-rpt-panel="export" hidden>
                <a class="rpt-export-option" href="<?php echo htmlspecialchars($exportBase . '&format=pdf', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo icon('receipt', 'icon-sm'); ?> PDF
                </a>
                <a class="rpt-export-option" href="<?php echo htmlspecialchars($exportBase . '&format=xlsx', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo icon('clipboard', 'icon-sm'); ?> XLSX
                </a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * The "← Back to Reports Overview" + "Print" row that sits just under
 * the topbar on every sub-report page (Financial, Performance, Guest
 * Insights, Inventory) — small enough to not warrant its own file, but
 * factored out since all four pages render it identically.
 */
function render_reports_subheader(): string
{
    ob_start();
    ?>
    <div class="rpt-subheader">
        <a href="report.php" class="rpt-back-link">
            <?php echo rpt_icon('chevron-left', 'icon-sm'); ?> Back to Reports Overview
        </a>
        <button type="button" class="btn btn-secondary rpt-print-btn" onclick="window.print()">
            <?php echo rpt_icon('printer', 'icon-sm'); ?> Print
        </button>
    </div>
    <?php
    return ob_get_clean();
}
