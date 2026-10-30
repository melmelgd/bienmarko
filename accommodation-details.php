<?php
/**
 * accommodation-details.php  —  place in the project root, next to index.html
 *
 * Details page shown when a guest clicks "Book / Inquire" on a card from
 * rooms.html, cottages.html, event-halls.html or pool.html.
 *
 *   accommodation-details.php?id=3&room=Barkada%20Room
 *
 * Looks the accommodation up by id (preferred) or by exact name, shows its
 * photos, price, capacity, description, amenities and house rules, lets the
 * visitor check dates against api/availability.php, and sends them on to
 * user/guest-book.php (or login / register) when they press "Book Now".
 *
 * Read-only apart from one defensive, additive column guard (bed_config),
 * following the same pattern guest-book.php uses for tour_filename.
 */

require_once __DIR__ . '/includes/db.php';

/* ── helpers ──────────────────────────────────────────────────────────── */

function ad_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Short plain-text excerpt for the meta description (works with or without mbstring). */
function ad_excerpt(string $text, int $max = 150): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8') > $max ? rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…' : $text;
    }
    return strlen($text) > $max ? rtrim(substr($text, 0, $max - 1)) . '…' : $text;
}

/** Mirrors bm_public_category() in api/accommodations.php so a card and its details page always agree. */
function ad_category(string $type): string
{
    $n = strtolower(trim($type));
    if (strpos($n, 'room') !== false) {
        return 'rooms';
    }
    if (strpos($n, 'event') !== false || strpos($n, 'hall') !== false) {
        return 'venues';
    }
    if (strpos($n, 'cottage') !== false || strpos($n, 'hut') !== false || strpos($n, 'nipa') !== false) {
        return 'cottage';
    }
    if (strpos($n, 'pool') !== false || strpos($n, 'swim') !== false) {
        return 'pool';
    }
    return 'other';
}

/** Same rule as bk_is_pool_type() / api/availability.php: only "Swimming Pool" is shared by guest count. */
function ad_is_pool_type(string $type): bool
{
    return strcasecmp(trim($type), 'Swimming Pool') === 0;
}

function ad_amenity_icon_name(string $label): string
{
    $l = strtolower($label);
    if (preg_match('/wi-?fi|internet/', $l))                                   return 'wifi';
    if (preg_match('/pool|swim/', $l))                                         return 'pool';
    if (preg_match('/kitchen|cook|stove|fridge|refrigerator/', $l))            return 'kitchen';
    if (preg_match('/house\s?keeping|clean|linen|towel/', $l))                 return 'sparkle';
    if (preg_match('/bath|shower|toilet|restroom|\bcr\b/', $l))                return 'bath';
    if (preg_match('/air[\s-]?con|air[\s-]?condition|a\/c|\bfan\b/', $l))      return 'snow';
    if (preg_match('/\btv\b|television|cable/', $l))                           return 'tv';
    if (preg_match('/park|garage/', $l))                                       return 'car';
    return 'check';
}

