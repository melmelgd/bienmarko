<?php
/**
 * admin/includes/reports-styles.php
 * -----------------------------------------------------------------------
 * <style> block shared by report.php + the four report-*.php pages.
 * Pulled into its own include (rather than five copy-pasted <style>
 * blocks, the way most single pages in this project embed their own)
 * because these five pages are siblings that must look identical —
 * one shared block means the toolbar/calendar/stat-card styling can't
 * drift between them.
 *
 * Design note: unlike the rest of the (dark) admin theme, the mockups
 * for this section use light/pastel stat cards for key metrics (green
 * for revenue-positive numbers, red for costs, blue/olive for guest
 * counts) plus two light "highlight" panels (Top Returning Guests,
 * Most Used Items), while data tables and chart panels stay on the
 * standard dark panel background. That contrast is intentional and
 * reproduced here — it's a deliberate secondary treatment scoped only
 * to Reports & Analytics, not a change to the shared dark theme.
 * -----------------------------------------------------------------------
 */
?>
<style>
/* ===== Topbar toolbar (Week/Month/Year + Custom + Export) ===== */
.rpt-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.rpt-period-toggle { flex-shrink: 0; }
.rpt-period-toggle a.period-pill {
  display: inline-flex; align-items: center;
  border: none; background: none; color: var(--text-secondary);
  font-size: 0.85rem; font-weight: 600;
  padding: 8px 14px; border-radius: calc(var(--r-md) - 4px);
  transition: background var(--transition), color var(--transition);
  cursor: pointer;
}
.rpt-period-toggle a.period-pill:hover { color: var(--text); }
.rpt-period-toggle a.period-pill.is-active { background: var(--gold-500); color: var(--on-accent); }

.rpt-dropdown-wrap { position: relative; flex-shrink: 0; }
.rpt-toolbar-btn { padding: 10px 14px; font-size: 0.85rem; }
.rpt-toolbar-btn .rpt-caret { transition: transform var(--transition); }
.rpt-toolbar-btn[aria-expanded="true"] .rpt-caret { transform: rotate(180deg); }

.rpt-dropdown-panel {
  position: absolute; top: calc(100% + 8px); right: 0;
  background: var(--panel); border: 1px solid var(--border-strong);
  border-radius: var(--r-lg); box-shadow: 0 12px 32px rgba(0,0,0,0.45);
  z-index: 60; overflow: hidden;
}
.rpt-dropdown-panel[hidden] { display: none; }

/* ===== Export dropdown ===== */
.rpt-export-panel { min-width: 160px; padding: 6px; }
.rpt-export-option {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: var(--r-md);
  color: var(--text-secondary); font-size: 0.875rem; font-weight: 600;
  transition: background var(--transition), color var(--transition);
}
.rpt-export-option:hover { background: var(--panel-alt); color: var(--text); }

