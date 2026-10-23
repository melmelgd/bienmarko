<?php
/**
 * icons.php
 * -----------------------------------------------------------------------
 * One small helper, icon($name, $class), that returns a self-contained
 * inline SVG icon. Used by the sidebar, topbar, and every page so the
 * project never depends on an external icon font/CDN to render correctly.
 *
 * Usage:  echo icon('calendar');
 *         echo icon('calendar', 'stat-icon');
 * -----------------------------------------------------------------------
 */

function icon_paths(): array
{
    return [
        'grid'          => '<rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect>',
        'calendar'      => '<rect x="3" y="4" width="18" height="17" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="16" y1="2" x2="16" y2="6"></line>',
        'calendar-check'=> '<rect x="3" y="4" width="18" height="17" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="16" y1="2" x2="16" y2="6"></line><polyline points="8.5 14.5 11 17 16 12"></polyline>',
        'receipt'       => '<path d="M6 3h12v16l-1.5-1-1.5 1-1.5-1-1.5 1-1.5-1-1.5 1-1.5-1-1.5 1V3z"></path><line x1="9" y1="8" x2="15" y2="8"></line><line x1="9" y1="12" x2="15" y2="12"></line>',
        'bed'           => '<path d="M3 18v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6"></path><path d="M3 13h18"></path><path d="M7 13V9.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V13"></path><path d="M13 13V9.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V13"></path><path d="M3 21v-3"></path><path d="M21 21v-3"></path>',
        'clipboard'     => '<rect x="5" y="4" width="14" height="17" rx="2"></rect><rect x="9" y="2" width="6" height="4" rx="1"></rect><line x1="8" y1="11" x2="16" y2="11"></line><line x1="8" y1="15" x2="16" y2="15"></line><line x1="8" y1="19" x2="13" y2="19"></line>',
        'trend-down'    => '<polyline points="3 7 10 14 14 10 21 17"></polyline><polyline points="21 10 21 17 14 17"></polyline>',
        'trend-up'      => '<polyline points="3 17 10 10 14 14 21 7"></polyline><polyline points="14 7 21 7 21 14"></polyline>',
        'bar-chart'     => '<line x1="4" y1="20" x2="20" y2="20"></line><rect x="6" y="12" width="3" height="8" rx="0.5"></rect><rect x="11" y="7" width="3" height="13" rx="0.5"></rect><rect x="16" y="15" width="3" height="5" rx="0.5"></rect>',
        'message'       => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>',
        'settings'      => '<line x1="4" y1="6" x2="20" y2="6"></line><circle cx="9" cy="6" r="2"></circle><line x1="4" y1="12" x2="20" y2="12"></line><circle cx="15" cy="12" r="2"></circle><line x1="4" y1="18" x2="20" y2="18"></line><circle cx="9" cy="18" r="2"></circle>',
        'help'          => '<circle cx="12" cy="12" r="9"></circle><path d="M9.5 9a2.5 2.5 0 0 1 4.8-1c0 1.5-2.3 2-2.3 3.5"></path><circle cx="12" cy="16.5" r="0.9" fill="currentColor" stroke="none"></circle>',
        'database'      => '<ellipse cx="12" cy="5" rx="8" ry="3"></ellipse><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"></path><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"></path>',
        'logout'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line>',
        'menu'          => '<line x1="4" y1="7" x2="20" y2="7"></line><line x1="4" y1="12" x2="20" y2="12"></line><line x1="4" y1="17" x2="20" y2="17"></line>',
        'close'         => '<line x1="6" y1="6" x2="18" y2="18"></line><line x1="18" y1="6" x2="6" y2="18"></line>',
        'bell'          => '<path d="M6 9a6 6 0 0 1 12 0c0 4 1.5 5.5 2 6.5H4c.5-1 2-2.5 2-6.5"></path><path d="M10 19a2 2 0 0 0 4 0"></path>',
        'plus'          => '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>',
        'wallet'        => '<path d="M3 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2"></path><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2H6a3 3 0 0 1-3-3"></path><circle cx="16.5" cy="13.5" r="1.3"></circle>',
        'moon'          => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"></path>',
        'pie-chart'     => '<circle cx="12" cy="12" r="9"></circle><line x1="12" y1="12" x2="12" y2="3"></line><line x1="12" y1="12" x2="19.5" y2="7.5"></line>',
        'inbox'         => '<polyline points="4 12 9 12 11 15 13 15 15 12 20 12"></polyline><path d="M5.5 6h13l1.5 6v6a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18v-6z"></path>',
        'boxes'         => '<rect x="3" y="3" width="8" height="8" rx="1"></rect><rect x="13" y="3" width="8" height="8" rx="1"></rect><rect x="8" y="13" width="8" height="8" rx="1"></rect>',
        'alert-triangle'=> '<path d="M12 3.5 21.5 20h-19z"></path><line x1="12" y1="9.5" x2="12" y2="14"></line><circle cx="12" cy="17" r="0.9" fill="currentColor" stroke="none"></circle>',
        'users'         => '<circle cx="9" cy="8" r="3.2"></circle><path d="M3.5 20a5.5 5.5 0 0 1 11 0"></path><circle cx="17" cy="9" r="2.6"></circle><path d="M15 20a4.5 4.5 0 0 1 6.5-4"></path>',
        'star'          => '<polygon points="12 2.5 14.9 9 22 9.8 16.8 14.5 18.2 21.5 12 17.8 5.8 21.5 7.2 14.5 2 9.8 9.1 9"></polygon>',
        'mail'          => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><polyline points="3.5 6 12 13 20.5 6"></polyline>',
        'phone'         => '<path d="M5 4h3.5l1.5 4.5-2 1.5a12 12 0 0 0 6 6l1.5-2L19 15.5V19a1.5 1.5 0 0 1-1.6 1.5A15.5 15.5 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4z"></path>',
        'map-pin'       => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"></path><circle cx="12" cy="9.5" r="2.3"></circle>',
        'save'          => '<path d="M5 4h11l3 3v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"></path><path d="M8 4v5h7V4"></path><line x1="8" y1="13" x2="16" y2="13"></line><line x1="8" y1="16.5" x2="16" y2="16.5"></line>',
        'download'      => '<path d="M12 3v12"></path><polyline points="7 11 12 16 17 11"></polyline><path d="M4.5 17v2.5A1.5 1.5 0 0 0 6 21h12a1.5 1.5 0 0 0 1.5-1.5V17"></path>',
        'upload'        => '<path d="M12 21V9"></path><polyline points="7 13 12 8 17 13"></polyline><path d="M4.5 17v2.5A1.5 1.5 0 0 0 6 21h12a1.5 1.5 0 0 0 1.5-1.5V17"></path>',
        'refresh'       => '<path d="M20 11A8 8 0 0 0 5.5 6.5L4 8"></path><polyline points="4 4 4 8 8 8"></polyline><path d="M4 13a8 8 0 0 0 14.5 4.5L20 16"></path><polyline points="20 20 20 16 16 16"></polyline>',
        'clock'         => '<circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15.5 14"></polyline>',
        'edit'          => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"></path>',
        'chevron-right' => '<polyline points="9 5 16 12 9 19"></polyline>',
        'check'         => '<polyline points="4 12.5 9.5 18 20 6"></polyline>',
        'trash'         => '<polyline points="4 7 20 7"></polyline><path d="M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7"></path><path d="M6.5 7 7.3 19.5A2 2 0 0 0 9.3 21h5.4a2 2 0 0 0 2-1.8L17.5 7"></path>',
        'eye'           => '<path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3"></circle>',
    ];
}

/**
 * Render an inline SVG icon by name.
 * Falls back to a plain circle (rather than fatally erroring) if an
 * unknown name is ever passed in, so a typo degrades gracefully.
 */
function icon(string $name, string $class = 'icon'): string
{
    /* The palm logo is a PNG, not an SVG path. An <img> can't live inside
       an <svg>: the browser ejects it and leaves an empty 20px <svg> beside
       it, which pushes the logo off-center. So return a plain <img> here. */
    if ($name === 'palm') {
        return '<img src="../logo/palm-tree.png" alt="Palm icon" width="24" height="24" '
             . 'style="display:block;width:24px;height:24px;object-fit:contain;">';
    }

    $paths = icon_paths();
    $inner = $paths[$name] ?? '<circle cx="12" cy="12" r="9"></circle>';
    $safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');

    return '<svg class="' . $safeClass . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . $inner . '</svg>';
}