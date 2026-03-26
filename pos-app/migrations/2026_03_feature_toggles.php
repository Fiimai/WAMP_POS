<?php

declare(strict_types=1);

/**
 * Migration: Add feature toggle columns to shop_settings
 * Version: 2026_03_001
 */

return [
    '2026_03_001_add_feature_toggles' => function(PDO $pdo) {
        // Add feature toggle columns to shop_settings
        $pdo->exec("
            ALTER TABLE shop_settings
            ADD COLUMN IF NOT EXISTS enable_discounts TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS enable_returns TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS enable_multi_store TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS enable_time_clock TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS enable_email_notifications TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS smtp_host VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS smtp_port INT DEFAULT 587,
            ADD COLUMN IF NOT EXISTS smtp_username VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS smtp_password VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS smtp_encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
            ADD COLUMN IF NOT EXISTS email_from_address VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS email_from_name VARCHAR(255) NULL;
        ");
    }
];