/* ===== Custom date-range calendar dropdown ===== */
.rpt-calendar-panel { width: 300px; padding: 16px; }
.rpt-cal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.rpt-cal-title { font-weight: 700; font-size: 0.95rem; }
.rpt-cal-nav {
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  background: var(--panel-alt); border: 1px solid var(--border); border-radius: var(--r-sm);
  color: var(--text-secondary);
}
.rpt-cal-nav:hover { color: var(--text); border-color: var(--border-strong); }
.rpt-cal-weekdays, .rpt-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.rpt-cal-weekdays { margin-bottom: 4px; }
.rpt-cal-weekdays span { text-align: center; font-size: 0.7rem; color: var(--text-tertiary); font-weight: 600; padding: 4px 0; }
.rpt-cal-day {
  aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
  background: none; border: none; border-radius: var(--r-sm);
  color: var(--text); font-size: 0.8rem;
  transition: background var(--transition), color var(--transition);
}
.rpt-cal-day:hover:not(.is-disabled) { background: var(--panel-alt); }
.rpt-cal-day.is-other-month { color: var(--text-muted); }
.rpt-cal-day.is-today { box-shadow: inset 0 0 0 1px var(--gold-600); }
.rpt-cal-day.is-in-range { background: var(--gold-tint-bg); color: var(--gold-300); border-radius: 0; }
.rpt-cal-day.is-range-start, .rpt-cal-day.is-range-end { background: var(--gold-500); color: var(--on-accent); font-weight: 700; }
.rpt-cal-day.is-range-start { border-radius: var(--r-sm) 0 0 var(--r-sm); }
.rpt-cal-day.is-range-end { border-radius: 0 var(--r-sm) var(--r-sm) 0; }
.rpt-cal-day.is-disabled { color: var(--text-muted); opacity: 0.4; cursor: default; }
.rpt-cal-footer { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.rpt-cal-range { display: block; font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 10px; }
.rpt-cal-actions { display: flex; justify-content: flex-end; gap: 8px; }
.rpt-cal-actions .btn-sm { padding: 7px 12px; font-size: 0.8rem; }

/* ===== Sub-header row: Back link + Print ===== */
.rpt-subheader { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.rpt-back-link {
  display: inline-flex; align-items: center; gap: 6px;
  color: var(--gold-400); font-weight: 600; font-size: 0.875rem;
}
.rpt-back-link:hover { color: var(--gold-300); }
.rpt-print-btn { padding: 9px 14px; font-size: 0.85rem; }

/* ===== Reports Overview cards ===== */
.rpt-overview-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.rpt-overview-card {
  display: flex; flex-direction: column; gap: 14px;
  transition: border-color var(--transition), transform var(--transition);
}
.rpt-overview-card:hover { border-color: var(--gold-600); transform: translateY(-1px); }
.rpt-overview-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.rpt-overview-card h3 { font-size: 1.05rem; font-weight: 700; margin-bottom: 4px; }
.rpt-overview-card p { color: var(--text-tertiary); font-size: 0.85rem; }
.rpt-overview-stats { display: flex; gap: 24px; flex-wrap: wrap; }
.rpt-overview-stat-label { display: block; font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 2px; }
.rpt-overview-stat-value { font-family: var(--font-display); font-size: 1.15rem; font-weight: 700; }
.rpt-overview-stat-value--green { color: var(--emerald); }
.rpt-overview-stat-value--red { color: var(--red); }
.rpt-overview-stat-value--blue { color: var(--blue); }
.rpt-overview-card .btn-text { margin-top: auto; }

/* ===== Pastel stat cards (used by all four sub-report pages) ===== */
.rpt-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
.rpt-stat-card {
  border-radius: var(--r-lg); padding: 18px 20px;
  display: flex; flex-direction: column; gap: 6px;
}
.rpt-stat-card-top { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; }
.rpt-stat-card-top .icon-sm { flex-shrink: 0; }
.rpt-stat-value { font-family: var(--font-display); font-size: 1.6rem; font-weight: 700; }
.rpt-stat-sub { font-size: 0.75rem; opacity: 0.75; font-weight: 500; }
.rpt-stat--green { background: #dcfce7; color: #14532d; }
.rpt-stat--green .rpt-stat-value { color: #15803d; }
.rpt-stat--red   { background: #fee2e2; color: #7f1d1d; }
.rpt-stat--red .rpt-stat-value { color: #dc2626; }
.rpt-stat--blue  { background: #dbeafe; color: #1e3a5f; }
.rpt-stat--blue .rpt-stat-value { color: #2563eb; }
.rpt-stat--rose  { background: #fce7f3; color: #831843; }
.rpt-stat--rose .rpt-stat-value { color: #db2777; }
.rpt-stat--olive { background: #e4e8c0; color: #3f4a1f; }
.rpt-stat--olive .rpt-stat-value { color: #4d5a24; }
.rpt-stat--lime  { background: #ecfccb; color: #365314; }
.rpt-stat--lime .rpt-stat-value { color: #4d7c0f; }

/* ===== Revenue / breakdown bars ===== */
.rpt-panel-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.rpt-bar-list { display: flex; flex-direction: column; gap: 14px; }
.rpt-bar-row-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 6px; font-size: 0.875rem; }
.rpt-bar-row-head .rpt-bar-label { color: var(--text-secondary); font-weight: 500; }
.rpt-bar-row-head .rpt-bar-amount { color: var(--text); font-weight: 700; }
.rpt-bar-track { height: 7px; border-radius: var(--r-full); background: var(--panel-alt); overflow: hidden; }
.rpt-bar-fill { height: 100%; border-radius: var(--r-full); background: var(--gold-500); }
.rpt-see-all-wrap { text-align: right; margin-top: 8px; }

/* ===== Payment collection status ===== */
.rpt-collect-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.rpt-collect-label { display: flex; align-items: center; gap: 8px; color: var(--text-secondary); font-size: 0.875rem; font-weight: 600; margin-bottom: 8px; }
.rpt-collect-amount { font-family: var(--font-display); font-size: 1.6rem; font-weight: 700; }
.rpt-collect-amount--paid { color: var(--gold-400); }
.rpt-collect-amount--pending { color: var(--blue); }
.rpt-collect-count { font-size: 0.8rem; color: var(--text-tertiary); margin-top: 2px; }

/* ===== Performance: seasonal trend chart ===== */
.rpt-chart-panel-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; align-items: start; }
.rpt-trend-svg-wrap { position: relative; }
.rpt-trend-tooltip {
  position: absolute; pointer-events: none;
  background: var(--bg-sidebar); border: 1px solid var(--border-strong);
  border-radius: var(--r-md); padding: 10px 12px;
  font-size: 0.78rem; line-height: 1.6; color: var(--text-secondary);
  box-shadow: 0 8px 24px rgba(0,0,0,0.5);
  opacity: 0; transition: opacity 120ms ease; z-index: 20; white-space: nowrap;
}
.rpt-trend-tooltip.is-visible { opacity: 1; }
.rpt-trend-tooltip strong { color: var(--text); display: block; margin-bottom: 4px; }
.rpt-trend-legend { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px; font-size: 0.78rem; color: var(--text-tertiary); }
.rpt-trend-legend span { display: inline-flex; align-items: center; gap: 6px; }
.rpt-trend-legend i { width: 10px; height: 10px; border-radius: var(--r-full); display: inline-block; }

/* ===== Performance rankings ===== */
.rpt-rank-list { display: flex; flex-direction: column; gap: 16px; }
.rpt-rank-row { display: flex; align-items: center; gap: 12px; }
.rpt-rank-badge {
  width: 26px; height: 26px; border-radius: var(--r-full); flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: var(--panel-alt); color: var(--text-secondary);
  font-size: 0.75rem; font-weight: 700;
}
.rpt-rank-row:nth-child(1) .rpt-rank-badge { background: var(--gold-500); color: var(--on-accent); }
.rpt-rank-body { flex: 1; min-width: 0; }
.rpt-rank-top { display: flex; align-items: baseline; justify-content: space-between; font-size: 0.875rem; margin-bottom: 4px; }
.rpt-rank-name { font-weight: 600; }
.rpt-rank-sub { color: var(--text-tertiary); font-size: 0.75rem; }
.rpt-rank-revenue { color: var(--gold-400); font-weight: 700; }

/* ===== Light "highlight" panels: Top Returning Guests / Most Used Items ===== */
.rpt-light-panel { background: #f8f7f2; border-color: #e5e3d8; color: #1c1c1e; }
.rpt-light-panel .panel-title { color: #1c1c1e; }
.rpt-light-panel table { color: #1c1c1e; }
.rpt-light-panel thead tr { border-bottom: 1px solid #e0ded2; }
.rpt-light-panel th { color: #6b6a60; }
.rpt-light-panel tbody tr { border-bottom: 1px solid #ece9dc; }
.rpt-light-panel tbody tr:last-child { border-bottom: none; }
.rpt-light-panel td { padding: 14px 20px; }
.rpt-guest-name { font-weight: 600; }
.rpt-guest-rating { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
.rpt-guest-rating .icon-sm { color: var(--gold-500); }
.rpt-guest-rating--none { color: #9a988c; font-weight: 500; }

.rpt-used-list { display: flex; flex-direction: column; gap: 16px; }
.rpt-used-row-head { display: flex; align-items: baseline; justify-content: space-between; font-size: 0.875rem; margin-bottom: 6px; }
.rpt-used-row-head .rpt-used-label { font-weight: 600; }
.rpt-used-row-head .rpt-used-pct { font-weight: 700; }
.rpt-light-panel .rpt-bar-track { background: #e7e5d8; }
.rpt-light-panel .rpt-bar-fill { background: var(--gold-500); }

/* ===== Inventory item status badges (reused look from admin/inventory.php) ===== */
.sb { font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: var(--r-full); white-space: nowrap; }
.sb--green { background: var(--emerald-bg); color: var(--emerald); }
.sb--amber { background: var(--amber-bg); color: var(--gold-400); }
.sb--red   { background: var(--red-bg); color: var(--red); }

/* ===== Responsive ===== */
@media (max-width: 1023px) {
  .rpt-overview-grid, .rpt-panel-grid-2, .rpt-chart-panel-grid, .rpt-collect-grid { grid-template-columns: 1fr; }
  .rpt-calendar-panel { right: -60px; }
}
@media (max-width: 639px) {
  .rpt-toolbar { width: 100%; }
  .rpt-period-toggle { width: 100%; }
  .rpt-toolbar-btn span { display: none; }
  .rpt-calendar-panel { position: fixed; top: auto; bottom: 0; left: 0; right: 0; width: 100%; border-radius: var(--r-lg) var(--r-lg) 0 0; }
}

/* ===== Print ===== */
@media print {
  .sidebar, .topbar, .rpt-toolbar, .rpt-subheader, .rpt-dropdown-panel { display: none !important; }
  .app-main { margin-left: 0 !important; }
  body, .content { background: #fff !important; color: #111 !important; }
  .panel, .rpt-stat-card { break-inside: avoid; border: 1px solid #ddd !important; background: #fff !important; box-shadow: none !important; }
  .rpt-stat--green, .rpt-stat--red, .rpt-stat--blue, .rpt-stat--rose, .rpt-stat--olive, .rpt-stat--lime { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
