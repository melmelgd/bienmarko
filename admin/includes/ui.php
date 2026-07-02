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
