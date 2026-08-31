<?php
/**
 * Shared UI helpers. Keeping these in one place means every "empty state"
 * or stat card looks and behaves identically everywhere it appears.
 */


function render_stat_card(array $card): void
{
    $variant = htmlspecialchars($card['variant'] ?? 'olive');
    ?>
    <div class="stat-card stat-card--<?php echo $variant; ?>">
        <span class="stat-card__icon"><?php render_icon($card['icon'] ?? 'circle-help'); ?></span>
        <p class="stat-card__label"><?php echo htmlspecialchars($card['label'] ?? ''); ?></p>
        <p class="stat-card__value"><?php echo htmlspecialchars($card['value'] ?? ''); ?></p>
        <p class="stat-card__meta"><?php echo htmlspecialchars($card['meta'] ?? ''); ?></p>
    </div>
    <?php
}

/**
 * Renders a consistent "nothing here yet" state with an optional call to action.
 * Empty states are written as an invitation to act, not an apology.
 */
function render_empty_state(string $icon, string $title, string $message, ?string $ctaLabel = null, ?string $ctaHref = null): void
{
    ?>
    <div class="empty-state">
        <span class="empty-state__icon"><?php render_icon($icon); ?></span>
        <h3 class="empty-state__title"><?php echo htmlspecialchars($title); ?></h3>
        <p class="empty-state__message"><?php echo htmlspecialchars($message); ?></p>
        <?php if ($ctaLabel && $ctaHref): ?>
            <a href="<?php echo htmlspecialchars($ctaHref); ?>" class="btn btn--primary"><?php echo htmlspecialchars($ctaLabel); ?></a>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Renders the title + subtitle block used at the top of secondary pages.
 * (Dashboard hand-rolls its own richer header because it also needs action buttons.)
 */
function page_head(string $title, ?string $subtitle = null): void
{
    ?>
    <div class="page-head__text">
        <h1 class="page-head__title"><?php echo htmlspecialchars($title); ?></h1>
        <?php if ($subtitle): ?>
            <p class="page-head__subtitle"><?php echo htmlspecialchars($subtitle); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Formats a MySQL DATETIME string as a short relative time ("2h ago",
 * "Yesterday", "3d ago") for compact display in the topbar notification
 * panel. Falls back to an absolute "Mon j" date once it's over a week
 * old. Used by user/includes/bootstrap.php when building $notifications.
 */
function bm_relative_time(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;

    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return (int) floor($diff / 60)   . 'm ago';
    if ($diff < 86400)  return (int) floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return (int) floor($diff / 86400) . 'd ago';

    return date('M j', $ts);
}
