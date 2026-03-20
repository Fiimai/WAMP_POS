<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class ShopSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $pdo = Database::connection();
        $statement = $pdo->query(
            'SELECT id, shop_name, shop_address, shop_phone, shop_tax_id, currency_code, currency_symbol,
                    tax_rate_percent, receipt_header, receipt_footer, theme_accent_primary, theme_accent_secondary
             FROM shop_settings
             WHERE id = 1
             LIMIT 1'
        );

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row;
        }

        return [
            'id' => 1,
            'shop_name' => 'My Shop',
            'shop_address' => '',
            'shop_phone' => '',
            'shop_tax_id' => '',
            'currency_code' => 'USD',
            'currency_symbol' => '$',
            'tax_rate_percent' => '8.00',
            'receipt_header' => 'Thank you for shopping with us',
            'receipt_footer' => 'No refunds without receipt',
            'theme_accent_primary' => '#06B6D4',
            'theme_accent_secondary' => '#22D3AA',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(array $data): void
    {
        $pdo = Database::connection();

        $sql = 'INSERT INTO shop_settings (
                    id, shop_name, shop_address, shop_phone, shop_tax_id,
                    currency_code, currency_symbol, tax_rate_percent,
                    receipt_header, receipt_footer,
                    theme_accent_primary, theme_accent_secondary
                ) VALUES (
                    1, :shop_name, :shop_address, :shop_phone, :shop_tax_id,
                    :currency_code, :currency_symbol, :tax_rate_percent,
                    :receipt_header, :receipt_footer,
                    :theme_accent_primary, :theme_accent_secondary
                )
                ON DUPLICATE KEY UPDATE
                    shop_name = VALUES(shop_name),
                    shop_address = VALUES(shop_address),
                    shop_phone = VALUES(shop_phone),
                    shop_tax_id = VALUES(shop_tax_id),
                    currency_code = VALUES(currency_code),
                    currency_symbol = VALUES(currency_symbol),
                    tax_rate_percent = VALUES(tax_rate_percent),
                    receipt_header = VALUES(receipt_header),
                    receipt_footer = VALUES(receipt_footer),
                    theme_accent_primary = VALUES(theme_accent_primary),
                    theme_accent_secondary = VALUES(theme_accent_secondary)';

        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':shop_name' => (string) $data['shop_name'],
            ':shop_address' => (string) ($data['shop_address'] ?? ''),
            ':shop_phone' => (string) ($data['shop_phone'] ?? ''),
            ':shop_tax_id' => (string) ($data['shop_tax_id'] ?? ''),
            ':currency_code' => strtoupper((string) $data['currency_code']),
            ':currency_symbol' => (string) $data['currency_symbol'],
            ':tax_rate_percent' => (float) $data['tax_rate_percent'],
            ':receipt_header' => (string) ($data['receipt_header'] ?? ''),
            ':receipt_footer' => (string) ($data['receipt_footer'] ?? ''),
            ':theme_accent_primary' => strtoupper((string) $data['theme_accent_primary']),
            ':theme_accent_secondary' => strtoupper((string) $data['theme_accent_secondary']),
        ]);
    }

    public static function taxRatePercent(): float
    {
        $settings = self::get();
        return (float) ($settings['tax_rate_percent'] ?? 8.0);
    }
}
