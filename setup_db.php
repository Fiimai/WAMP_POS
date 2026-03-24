<?php

// Simple script to run SQL files
$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbname = 'pos_db';

try {
    // Connect without database first
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname`");

    // Connect to the database
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Read and execute schema.sql
    $schema = file_get_contents('schema.sql');
    $pdo->exec($schema);

    // Read and execute seed.sql
    $seed = file_get_contents('seed.sql');
    $pdo->exec($seed);

    // Read and execute add_feature_toggles.sql
    $toggles = file_get_contents('add_feature_toggles.sql');
    $pdo->exec($toggles);

    echo "Database setup completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}