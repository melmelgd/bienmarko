<?php
// Detect environment
$isLocal = in_array($_SERVER['HTTP_HOST'], ['localhost', '192.168.120.212']);

// LOCAL DATABASE CONFIG 
$local = [
    'host' => 'localhost',
    'name' => 'u951669574_ojt',
    'user' => 'root',
    'pass' => ''
];

// HOSTING DATABASE CONFIG
$online = [
    'host' => 'localhost',
    'name' => 'u951669574_ojt',
    'user' => 'u951669574_ojt',
    'pass' => 'j9@OiY$s:Q'
];

// Choose config
$db = $isLocal ? $local : $online;

try {
    $conn = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
?>
