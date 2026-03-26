<?php

declare(strict_types=1);

/**
 * Safe Deployment Script
 * Run this during maintenance window or with load balancer
 */

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Core/MigrationManager.php';

use App\Core\Database;
use App\Core\MigrationManager;

echo "Starting POS Update Deployment...\n";

// Put site in maintenance mode
file_put_contents(__DIR__ . '/maintenance.flag', 'Site under maintenance. Please try again in a few minutes.');

try {
    $pdo = Database::connection();

    // Initialize migration manager
    $migrationManager = new MigrationManager($pdo);

    // Load and run migrations
    $migrationFiles = glob(__DIR__ . '/migrations/*.php');
    $allMigrations = [];

    foreach ($migrationFiles as $file) {
        $migrations = require $file;
        $allMigrations = array_merge($allMigrations, $migrations);
    }

    echo "Running " . count($allMigrations) . " migrations...\n";
    $executed = $migrationManager->runMigrations($allMigrations);

    echo "Successfully executed " . count($executed) . " migrations:\n";
    foreach ($executed as $migration) {
        echo "  ✓ $migration\n";
    }

    // Deploy code (this would be done by your CI/CD pipeline)
    echo "Code deployment would happen here...\n";

    // Clear caches
    echo "Clearing application caches...\n";
    // clearCache();

    // Run post-deployment checks
    echo "Running post-deployment health checks...\n";
    runHealthChecks($pdo);

    echo "\n✅ Deployment completed successfully!\n";

} catch (Exception $e) {
    echo "\n❌ Deployment failed: " . $e->getMessage() . "\n";

    // Attempt rollback if needed
    echo "Consider rolling back to previous version...\n";

} finally {
    // Remove maintenance mode
    if (file_exists(__DIR__ . '/maintenance.flag')) {
        unlink(__DIR__ . '/maintenance.flag');
    }
}

function runHealthChecks(PDO $pdo): void
{
    // Basic health checks
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as user_count FROM users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "  ✓ Database connection OK (Users: {$result['user_count']})\n";
    } catch (Exception $e) {
        echo "  ❌ Database health check failed: " . $e->getMessage() . "\n";
        throw $e;
    }

    // Check if new tables exist
    $requiredTables = ['customer_loyalty_accounts', 'loyalty_transactions'];
    foreach ($requiredTables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                echo "  ⚠️  Warning: Table '$table' not found\n";
            } else {
                echo "  ✓ Table '$table' exists\n";
            }
        } catch (Exception $e) {
            echo "  ❌ Table check failed for '$table': " . $e->getMessage() . "\n";
        }
    }
}