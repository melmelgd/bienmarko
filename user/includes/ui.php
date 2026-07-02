<?php
/**
 * Shared UI helpers. Keeping these in one place means every "empty state"
 * or stat card looks and behaves identically everywhere it appears.
 */

/**
 * Renders one stat card.
 * Expected keys: variant, icon, label, value, meta.
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
