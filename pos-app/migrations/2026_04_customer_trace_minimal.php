<?php

declare(strict_types=1);

/**
 * Migration: Add privacy-minimal customer trace fields to sales table
 * Version: 2026_04_001
 */

return [
    '2026_04_001_add_sales_customer_trace_fields' => function(PDO $pdo) {
        $pdo->exec("
            ALTER TABLE sales
            ADD COLUMN IF NOT EXISTS customer_name VARCHAR(80) NULL,
            ADD COLUMN IF NOT EXISTS customer_contact VARCHAR(120) NULL,
            ADD COLUMN IF NOT EXISTS delivery_note VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS customer_consent_at DATETIME NULL;
        ");
    },
];
