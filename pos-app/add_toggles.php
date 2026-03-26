<?php

// Simple script to run the feature toggles SQL
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

    // Read and execute add_feature_toggles.sql
    $toggles = file_get_contents('add_feature_toggles.sql');
    $pdo->exec($toggles);

    echo "Feature toggles added successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}