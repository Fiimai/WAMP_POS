<?php

declare(strict_types=1);

/**
 * Migration: Add loyalty program features
 * Version: 2024_03_001
 */

return [
    '2024_03_001_add_loyalty_tables' => function(PDO $pdo) {
        // Create loyalty program tables
        $pdo->exec("
            CREATE TABLE customer_loyalty_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NULL,
                phone VARCHAR(20) NOT NULL,
                email VARCHAR(190) NULL,
                points_balance INT NOT NULL DEFAULT 0,
                total_points_earned INT NOT NULL DEFAULT 0,
                tier ENUM('bronze', 'silver', 'gold', 'platinum') NOT NULL DEFAULT 'bronze',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_loyalty_phone (phone),
                UNIQUE KEY uq_loyalty_email (email),
                KEY idx_loyalty_tier_active (tier, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE loyalty_transactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                account_id BIGINT UNSIGNED NOT NULL,
                transaction_type ENUM('earn', 'redeem', 'expire', 'adjustment') NOT NULL,
                points INT NOT NULL,
                sale_id BIGINT UNSIGNED NULL,
                description VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_loyalty_tx_account_date (account_id, created_at),
                KEY idx_loyalty_tx_sale (sale_id),
                CONSTRAINT fk_loyalty_tx_account
                    FOREIGN KEY (account_id) REFERENCES customer_loyalty_accounts(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_loyalty_tx_sale
                    FOREIGN KEY (sale_id) REFERENCES sales(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    },

    '2024_03_002_add_loyalty_settings' => "
        ALTER TABLE shop_settings
        ADD COLUMN enable_loyalty_program TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN loyalty_points_per_dollar DECIMAL(5,2) NOT NULL DEFAULT 1.00,
        ADD COLUMN loyalty_redemption_rate DECIMAL(5,2) NOT NULL DEFAULT 0.01;
    ",

    '2024_03_003_populate_existing_customers' => function(PDO $pdo) {
        // Optional: Migrate existing customer data if you have customer tables
        // This is a placeholder for data migration logic
        $pdo->exec("
            -- Example: Create loyalty accounts for existing customers
            -- INSERT INTO customer_loyalty_accounts (phone, email, points_balance)
            -- SELECT phone, email, 0 FROM customers WHERE phone IS NOT NULL;
        ");
    }
];