<?php

// Detect environment
$host = $_SERVER['HTTP_HOST'] ?? '';

$isLocal =
    $host === 'localhost' ||
    str_starts_with($host, '127.0.0.1') ||
    str_starts_with($host, '192.168.') ||
    str_starts_with($host, '10.') ||
    str_starts_with($host, '172.16.');

// Local database
$local = [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'bienmarko',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
];

// Online database
$online = [
    'host'     => 'sql102.iceiy.cimy',
    'port'     => 3306,
    'database' => 'icei_42293432_bienmark',
    'username' => 'icei_42293432',
    'password' => '12345678',
    'charset'  => 'utf8mb4',
];

// Select correct configuration
return $isLocal ? $local : $online;