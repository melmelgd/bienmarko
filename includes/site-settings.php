<?php
/**
 * includes/site-settings.php
 * -----------------------------------------------------------------------
 * ONE place that reads the values saved on Admin > Settings > General
 * (table `system_settings`, row id = 1) so every page of the system shows
 * the same property name, email, phone and address.
 *
 *   bienmarko_site_settings()  -> ['name','email','phone','address']
 *   bienmarko_site_name()      -> "Bien Marko's Pension House"
 *   bienmarko_site_brand()     -> ['short' => "Bien Marko's", 'rest' => 'Pension House', 'full' => ...]
 *   bienmarko_site_tel_href()  -> "+639175880942" (safe for tel: links)
 *   bienmarko_site_h()         -> htmlspecialchars shortcut
 *
 * If a value has not been saved yet (or the table doesn't exist), the
 * original defaults below are used, so nothing ever shows up blank.
 * The result is cached per request, so calling it many times is free.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/db.php';

if (!defined('BIENMARKO_DEFAULT_NAME')) {
    define('BIENMARKO_DEFAULT_NAME', "Bien Marko's Pension House");
}
if (!defined('BIENMARKO_DEFAULT_ADDRESS')) {
    define('BIENMARKO_DEFAULT_ADDRESS', '1st Ave Street, Poblacion, Tabango, Leyte');
}

/**
 * @return array{name:string,email:string,phone:string,address:string}
 */
function bienmarko_site_settings(?PDO $pdo = null, bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }

    $info = [
        'name'    => BIENMARKO_DEFAULT_NAME,
        'email'   => '',
        'phone'   => '',
        'address' => BIENMARKO_DEFAULT_ADDRESS,
    ];

    try {
        $pdo = $pdo ?: bienmarko_db();
        $row = $pdo->query(
            'SELECT `property_name`, `contact_email`, `contact_number`, `address`
               FROM `system_settings` WHERE `id` = 1'
        )->fetch(PDO::FETCH_ASSOC);

        if (is_array($row)) {
            $name = trim((string) $row['property_name']);
            if ($name !== '') {
                $info['name'] = $name;
            }
            $info['email'] = trim((string) $row['contact_email']);
            $info['phone'] = trim((string) $row['contact_number']);
            $address = trim((string) $row['address']);
            if ($address !== '') {
                $info['address'] = $address;
            }
        }
    } catch (Throwable $e) {
        // system_settings not created yet (Settings never opened) — defaults are fine
    }

    return $cache = $info;
}

function bienmarko_site_name(): string
{
    return bienmarko_site_settings()['name'];
}

/**
 * Splits the property name into a short brand + the rest, for the places
 * that style the two parts differently ("Bien Marko's" / "Pension House").
 * Recognises common suffixes (Pension House, Resort, Hotel, Inn, ...);
 * otherwise a multi-word name is split at its last space.
 *
 * @return array{short:string,rest:string,full:string}
 */
function bienmarko_site_brand(): array
{
    $full = bienmarko_site_name();

    if (preg_match(
        '/^(.+?)\s+((?:pension\s+house|guest\s*house|beach\s+resort|resort|hotel|inn|lodge|hostel|pension|villa|suites?|cottages?|homestay|apartelle)\b.*)$/iu',
        $full,
        $m
    )) {
        return ['short' => trim($m[1]), 'rest' => trim($m[2]), 'full' => $full];
    }

    $pos = mb_strrpos($full, ' ');
    if ($pos !== false && $pos > 0) {
        return [
            'short' => trim(mb_substr($full, 0, $pos)),
            'rest'  => trim(mb_substr($full, $pos + 1)),
            'full'  => $full,
        ];
    }

    return ['short' => $full, 'rest' => '', 'full' => $full];
}

/** Digits (and "+") only — safe inside href="tel:...". */
function bienmarko_site_tel_href(?string $phone = null): string
{
    $phone = $phone ?? bienmarko_site_settings()['phone'];
    return (string) preg_replace('/[^\d+]/', '', $phone);
}

/** Short HTML-escape used by pages that include this helper. */
function bienmarko_site_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