function ad_icon(string $name, int $size = 20): string
{
    static $paths = [
        'wifi'    => '<path d="M2 8.5a16 16 0 0 1 20 0"/><path d="M5.5 12a11 11 0 0 1 13 0"/><path d="M8.8 15.5a6.3 6.3 0 0 1 6.4 0"/><circle cx="12" cy="19" r="1"/>',
        'pool'    => '<path d="M2 17c1.5 1.3 3 1.3 4.5 0s3-1.3 4.5 0 3 1.3 4.5 0 3-1.3 4.5 0"/><path d="M2 21c1.5 1.3 3 1.3 4.5 0s3-1.3 4.5 0 3 1.3 4.5 0 3-1.3 4.5 0"/><path d="M7 13V6a2 2 0 0 1 4 0v7"/><path d="M12.5 9.5 16 6"/>',
        'kitchen' => '<path d="M5 3v8a2 2 0 0 0 4 0V3"/><path d="M7 3v18"/><path d="M17 21V3c-2 1-4 4-4 8 0 2 1.5 3 4 3"/>',
        'sparkle' => '<path d="M12 3l1.8 4.6L18.5 9l-4.7 1.4L12 15l-1.8-4.6L5.5 9l4.7-1.4z"/><path d="M19 15l.7 1.8L21.5 17.5l-1.8.7L19 20l-.7-1.8-1.8-.7 1.8-.7z"/>',
        'bath'    => '<path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z"/><path d="M6 12V6a2 2 0 0 1 3.5-1.3"/><path d="M7 19l-1 2M17 19l1 2"/>',
        'snow'    => '<path d="M12 2v20"/><path d="M4.9 6.5l14.2 11"/><path d="M4.9 17.5l14.2-11"/>',
        'tv'      => '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'car'     => '<path d="M5 17h14M6 17l1.5-6h9L18 17"/><circle cx="8" cy="17" r="1.5"/><circle cx="16" cy="17" r="1.5"/>',
        'check'   => '<polyline points="20 6 9 17 4 12"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/>',
        'mute'    => '<path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M17 9l6 6M23 9l-6 6"/>',
        'trash'   => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/><path d="M10 11v6M14 11v6"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.3c2.2.7 3.5 2.7 3.5 5.7"/>',
        'bed'     => '<path d="M3 18V7"/><path d="M3 14h18v4"/><path d="M21 14v-2a3 3 0 0 0-3-3h-7v5"/><circle cx="7" cy="11" r="1.5"/>',
        'home'    => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    ];
    $inner = $paths[$name] ?? $paths['check'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . $inner . '</svg>';
}

/* ── load the accommodation ───────────────────────────────────────────── */

$reqId   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$reqName = trim(strip_tags((string) ($_GET['room'] ?? '')));

$acc    = null;
$photos = [];

try {
    $pdo = bienmarko_db();

    // Defensive, additive: optional "bed configuration" line (e.g. "3 Bunk Beds & 1 Double Bed").
    // Safe to run repeatedly; silently skipped if the DB user lacks ALTER rights.
    try {
        $dbName   = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $colCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'accommodations' AND COLUMN_NAME = 'bed_config'"
        );
        $colCheck->execute(['db' => $dbName]);
        if ((int) $colCheck->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `accommodations` ADD COLUMN `bed_config` VARCHAR(160) NULL");
        }
    } catch (Throwable $e) {
        // best-effort only
    }

    if ($reqId && $reqId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM `accommodations` WHERE `id` = :id LIMIT 1');
        $stmt->execute(['id' => $reqId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$acc && $reqName !== '') {
        $stmt = $pdo->prepare('SELECT * FROM `accommodations` WHERE `name` = :name LIMIT 1');
        $stmt->execute(['name' => $reqName]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($acc) {
        // Cover photo first, then gallery photos, de-duplicated (same order as the booking flow).
        if (!empty($acc['image_filename'])) {
            $photos[] = (string) $acc['image_filename'];
        }
        try {
            $ph = $pdo->prepare(
                'SELECT `filename` FROM `accommodation_photos`
                 WHERE `accommodation_id` = :id ORDER BY `sort_order` ASC, `id` ASC'
            );
            $ph->execute(['id' => (int) $acc['id']]);
            foreach ($ph->fetchAll(PDO::FETCH_COLUMN) as $file) {
                if ($file !== '' && !in_array($file, $photos, true)) {
                    $photos[] = (string) $file;
                }
            }
        } catch (Throwable $e) {
            // gallery table may not exist yet — cover photo alone is fine
        }
    }
} catch (Throwable $e) {
    error_log('[bienmarko] accommodation-details: ' . $e->getMessage());
}

$year = date('Y');

/* ── not found ────────────────────────────────────────────────────────── */

$listingPages = [
    'rooms'   => ['rooms.html',       'All Rooms'],
    'venues'  => ['event-halls.html', 'All Event Halls'],
    'cottage' => ['cottages.html',    'All Cottages'],
    'pool'    => ['pool.html',        'Pool Access'],
    'other'   => ['index.html#accommodations', 'All Accommodations'],
];

if (!$acc) {
    http_response_code(404);
}

$name      = $acc ? (string) $acc['name'] : '';
$type      = $acc ? (string) ($acc['type'] ?? '') : '';
$category  = ad_category($type);
$isPool    = ad_is_pool_type($type);
$capacity  = $acc ? max(1, (int) ($acc['capacity'] ?? 1)) : 1;
$price     = $acc ? (float) ($acc['price'] ?? 0) : 0.0;
$priceUnit = $isPool ? 'person' : ($category === 'rooms' ? 'night' : 'day');
$status    = $acc ? strtolower((string) ($acc['status'] ?? 'available')) : '';
$blocked   = in_array($status, ['unavailable', 'maintenance'], true);
$bedConfig = $acc ? trim((string) ($acc['bed_config'] ?? '')) : '';
$roomNo    = $acc ? trim((string) ($acc['room_number'] ?? '')) : '';
$descr     = $acc ? trim((string) ($acc['description'] ?? '')) : '';

if ($category === 'rooms' && $roomNo !== '') {
    $eyebrow = preg_match('/^room/i', $roomNo) ? $roomNo : 'Room ' . $roomNo;
} else {
    $eyebrow = $type;
}

$amenities = [];
if ($acc && !empty($acc['amenities'])) {
    $decoded = json_decode((string) $acc['amenities'], true);
    if (is_array($decoded)) {
        $amenities = $decoded;
    } else {
        $amenities = array_map('trim', explode(',', (string) $acc['amenities']));
    }
    $amenities = array_values(array_filter($amenities, static function ($a) {
        return is_string($a) && trim($a) !== '';
    }));
}

if ($descr === '') {
    $defaults = [
        'rooms'   => 'A comfortable place to rest during your stay at Bien Marko\'s Pension House.',
        'cottage' => 'A peaceful cottage surrounded by the relaxing atmosphere of the property.',
        'venues'  => 'A spacious venue for your gathering, celebration, or special event.',
        'pool'    => 'Cool off and enjoy a relaxing day at the pool.',
        'other'   => 'Part of the Bien Marko\'s Pension House experience.',
    ];
    $descr = $defaults[$category];
}

$photoBase = 'admin/assets/uploads/accommodations/';
$photoUrls = array_map(static function ($f) use ($photoBase) {
    return $photoBase . rawurlencode($f);
}, $photos);

$tourFile = $acc ? trim((string) ($acc['tour_filename'] ?? '')) : '';
$tourUrl  = $tourFile !== '' ? 'admin/assets/uploads/tours/' . rawurlencode($tourFile) : '';

$listing    = $listingPages[$category];
$guestUrl   = 'user/guest-book.php?room=' . rawurlencode($name);
$bookUrl    = 'user/book.php?room=' . rawurlencode($name);   // registered users (reached through login)
$loginUrl   = 'login.php';
$priceText  = '₱' . number_format($price, 2);

$jsData = [
    'id'       => $acc ? (int) $acc['id'] : 0,
    'name'     => $name,
    'isPool'   => $isPool,
    'price'    => $price,
    'capacity' => $capacity,
    'unit'     => $priceUnit,
    'blocked'  => $blocked,
    'photos'   => $photoUrls,
    'guestUrl' => $guestUrl,
    'bookUrl'  => $bookUrl,
    'loginUrl' => $loginUrl,
    'tourUrl'  => $tourUrl,
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $acc ? 'Booking Details: ' . ad_e($name) . ' | ' : 'Not Found | ' ?>Bien Marko's Pension House</title>
<meta name="description" content="<?= $acc ? ad_e(ad_excerpt($descr)) : 'Accommodation not found.' ?>">
<link rel="icon" href="logo/palm-tree.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --ad-bg:#070707;
    --ad-panel:#121212;
    --ad-panel-2:#1a1a1a;
    --ad-line:rgba(255,255,255,.14);
    --ad-text:#f5f0e6;
    --ad-muted:#b9b09f;
    --ad-gold:#d9b56d;
    --ad-gold-fill:#c99b4c;
    --ad-gold-soft:rgba(201,155,76,.16);
    --ad-ok:#6fcf97;
    --ad-bad:#ff8a7a;
    --ad-serif:"Cormorant Garamond",Georgia,"Times New Roman",serif;
    --ad-sans:"Montserrat",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
}
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
html.ad-lock,html.ad-lock body{overflow:hidden}
body{margin:0;background:var(--ad-bg);color:var(--ad-text);font-family:var(--ad-sans);font-size:15px;line-height:1.6}
img{max-width:100%;display:block}
a{color:inherit}
button{font:inherit;color:inherit}
:focus-visible{outline:2px solid var(--ad-gold);outline-offset:2px}
[hidden]{display:none!important}

/* header */
.ad-nav{position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:68px;padding:8px 20px;background:rgba(7,7,7,.94);backdrop-filter:blur(8px);border-bottom:1px solid rgba(255,255,255,.18)}
.ad-logo{display:inline-flex;align-items:center;text-decoration:none;font-family:var(--ad-serif);font-weight:500;font-size:1.3rem;letter-spacing:.2em;text-transform:uppercase;color:var(--ad-gold);white-space:nowrap}
.ad-burger{display:inline-flex;flex-direction:column;justify-content:center;gap:5px;width:44px;height:44px;padding:0 11px;background:none;border:1px solid var(--ad-line);border-radius:6px;cursor:pointer}
.ad-burger span{display:block;height:2px;background:var(--ad-text);border-radius:2px}
.ad-links{display:none;position:absolute;top:100%;left:0;right:0;flex-direction:column;padding:6px 20px 20px;background:#0b0b0b;border-bottom:1px solid rgba(255,255,255,.18)}
.ad-links.is-open{display:flex}
.ad-menu{display:flex;flex-direction:column}
.ad-menu a,.ad-auth a{display:flex;align-items:center;min-height:48px;text-decoration:none;font-size:.8rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ad-text)}
.ad-menu a{border-bottom:1px solid var(--ad-line)}
.ad-menu a:hover,.ad-auth a:hover{color:var(--ad-gold)}
.ad-menu a.is-active{color:var(--ad-gold)}
.ad-auth{display:flex;flex-direction:column;margin-top:8px}
.ad-auth a.ad-signup{justify-content:center;margin-top:8px;background:var(--ad-gold-fill);color:#111;font-weight:700}
.ad-auth a.ad-signup:hover{color:#111;filter:brightness(1.08)}

/* layout */
.ad-main{width:100%;max-width:1080px;margin:0 auto;padding:16px 16px 56px}
.ad-crumbs{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:4px 0 14px;font-size:.78rem;color:var(--ad-muted)}
.ad-crumbs a{text-decoration:none}
.ad-crumbs a:hover{color:var(--ad-gold)}
.ad-crumbs [aria-current]{color:var(--ad-gold)}

.ad-hero{position:relative;overflow:hidden;border-radius:14px;border:1px solid var(--ad-line);background:var(--ad-panel);aspect-ratio:4/3}
.ad-hero-btn{display:block;width:100%;height:100%;padding:0;border:0;background:none;cursor:zoom-in}
.ad-hero img{width:100%;height:100%;object-fit:cover}
.ad-hero-count{position:absolute;right:12px;bottom:12px;display:inline-flex;align-items:center;min-height:36px;padding:0 14px;border:1px solid var(--ad-line);border-radius:999px;background:rgba(0,0,0,.7);font-size:.75rem;font-weight:600;cursor:pointer}
.ad-noimg{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;width:100%;height:100%;min-height:160px;color:var(--ad-muted);font-size:.8rem;background:var(--ad-panel)}

.ad-titlebar{display:flex;flex-direction:column;gap:14px;margin:18px 0 6px}
.ad-eyebrow{margin:0 0 2px;color:var(--ad-gold);font-size:.7rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
.ad-title{margin:0;font-family:var(--ad-serif);font-weight:600;font-size:2.1rem;line-height:1.1}
.ad-actions{display:flex;flex-wrap:wrap;gap:10px}
.ad-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:46px;padding:0 22px;border-radius:999px;border:1px solid var(--ad-text);background:transparent;color:var(--ad-text);font-size:.8rem;font-weight:700;letter-spacing:.04em;text-decoration:none;cursor:pointer;flex:1 1 150px}
.ad-btn:hover{border-color:var(--ad-gold);color:var(--ad-gold)}
.ad-btn--solid{background:var(--ad-text);color:#111}
.ad-btn--solid:hover{background:var(--ad-gold-fill);border-color:var(--ad-gold-fill);color:#111}
.ad-btn[disabled],.ad-btn[aria-disabled="true"]{opacity:.45;cursor:not-allowed;pointer-events:none}
.ad-pagelabel{margin:0 0 10px;color:var(--ad-gold);font-size:.7rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
.ad-views{display:flex;gap:8px;margin:0 0 10px}
.ad-view{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 20px;border:1px solid var(--ad-line);border-radius:999px;background:transparent;font-size:.78rem;font-weight:700;letter-spacing:.04em;cursor:pointer}
.ad-view[aria-pressed="true"]{background:var(--ad-gold-fill);border-color:var(--ad-gold-fill);color:#111}
.ad-photos{position:absolute;inset:0}
.ad-tour{position:absolute;inset:0;z-index:2;display:flex;background:#000}
.ad-tour iframe{flex:1;width:100%;height:100%;border:0;background:#000}
.ad-hint{margin:12px 0 0;color:var(--ad-muted);font-size:.8rem}
.ad-hint a{color:var(--ad-gold)}

.ad-notice{margin:14px 0 0;padding:12px 14px;border:1px solid var(--ad-bad);border-radius:10px;color:var(--ad-bad);font-size:.85rem}

.ad-facts{display:flex;flex-wrap:wrap;gap:8px 20px;margin:16px 0 0;padding:0;list-style:none;font-size:.88rem;font-weight:600}
.ad-facts li{display:inline-flex;align-items:center;gap:8px}
.ad-facts svg{color:var(--ad-gold)}
.ad-facts small{font-weight:500;color:var(--ad-muted)}

/* availability panel */
.ad-avail{margin-top:18px;padding:16px;border:1px solid var(--ad-line);border-radius:14px;background:var(--ad-panel)}
.ad-avail form{display:grid;grid-template-columns:1fr;gap:12px}
.ad-field label{display:block;margin-bottom:6px;color:var(--ad-muted);font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase}
.ad-field input{width:100%;min-height:46px;padding:0 12px;border:1px solid var(--ad-line);border-radius:10px;background:var(--ad-panel-2);color:var(--ad-text);font:inherit;color-scheme:dark}
.ad-result{margin:14px 0 0;font-size:.9rem;font-weight:600}
.ad-result[data-state="ok"]{color:var(--ad-ok)}
.ad-result[data-state="bad"]{color:var(--ad-bad)}
.ad-result-actions{margin-top:12px}
.ad-result-actions .ad-btn{flex:0 1 auto}

.ad-cols{display:grid;grid-template-columns:1fr;gap:32px;margin-top:28px}
.ad-desc{margin:0;font-family:var(--ad-serif);font-size:1.25rem;line-height:1.5;color:var(--ad-text)}
.ad-h{margin:0 0 14px;color:var(--ad-gold);font-size:.72rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
.ad-block{margin-top:30px}
.ad-block:first-child{margin-top:0}
.ad-list{display:grid;grid-template-columns:1fr;gap:12px;margin:0;padding:0;list-style:none}
.ad-list li{display:flex;align-items:center;gap:12px;font-size:.88rem}
.ad-ico{display:inline-flex;align-items:center;justify-content:center;flex:none;width:40px;height:40px;border-radius:50%;background:var(--ad-gold-soft);color:var(--ad-gold)}

.ad-thumbs{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.ad-thumb{padding:0;border:1px solid var(--ad-line);border-radius:12px;overflow:hidden;background:var(--ad-panel);aspect-ratio:1/1;cursor:zoom-in}
.ad-thumb img{width:100%;height:100%;object-fit:cover}
.ad-thumb:hover{border-color:var(--ad-gold)}

/* modal + lightbox */
.ad-overlay{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.82)}
.ad-close{position:absolute;top:8px;right:8px;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border:0;background:none;color:var(--ad-text);font-size:1.7rem;line-height:1;cursor:pointer}

.ad-lb{flex-direction:column;gap:12px;padding:12px}
.ad-lb img{max-width:100%;max-height:calc(100vh - 150px);object-fit:contain;border-radius:8px}
.ad-lb-bar{display:flex;align-items:center;gap:12px;color:var(--ad-muted);font-size:.8rem}
.ad-lb-btn{min-width:44px;min-height:44px;border:1px solid var(--ad-line);border-radius:999px;background:rgba(0,0,0,.6);cursor:pointer;font-size:1.2rem}
.ad-lb .ad-close{top:10px;right:10px;background:rgba(0,0,0,.6);border-radius:50%}

.ad-footer{display:flex;flex-direction:column;gap:6px;align-items:center;padding:20px 16px;border-top:1px solid var(--ad-line);color:var(--ad-muted);font-size:.78rem;text-align:center}
.ad-missing{padding:60px 0;text-align:center}
.ad-missing h1{font-family:var(--ad-serif);font-size:2rem;margin:0 0 10px}

/* "Book Now" choice popup: With Account / Without Account */
.ad-bn{background:rgba(0,0,0,.6);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);padding:max(16px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(16px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}
.ad-bn-card{position:relative;width:100%;max-width:420px;max-height:100%;overflow-y:auto;background:#000;color:#fff;border-radius:32px;padding:26px 28px 30px;box-shadow:0 24px 60px rgba(0,0,0,.5);animation:ad-bn-in .22s ease both}
@keyframes ad-bn-in{from{opacity:0;transform:translateY(14px) scale(.97)}to{opacity:1;transform:none}}
.ad-bn-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 26px}
.ad-bn-title{margin:0;font-family:var(--ad-sans);font-size:1.6rem;line-height:1.2;font-weight:700;color:#fff}
.ad-bn-x{flex:none;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;margin:-6px -8px -6px 0;padding:0;border:0;border-radius:50%;background:transparent;color:#fff;cursor:pointer;-webkit-tap-highlight-color:transparent}
.ad-bn-x:hover{background:rgba(255,255,255,.12)}
.ad-bn-options{display:grid;gap:12px}
.ad-bn-btn{display:flex;align-items:center;justify-content:center;gap:12px;width:100%;min-height:56px;padding:12px 20px;border:1px solid transparent;border-radius:999px;font-size:1.05rem;font-weight:500;line-height:1.2;text-decoration:none;color:#111;-webkit-tap-highlight-color:transparent;transition:transform .12s ease,filter .15s ease}
.ad-bn-btn svg{flex:none}
.ad-bn-btn:hover{filter:brightness(1.06)}
.ad-bn-btn:active{transform:scale(.98)}
.ad-bn-btn--account{background:#f2b100;border-color:rgba(0,0,0,.18)}
.ad-bn-btn--guest{background:#fff}
.ad-bn-x:focus-visible,.ad-bn-btn:focus-visible{outline:3px solid #fff;outline-offset:3px}
.ad-bn-btn--guest:focus-visible{outline-color:#f2b100}
.ad-bn-divider{height:1px;margin:22px 0 16px;background:rgba(255,255,255,.22)}
.ad-bn-note{max-width:270px;margin:0 auto;text-align:center;font-size:.875rem;line-height:1.45;font-weight:300;color:rgba(255,255,255,.88)}
@media (max-width:420px){.ad-bn-card{border-radius:28px;padding:22px 20px 26px}.ad-bn-title{font-size:1.5rem}.ad-bn-btn{font-size:1rem}}
/* ≥ 640px */
@media (min-width:640px){
    .ad-hero{aspect-ratio:16/8}
    .ad-title{font-size:2.6rem}
    .ad-titlebar{flex-direction:row;align-items:flex-end;justify-content:space-between}
    .ad-actions{flex-wrap:nowrap}
    .ad-btn{flex:0 0 auto}
    .ad-avail form{grid-template-columns:repeat(3,1fr) auto;align-items:end}
    .ad-list{grid-template-columns:repeat(2,1fr)}
    .ad-thumbs{grid-template-columns:repeat(4,1fr)}
}
/* ≥ 900px */
@media (min-width:900px){
    .ad-nav{position:relative;display:grid;grid-template-columns:1fr auto 1fr;gap:24px;min-height:112px;padding:0 clamp(32px,4.8vw,90px);background:transparent;backdrop-filter:none}
    .ad-logo{font-size:1.75rem}
    .ad-burger{display:none}
    .ad-links{display:contents}
    .ad-menu{flex-direction:row;align-items:center;gap:clamp(24px,2.7vw,50px)}
    .ad-menu a{min-height:0;border:0}
    .ad-auth{flex-direction:row;align-items:center;justify-self:end;gap:clamp(20px,2vw,34px);margin:0}
    .ad-auth a{min-height:0}
    .ad-auth a.ad-signup{margin:0;min-height:61px;padding:0 32px}
    .ad-main{padding:24px 32px 72px}
    .ad-cols{grid-template-columns:1.7fr 1fr;gap:48px}
    .ad-list.ad-list--single{grid-template-columns:1fr}
    .ad-footer{flex-direction:row;justify-content:space-between;padding:20px 32px}
}
@media (prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important;animation:none!important}}
</style>
</head>
<body>

<header class="ad-nav">
    <a href="index.html#home" class="ad-logo" aria-label="Bien Marko's Pension House home">Bien Marko's</a>
    <button class="ad-burger" id="adBurger" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="adLinks">
        <span></span><span></span><span></span>
    </button>
    <nav class="ad-links" id="adLinks" aria-label="Primary navigation">
        <div class="ad-menu">
            <a href="index.html#home">Home</a>
            <a href="index.html#accommodations" class="is-active" aria-current="page">Accommodation</a>
            <a href="index.html#about">About</a>
            <a href="index.html#contact">Contact</a>
        </div>
        <div class="ad-auth">
            <a href="login.php">Log in</a>
            <a href="register.php" class="ad-signup">Sign up</a>
        </div>
    </nav>
</header>

<main class="ad-main">
<?php if (!$acc): ?>
    <section class="ad-missing">
        <h1>Accommodation not found</h1>
        <p>We couldn't find the accommodation you selected. It may have been removed or renamed.</p>
        <p><a class="ad-btn ad-btn--solid" href="index.html#accommodations">Back to accommodations</a></p>
    </section>
<?php else: ?>

    <nav class="ad-crumbs" aria-label="Breadcrumb">
        <a href="<?= ad_e($listing[0]) ?>"><?= ad_e($listing[1]) ?></a>
        <span aria-hidden="true">›</span>
        <span aria-current="page"><?= ad_e($name) ?></span>
    </nav>

    <p class="ad-pagelabel">Booking Details</p>

    <?php if ($tourUrl): ?>
    <div class="ad-views" role="group" aria-label="Choose view">
        <button class="ad-view" type="button" data-view="photos" aria-pressed="true">Photos</button>
        <button class="ad-view" type="button" data-view="tour" aria-pressed="false">360&deg; Tour</button>
    </div>
    <?php endif; ?>

    <section class="ad-hero" aria-label="Photos and tour of <?= ad_e($name) ?>">
        <div class="ad-photos" id="adPhotos">
        <?php if ($photoUrls): ?>
            <button class="ad-hero-btn" type="button" data-photo="0" aria-label="View photos of <?= ad_e($name) ?>">
                <img src="<?= ad_e($photoUrls[0]) ?>" alt="<?= ad_e($name) ?>" data-fallback>
            </button>
            <?php if (count($photoUrls) > 1): ?>
                <button class="ad-hero-count" type="button" data-photo="0">View all <?= count($photoUrls) ?> photos</button>
            <?php endif; ?>
        <?php else: ?>
            <div class="ad-noimg"><?= ad_icon('home', 40) ?><span>No photo available yet</span></div>
        <?php endif; ?>
        </div>
        <?php if ($tourUrl): ?>
        <div class="ad-tour" id="adTour" hidden>
            <iframe id="adTourFrame" src="about:blank" title="360 degree tour of <?= ad_e($name) ?>" loading="lazy" allow="accelerometer; gyroscope; fullscreen" allowfullscreen sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
            <button class="ad-hero-count" id="adTourFs" type="button">Full screen</button>
        </div>
        <?php endif; ?>
    </section>

    <div class="ad-titlebar">
        <div>
            <?php if ($eyebrow !== ''): ?><p class="ad-eyebrow"><?= ad_e($eyebrow) ?></p><?php endif; ?>
            <h1 class="ad-title"><?= ad_e($name) ?></h1>
        </div>
        <div class="ad-actions">
            <button class="ad-btn" id="adCheckBtn" type="button" aria-expanded="false" aria-controls="adAvail"<?= $blocked ? ' disabled' : '' ?>>Check Availability</button>
            <a class="ad-btn ad-btn--solid" href="<?= ad_e($guestUrl) ?>" data-book-link<?= $blocked ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Book Now</a>
        </div>
    </div>

    <p class="ad-hint">Have an account? <a href="login.php">Log in</a> or <a href="register.php">create one</a> to manage your bookings later.</p>

    <?php if ($blocked): ?>
        <p class="ad-notice" role="status">
            This accommodation is currently <?= $status === 'maintenance' ? 'under maintenance' : 'unavailable' ?>. Please check back soon or contact us for assistance.
        </p>
    <?php endif; ?>

    <ul class="ad-facts">
        <li><strong><?= ad_e($priceText) ?></strong> <small>/ <?= ad_e($priceUnit) ?></small></li>
        <li><?= ad_icon('users', 18) ?> <?= $capacity ?> Guest<?= $capacity === 1 ? '' : 's' ?></li>
        <?php if ($bedConfig !== ''): ?>
            <li><?= ad_icon('bed', 18) ?> <?= ad_e($bedConfig) ?></li>
        <?php endif; ?>
    </ul>

    <section class="ad-avail" id="adAvail" hidden aria-label="Check availability">
        <form id="adAvailForm" novalidate>
            <div class="ad-field">
                <label for="adIn"><?= $isPool ? 'Date' : 'Check-in' ?></label>
                <input type="date" id="adIn" required>
            </div>
            <div class="ad-field">
                <label for="adOut"><?= $isPool ? 'Until' : 'Check-out' ?></label>
                <input type="date" id="adOut" required>
            </div>
            <div class="ad-field"<?= $isPool ? '' : ' hidden' ?> id="adGuestsWrap">
                <label for="adGuests">Guests</label>
                <input type="number" id="adGuests" min="1" max="<?= $capacity ?>" value="1" inputmode="numeric">
            </div>
            <button class="ad-btn ad-btn--solid" type="submit">Check</button>
        </form>
        <p class="ad-result" id="adResult" role="status" aria-live="polite"></p>
        <div class="ad-result-actions" id="adResultActions" hidden>
            <a class="ad-btn ad-btn--solid" href="<?= ad_e($guestUrl) ?>" data-book-link>Continue to Booking</a>
        </div>
    </section>

    <div class="ad-cols">
        <div>
            <div class="ad-block">
                <p class="ad-desc"><?= nl2br(ad_e($descr)) ?></p>
            </div>

            <?php if ($amenities): ?>
            <div class="ad-block">
                <h2 class="ad-h">Amenities</h2>
                <ul class="ad-list">
                    <?php foreach ($amenities as $a): ?>
                        <li><span class="ad-ico"><?= ad_icon(ad_amenity_icon_name((string) $a), 18) ?></span><?= ad_e($a) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (count($photoUrls) > 1): ?>
            <div class="ad-block">
                <h2 class="ad-h">More Photos</h2>
                <div class="ad-thumbs">
                    <?php foreach ($photoUrls as $i => $url): if ($i === 0) { continue; } ?>
                        <button class="ad-thumb" type="button" data-photo="<?= (int) $i ?>" aria-label="Open photo <?= (int) $i + 1 ?> of <?= count($photoUrls) ?>">
                            <img src="<?= ad_e($url) ?>" alt="<?= ad_e($name) ?> photo <?= (int) $i + 1 ?>" loading="lazy" data-fallback>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <aside>
            <h2 class="ad-h">House Rules</h2>
            <ul class="ad-list ad-list--single">
                <?php if ($category === 'rooms'): ?>
                    <li><span class="ad-ico"><?= ad_icon('clock', 18) ?></span>Check-in time: 2:00 PM</li>
                    <li><span class="ad-ico"><?= ad_icon('clock', 18) ?></span>Check-out time: 12:00 PM</li>
                <?php endif; ?>
                <li><span class="ad-ico"><?= ad_icon('mute', 18) ?></span>No Loud Voices</li>
                <li><span class="ad-ico"><?= ad_icon('trash', 18) ?></span>Keep the Area Clean</li>
                <li><span class="ad-ico"><?= ad_icon('clock', 18) ?></span>Curfew 11:00 PM</li>
            </ul>
        </aside>
    </div>

<?php endif; ?>
</main>

<footer class="ad-footer">
    <span>© <?= $year ?> Bien Marko's Pension House. All rights reserved.</span>
    <a href="index.html#accommodations">Back to accommodations</a>
</footer>

<?php if ($acc): ?>
<!-- Photo lightbox -->
<div class="ad-overlay ad-lb" id="adLightbox" hidden role="dialog" aria-modal="true" aria-label="Photo viewer">
    <button class="ad-close" type="button" data-close-modal aria-label="Close photos">&times;</button>
    <img id="adLbImg" src="" alt="">
    <div class="ad-lb-bar">
        <button class="ad-lb-btn" type="button" id="adLbPrev" aria-label="Previous photo">&#8249;</button>
        <span id="adLbCount"></span>
        <button class="ad-lb-btn" type="button" id="adLbNext" aria-label="Next photo">&#8250;</button>
    </div>
</div>

<!-- Book Now popup: choose to book with or without an account -->
<div class="ad-overlay ad-bn" id="adBookModal" hidden role="dialog" aria-modal="true" aria-labelledby="adBookTitle">
    <div class="ad-bn-card">
        <div class="ad-bn-head">
            <h2 class="ad-bn-title" id="adBookTitle">Book Now</h2>
            <button class="ad-bn-x" type="button" data-close-modal aria-label="Close"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg></button>
        </div>
        <div class="ad-bn-options">
            <a class="ad-bn-btn ad-bn-btn--account" id="adBookAccount" href="<?= ad_e($loginUrl) ?>?next=<?= rawurlencode($bookUrl) ?>"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg><span>With Account</span></a>
            <a class="ad-bn-btn ad-bn-btn--guest" id="adBookGuest" href="<?= ad_e($guestUrl) ?>"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 21a8 8 0 0 1 11.873-7"></path><circle cx="10" cy="8" r="5"></circle><path d="m17 17 5 5"></path><path d="m22 17-5 5"></path></svg><span>Without Account</span></a>
        </div>
        <div class="ad-bn-divider"></div>
        <p class="ad-bn-note">Booking with an account allows you to manage your reservations easily</p>
    </div>
</div>

<script>
var AD = <?= json_encode($jsData, $jsonFlags) ?>;
</script>
<?php endif; ?>

<script>
(function () {
    'use strict';

    /* ── mobile menu ───────────────────────────────────────────────── */
    var burger = document.getElementById('adBurger');
    var links  = document.getElementById('adLinks');
    if (burger && links) {
        burger.addEventListener('click', function () {
            var open = links.classList.toggle('is-open');
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    if (typeof AD === 'undefined') { return; }

    /* ── helpers ───────────────────────────────────────────────────── */
    var root = document.documentElement;
    function $(id) { return document.getElementById(id); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parseISO(s) {
        var p = String(s || '').split('-');
        if (p.length !== 3) { return null; }
        var d = new Date(+p[0], +p[1] - 1, +p[2]);
        return isNaN(d.getTime()) ? null : d;
    }
    function money(n) {
        return '\u20B1' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /* broken photo → friendly placeholder */
    Array.prototype.forEach.call(document.querySelectorAll('img[data-fallback]'), function (img) {
        img.addEventListener('error', function () {
            var ph = document.createElement('div');
            ph.className = 'ad-noimg';
            ph.textContent = 'Photo unavailable';
            img.replaceWith(ph);
        }, { once: true });
    });

    /* ── overlays (modal + lightbox) ───────────────────────────────── */
    var lightbox  = $('adLightbox');
    var bookModal = $('adBookModal');
    var lastFocus = null;
    var pending   = null;   // dates confirmed by "Check Availability"

    function openOverlay(el) {
        lastFocus = document.activeElement;
        el.hidden = false;
        root.classList.add('ad-lock');
        var focusTarget = el.querySelector('a, button');
        if (focusTarget) { focusTarget.focus(); }
    }
    function closeOverlays() {
        [lightbox, bookModal].forEach(function (el) { if (el) { el.hidden = true; } });
        root.classList.remove('ad-lock');
        if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-close-modal]'), function (b) {
        b.addEventListener('click', closeOverlays);
    });
    [lightbox, bookModal].forEach(function (el) {
        if (!el) { return; }
        el.addEventListener('click', function (e) { if (e.target === el) { closeOverlays(); } });
    });

    /* Book Now / Continue to Booking open the "Book Now" popup (With Account /
       Without Account). Once dates are confirmed they are carried over in the URL. */
    function withDates(url) {
        if (pending) {
            url += (url.indexOf('?') === -1 ? '?' : '&') +
                'checkin=' + encodeURIComponent(pending.checkin) +
                '&checkout=' + encodeURIComponent(pending.checkout);
        }
        return url;
    }
    function updateBookLinks() {
        var url = withDates(AD.guestUrl);
        Array.prototype.forEach.call(document.querySelectorAll('[data-book-link]'), function (a) {
            a.setAttribute('href', url);
        });
    }
    function openBookModal() {
        if (!bookModal) { return; }
        var guestLink   = $('adBookGuest');
        var accountLink = $('adBookAccount');
        if (guestLink)   { guestLink.setAttribute('href', withDates(AD.guestUrl)); }
        if (accountLink) { accountLink.setAttribute('href', AD.loginUrl + '?next=' + encodeURIComponent(withDates(AD.bookUrl))); }
        openOverlay(bookModal);
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-book-link]'), function (a) {
        a.addEventListener('click', function (e) {
            if (AD.blocked) { e.preventDefault(); return; }
            // let ctrl/cmd/shift-click and middle-click behave normally
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button > 0) { return; }
            e.preventDefault();
            openBookModal();
        });
    });

    /* Photos / 360° tour switch (tour loads only when first opened) */
    var photosEl  = $('adPhotos');
    var tourEl    = $('adTour');
    var tourFrame = $('adTourFrame');
    var tourLoaded = false;
    function setView(view) {
        if (!tourEl) { return; }
        var tour = view === 'tour';
        tourEl.hidden = !tour;
        photosEl.hidden = tour;
        if (tour && !tourLoaded) { tourFrame.src = AD.tourUrl; tourLoaded = true; }
        Array.prototype.forEach.call(document.querySelectorAll('[data-view]'), function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-view') === view ? 'true' : 'false');
        });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-view]'), function (b) {
        b.addEventListener('click', function () { setView(b.getAttribute('data-view')); });
    });
    var fsBtn = $('adTourFs');
    if (fsBtn) {
        fsBtn.addEventListener('click', function () {
            if (tourEl.requestFullscreen) { tourEl.requestFullscreen(); }
            else { window.open(AD.tourUrl, '_blank', 'noopener'); }
        });
    }
    if (tourEl && !AD.photos.length) { setView('tour'); }

    /* lightbox */
    var lbIndex = 0;
    function showPhoto(i) {
        var n = AD.photos.length;
        if (!n) { return; }
        lbIndex = (i + n) % n;
        $('adLbImg').src = AD.photos[lbIndex];
        $('adLbImg').alt = AD.name + ' photo ' + (lbIndex + 1);
        $('adLbCount').textContent = (lbIndex + 1) + ' of ' + n;
        var multi = n > 1;
        $('adLbPrev').hidden = !multi;
        $('adLbNext').hidden = !multi;
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-photo]'), function (b) {
        b.addEventListener('click', function () {
            showPhoto(parseInt(b.getAttribute('data-photo'), 10) || 0);
            openOverlay(lightbox);
        });
    });
    $('adLbPrev').addEventListener('click', function () { showPhoto(lbIndex - 1); });
    $('adLbNext').addEventListener('click', function () { showPhoto(lbIndex + 1); });

    document.addEventListener('keydown', function (e) {
        var anyOpen = (lightbox && !lightbox.hidden) || (bookModal && !bookModal.hidden);
        if (!anyOpen) { return; }
        if (e.key === 'Escape') { closeOverlays(); }

        /* keep Tab focus inside the Book Now popup */
        if (e.key === 'Tab' && bookModal && !bookModal.hidden) {
            var items = bookModal.querySelectorAll('a[href], button');
            var first = items[0];
            var last  = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
        if (lightbox && !lightbox.hidden) {
            if (e.key === 'ArrowLeft')  { showPhoto(lbIndex - 1); }
            if (e.key === 'ArrowRight') { showPhoto(lbIndex + 1); }
        }
    });

    /* ── availability check ────────────────────────────────────────── */
    var panel    = $('adAvail');
    var checkBtn = $('adCheckBtn');
    var form     = $('adAvailForm');
    var inEl     = $('adIn');
    var outEl    = $('adOut');
    var guestsEl = $('adGuests');
    var result   = $('adResult');
    var actions  = $('adResultActions');

    function say(msg, state) {
        result.textContent = msg || '';
        if (state) { result.setAttribute('data-state', state); }
        else { result.removeAttribute('data-state'); }
    }
    function resetResult() {
        pending = null;
        updateBookLinks();
        actions.hidden = true;
        say('');
    }

    var today = new Date();
    today.setHours(0, 0, 0, 0);
    inEl.min = iso(today);
    outEl.min = iso(new Date(today.getTime() + 86400000));

    inEl.addEventListener('change', function () {
        var d = parseISO(inEl.value);
        if (d) {
            var next = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1);
            outEl.min = iso(next);
            if (!outEl.value || outEl.value <= inEl.value) { outEl.value = iso(next); }
        }
        resetResult();
    });
    outEl.addEventListener('change', resetResult);
    if (guestsEl) { guestsEl.addEventListener('input', resetResult); }

    if (checkBtn && panel) {
        checkBtn.addEventListener('click', function () {
            var open = panel.hidden;
            panel.hidden = !open;
            checkBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { inEl.focus(); }
        });
    }

    /* A night is taken when a booking covers it (check_in <= night < check_out).
       Pool: shared up to capacity by guest count. Everything else: exclusive. */
    function isRangeFree(checkin, checkout, guests, bookings, isPool) {
        var capacity = AD.capacity;
        var cur = parseISO(checkin);
        var end = parseISO(checkout);
        var guard = 0;
        while (cur < end && guard < 400) {
            var night = iso(cur);
            var used = 0;
            for (var i = 0; i < bookings.length; i++) {
                var b = bookings[i];
                if (b.check_in <= night && night < b.check_out) {
                    used += isPool ? (parseInt(b.guests_count, 10) || 1) : 1;
                }
            }
            if (isPool ? (used + guests) > capacity : used > 0) { return false; }
            cur.setDate(cur.getDate() + 1);
            guard++;
        }
        return true;
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            resetResult();

            var checkin  = inEl.value;
            var checkout = outEl.value;
            var guests   = AD.isPool ? (parseInt(guestsEl.value, 10) || 0) : 1;
            var a = parseISO(checkin);
            var b = parseISO(checkout);

            if (!a || !b) { say('Please choose both dates.', 'bad'); return; }
            if (a < today) { say('The first date cannot be in the past.', 'bad'); return; }
            if (b <= a) { say('The end date must be after the start date.', 'bad'); return; }
            if (AD.isPool && (guests < 1 || guests > AD.capacity)) {
                say('Guests must be between 1 and ' + AD.capacity + '.', 'bad');
                return;
            }

            say('Checking availability\u2026');
            fetch('api/availability.php?accommodation_id=' + encodeURIComponent(AD.id), {
                headers: { Accept: 'application/json' }
            })
                .then(function (r) {
                    if (!r.ok) { throw new Error('bad response'); }
                    return r.json();
                })
                .then(function (res) {
                    if (!res || !res.success) { throw new Error('unavailable'); }
                    var bookings = Array.isArray(res.bookings) ? res.bookings : [];
                    var apiPool  = !!(res.accommodation && res.accommodation.is_pool);
                    if (res.accommodation && res.accommodation.capacity) { AD.capacity = res.accommodation.capacity; }
                    if (isRangeFree(checkin, checkout, apiPool ? guests : 1, bookings, apiPool)) {
                        var nights = Math.round((b - a) / 86400000);
                        var total  = AD.isPool ? AD.price * guests : AD.price * nights;
                        var label  = AD.isPool
                            ? guests + ' guest' + (guests === 1 ? '' : 's')
                            : nights + ' ' + (AD.unit === 'night' ? 'night' : 'day') + (nights === 1 ? '' : 's');
                        pending = { checkin: checkin, checkout: checkout };
                        updateBookLinks();
                        say('Available for those dates \u00B7 ' + label + ' \u00B7 estimated ' + money(total), 'ok');
                        actions.hidden = false;
                    } else {
                        say('Sorry, those dates are not available. Please try different dates.', 'bad');
                    }
                })
                .catch(function () {
                    say('We could not check availability right now. Please try again or continue to booking.', 'bad');
                });
        });
    }
}());
</script>
</body>
</html>