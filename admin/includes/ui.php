<?php
/**
 * ui.php
 * -----------------------------------------------------------------------
 * Small reusable render helpers for patterns that repeat across pages:
 * stat cards, empty-state panels, and empty data tables. Centralizing
 * these means every page's "no data yet" panel looks and behaves
 * identically instead of being hand re-typed (and risking drift) on
 * every page.
 *
 * These functions know nothing about navigation — same separation
 * principle as the sidebar: this file is content-helpers only.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/icons.php';

function render_stat_card(string $label, string $value, string $iconName, string $tint): void
{
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $safeTint = htmlspecialchars($tint, ENT_QUOTES, 'UTF-8');
    echo '<div class="stat-card">';
    echo '<div class="stat-card-top">';
    echo '<span class="stat-label">' . $safeLabel . '</span>';
    echo '<span class="stat-icon stat-icon--' . $safeTint . '">' . icon($iconName) . '</span>';
    echo '</div>';
    echo '<span class="stat-value">' . $safeValue . '</span>';
    echo '</div>';
}

function render_empty_state(string $iconName, string $title, string $message, ?string $actionLabel = null): void
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">' . icon($iconName, 'icon-lg') . '</div>';
    echo '<p class="empty-state-title">' . $safeTitle . '</p>';
    echo '<p class="empty-state-message">' . $safeMessage . '</p>';
    if ($actionLabel) {
        $safeAction = htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8');
        echo '<button type="button" class="btn btn-primary">' . icon('plus', 'icon-sm') . '<span>' . $safeAction . '</span></button>';
    }
    echo '</div>';
}

/** A standalone panel that just contains an empty state (Accommodations, Feedback, Backup). */
function render_empty_panel(string $iconName, string $title, string $message, ?string $actionLabel = null): void
{
    echo '<div class="panel">';
    render_empty_state($iconName, $title, $message, $actionLabel);
    echo '</div>';
}

/** A panel with a table header row + an empty state in place of rows (Bookings, Payments, Inventory, Expenses). */
function render_table_empty(array $columns, string $iconName, string $title, string $message): void
{
    echo '<div class="panel table-wrap"><div class="table-scroll">';
    echo '<table>';
    echo '<thead><tr>';
    foreach ($columns as $col) {
        echo '<th>' . htmlspecialchars($col, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead>';
    echo '<tbody><tr><td colspan="' . count($columns) . '">';
    render_empty_state($iconName, $title, $message);
    echo '</td></tr></tbody>';
    echo '</table>';
    echo '</div></div>';
}

/**
 * Renders a "Showing X–Y of Z" pagination bar with Prev/Next and a
 * compact page-number window (with ellipses once there are many pages).
 * Used below any table capped to a fixed page size — currently the
 * Dashboard's Recent Bookings table, and the Bookings and Payments
 * tables.
 *
 * Pure GET-link pagination (no JS/AJAX) so it fits the existing filter
 * pattern on those pages, which already reload via `window.location`
 * for search/status filters. $extraParams should carry any active
 * filters (e.g. ['status' => $filterStatus, 'search' => $filterSearch])
 * so paging keeps them; empty/null values are dropped automatically.
 *
 * @param string $baseUrl     Page filename to link to, e.g. 'bookings.php'.
 * @param int    $page        Current page (1-indexed).
 * @param int    $perPage     Rows shown per page.
 * @param int    $totalItems  Total matching rows across all pages.
 * @param array  $extraParams Other GET params to preserve on every link.
 * @param string $itemLabel   Singular noun for the "of Z <label>s" text.
 */
function render_pagination(string $baseUrl, int $page, int $perPage, int $totalItems, array $extraParams = [], string $itemLabel = 'item'): void
{
    if ($totalItems <= 0 || $perPage <= 0) {
        return;
    }

    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page       = max(1, min($page, $totalPages));

    $rangeStart = (($page - 1) * $perPage) + 1;
    $rangeEnd   = min($page * $perPage, $totalItems);

    $buildUrl = function (int $targetPage) use ($baseUrl, $extraParams): string {
        $query = [];
        foreach ($extraParams as $key => $val) {
            if ($val !== null && $val !== '') {
                $query[$key] = $val;
            }
        }
        $query['page'] = $targetPage;
        return htmlspecialchars($baseUrl . '?' . http_build_query($query), ENT_QUOTES, 'UTF-8');
    };

    // Compact page-number window: 1 … p-1 p p+1 … last (all pages shown
    // as-is when there are 7 or fewer, so short lists never show a "…").
    $numbers = [];
    if ($totalPages <= 7) {
        $numbers = range(1, $totalPages);
    } else {
        $keep = array_unique(array_filter(
            [1, 2, $page - 1, $page, $page + 1, $totalPages - 1, $totalPages],
            fn($n) => $n >= 1 && $n <= $totalPages
        ));
        sort($keep);
        $prev = 0;
        foreach ($keep as $n) {
            if ($prev && $n - $prev > 1) {
                $numbers[] = 0; // 0 marks a "…" gap
            }
            $numbers[] = $n;
            $prev = $n;
        }
    }

    $safeLabel = htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8');
    $plural    = $totalItems === 1 ? '' : 's';

    echo '<div class="table-pagination">';
    echo '<span class="pg-info">Showing ' . $rangeStart . '&ndash;' . $rangeEnd . ' of ' . $totalItems . ' ' . $safeLabel . $plural . '</span>';
    echo '<nav class="pg-controls" aria-label="Pagination">';

    if ($page > 1) {
        echo '<a class="pg-btn" href="' . $buildUrl($page - 1) . '" aria-label="Previous page">'
           . icon('chevron-right', 'icon-sm pg-chevron-prev') . '<span>Prev</span></a>';
    } else {
        echo '<span class="pg-btn is-disabled" aria-disabled="true">'
           . icon('chevron-right', 'icon-sm pg-chevron-prev') . '<span>Prev</span></span>';
    }

    echo '<div class="pg-pages">';
    foreach ($numbers as $n) {
        if ($n === 0) {
            echo '<span class="pg-ellipsis">&hellip;</span>';
        } elseif ($n === $page) {
            echo '<span class="pg-page is-active" aria-current="page">' . $n . '</span>';
        } else {
            echo '<a class="pg-page" href="' . $buildUrl($n) . '">' . $n . '</a>';
        }
    }
    echo '</div>';

    if ($page < $totalPages) {
        echo '<a class="pg-btn" href="' . $buildUrl($page + 1) . '" aria-label="Next page">'
           . '<span>Next</span>' . icon('chevron-right', 'icon-sm') . '</a>';
    } else {
        echo '<span class="pg-btn is-disabled" aria-disabled="true">'
           . '<span>Next</span>' . icon('chevron-right', 'icon-sm') . '</span>';
    }

    echo '</nav>';
    echo '</div>';
}
