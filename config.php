<?php

declare(strict_types=1);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
$serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '');

$isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
    || str_ends_with($host, '.local')
    || in_array($serverAddr, ['127.0.0.1', '::1'], true);

$localDefaults = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'pos_db',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
];

$productionDefaults = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'pos_db',
    'user' => 'pos_user',
    'pass' => 'change_me',
    'charset' => 'utf8mb4',
];

$defaults = $isLocalhost ? $localDefaults : $productionDefaults;

$resolved = [
    'host' => getenv('DB_HOST') ?: $defaults['host'],
    'port' => (int) (getenv('DB_PORT') ?: $defaults['port']),
    'name' => getenv('DB_NAME') ?: $defaults['name'],
    'user' => getenv('DB_USER') ?: $defaults['user'],
    'pass' => getenv('DB_PASS') ?: $defaults['pass'],
    'charset' => getenv('DB_CHARSET') ?: $defaults['charset'],
];

if (!$isLocalhost) {
    $missing = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $required) {
        if (getenv($required) === false || getenv($required) === '') {
            $missing[] = $required;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException('Missing required production environment variables: ' . implode(', ', $missing));
    }

    if ($resolved['pass'] === 'change_me' || $resolved['user'] === 'pos_user') {
        throw new RuntimeException('Unsafe production database defaults detected. Set secure DB environment variables.');
    }
}

if (!defined('DB_HOST')) {
    define('DB_HOST', $resolved['host']);
}
if (!defined('DB_PORT')) {
    define('DB_PORT', (string) $resolved['port']);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $resolved['name']);
}
if (!defined('DB_USER')) {
    define('DB_USER', $resolved['user']);
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $resolved['pass']);
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', $resolved['charset']);
}

if ($isLocalhost) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

return ['db' => $resolved];

return [
    'environment' => [
        'is_localhost' => $isLocalhost,
    ],
    'db' => [
        'host' => $resolved['host'],
        'port' => $resolved['port'],
        'name' => $resolved['name'],
        'user' => $resolved['user'],
        'pass' => $resolved['pass'],
        'charset' => $resolved['charset'],
    ],
];

