<?php

declare(strict_types=1);

$rootConfigPath = dirname(__DIR__, 2) . '/config.php';

if (!is_file($rootConfigPath)) {
    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'pos_db',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ];
}

$rootConfig = require $rootConfigPath;

if (!is_array($rootConfig) || !isset($rootConfig['db']) || !is_array($rootConfig['db'])) {
    throw new RuntimeException('Invalid config.php format. Expected array with db key.');
}

return $rootConfig['db'];

