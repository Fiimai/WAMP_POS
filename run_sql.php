<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Database;

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    $sql = file_get_contents(__DIR__ . '/add_feature_toggles.sql');

    $pdo->exec($sql);

    echo "Feature toggles added successfully to shop_settings table.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}