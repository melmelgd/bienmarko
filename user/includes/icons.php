<?php
/**
 * Single source of truth for icon FILE PATHS (not markup).
 *
 * Every icon is a plain file in assets/icons/. To use your own icon set,
 * just drop a replacement file at the same path — nothing else in the
 * project needs to change. To use a different filename or format (.png,
 * .webp, etc.), edit the path on the matching line below.
 *
 * NOTE on color: because icons now render as <img> tags, the browser
 * treats each one as an opaque image — page CSS can no longer reach in
 * and recolor it (the old inline-SVG version could, via currentColor).
 * The starter icons are baked in a light, neutral color (#F3F0E8) that
 * reads on the dark theme everywhere. Active/hover states are shown with
 * background tints, borders, and glows instead of glyph-color changes.
 * If you swap in your own icons, keep them a light/neutral color for the
 * same reason (or supply light + accent variants and swap the path
 * conditionally, if you want a color-changing icon for a specific state).
 */

$iconLibrary = [
    'house'            => 'assets/icons/house.svg',
    'layout-dashboard' => 'assets/icons/layout-dashboard.svg',
    'calendar-check'   => 'assets/icons/calendar-check.svg',
    'file-text'        => 'assets/icons/file-text.svg',
    'message-square'   => 'assets/icons/message-square.svg',
    'circle-help'      => 'assets/icons/circle-help.svg',
    'bell'             => 'assets/icons/bell.svg',
    'user'             => 'assets/icons/user.svg',
    'log-out'          => 'assets/icons/log-out.svg',
    'calendar-plus'    => 'assets/icons/calendar-plus.svg',
    'wallet'           => 'assets/icons/wallet.svg',
    'circle-check'     => 'assets/icons/circle-check.svg',
    'bed-double'       => 'assets/icons/bed-double.svg',
    'life-buoy'        => 'assets/icons/life-buoy.svg',
    'pencil'           => 'assets/icons/pencil.svg',
    'star'             => 'assets/icons/star.svg',
    'send'             => 'assets/icons/send.svg',
    'inbox'            => 'assets/icons/inbox.svg',
    'x'                => 'assets/icons/x.svg',
    'phone'            => 'assets/icons/phone.svg',
    'mail'             => 'assets/icons/mail.svg',
    'chevron-down'     => 'assets/icons/chevron-down.svg',
];

/**
 * Outputs an <img> tag for the given icon key.
 *
 * @param string $name Icon key from $iconLibrary above.
 * @param string $alt  Accessible text. Leave blank (default) when the icon
 *                     sits next to a visible label or its button already
 *                     has an aria-label — that's true everywhere in this
 *                     project, so every call site can omit this safely.
 */
function render_icon(string $name, string $alt = ''): void
{
    global $iconLibrary;
    $src = $iconLibrary[$name] ?? $iconLibrary['circle-help'];
    printf(
        '<img class="icon" src="%s" alt="%s">',
        htmlspecialchars($src),
        htmlspecialchars($alt)
    );
}
