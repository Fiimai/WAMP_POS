<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class ShopSettings
{
    /**
     * @var array<string, bool>|null
     */
    private static ?array $columnMap = null;

    private static function hasColumn(string $column): bool
    {
        if (self::$columnMap !== null) {
            return self::$columnMap[$column] ?? false;
        }

        self::$columnMap = [];

        try {
            $pdo = Database::connection();
            $statement = $pdo->query('SHOW COLUMNS FROM shop_settings');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $name = (string) ($row['Field'] ?? '');
                if ($name !== '') {
                    self::$columnMap[$name] = true;
                }
            }
        } catch (\Throwable $throwable) {
            self::$columnMap = [];
        }

        return self::$columnMap[$column] ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $pdo = Database::connection();

        $selectColumns = [
            'id',
            'shop_name',
            'shop_address',
            'shop_phone',
            'shop_tax_id',
            'currency_code',
            'currency_symbol',
            'tax_rate_percent',
            'receipt_header',
            'receipt_footer',
            'theme_accent_primary',
            'theme_accent_secondary',
        ];

        if (self::hasColumn('shop_logo_url')) {
            $selectColumns[] = 'shop_logo_url';
        }

        if (self::hasColumn('business_tagline')) {
            $selectColumns[] = 'business_tagline';
        }

        $statement = $pdo->query(
            'SELECT ' . implode(', ', $selectColumns) . '
             FROM shop_settings
             WHERE id = 1
             LIMIT 1'
        );

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $row['shop_logo_url'] = (string) ($row['shop_logo_url'] ?? '');
            $row['business_tagline'] = (string) ($row['business_tagline'] ?? '');
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
            'shop_logo_url' => '',
            'business_tagline' => '',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(array $data): void
    {
        $pdo = Database::connection();

        $supportsLogo = self::hasColumn('shop_logo_url');
        $supportsTagline = self::hasColumn('business_tagline');

        $insertColumns = [
            'id', 'shop_name', 'shop_address', 'shop_phone', 'shop_tax_id',
            'currency_code', 'currency_symbol', 'tax_rate_percent',
            'receipt_header', 'receipt_footer',
            'theme_accent_primary', 'theme_accent_secondary',
        ];

        $valueColumns = [
            '1', ':shop_name', ':shop_address', ':shop_phone', ':shop_tax_id',
            ':currency_code', ':currency_symbol', ':tax_rate_percent',
            ':receipt_header', ':receipt_footer',
            ':theme_accent_primary', ':theme_accent_secondary',
        ];

        if ($supportsLogo) {
            $insertColumns[] = 'shop_logo_url';
            $valueColumns[] = ':shop_logo_url';
        }

        if ($supportsTagline) {
            $insertColumns[] = 'business_tagline';
            $valueColumns[] = ':business_tagline';
        }

        $updateColumns = [
            'shop_name',
            'shop_address',
            'shop_phone',
            'shop_tax_id',
            'currency_code',
            'currency_symbol',
            'tax_rate_percent',
            'receipt_header',
            'receipt_footer',
            'theme_accent_primary',
            'theme_accent_secondary',
        ];

        if ($supportsLogo) {
            $updateColumns[] = 'shop_logo_url';
        }

        if ($supportsTagline) {
            $updateColumns[] = 'business_tagline';
        }

        $assignments = [];
        foreach ($updateColumns as $column) {
            $assignments[] = $column . ' = VALUES(' . $column . ')';
        }

        $sql = 'INSERT INTO shop_settings (' . implode(', ', $insertColumns) . ')
                VALUES (' . implode(', ', $valueColumns) . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        $statement = $pdo->prepare($sql);
        $params = [
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
        ];

        if ($supportsLogo) {
            $params[':shop_logo_url'] = (string) ($data['shop_logo_url'] ?? '');
        }

        if ($supportsTagline) {
            $params[':business_tagline'] = (string) ($data['business_tagline'] ?? '');
        }

        $statement->execute($params);
    }

    public static function taxRatePercent(): float
    {
        $settings = self::get();
        return (float) ($settings['tax_rate_percent'] ?? 8.0);
    }
}

