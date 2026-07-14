<?php
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
