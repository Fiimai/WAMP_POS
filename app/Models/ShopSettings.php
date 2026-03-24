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

        if (self::hasColumn('enable_discounts')) {
            $selectColumns[] = 'enable_discounts';
        }

        if (self::hasColumn('enable_returns')) {
            $selectColumns[] = 'enable_returns';
        }

        if (self::hasColumn('enable_multi_store')) {
            $selectColumns[] = 'enable_multi_store';
        }

        if (self::hasColumn('enable_time_clock')) {
            $selectColumns[] = 'enable_time_clock';
        }

        if (self::hasColumn('enable_email_notifications')) {
            $selectColumns[] = 'enable_email_notifications';
        }

        if (self::hasColumn('smtp_host')) {
            $selectColumns[] = 'smtp_host';
        }

        if (self::hasColumn('smtp_port')) {
            $selectColumns[] = 'smtp_port';
        }

        if (self::hasColumn('smtp_username')) {
            $selectColumns[] = 'smtp_username';
        }

        if (self::hasColumn('smtp_password')) {
            $selectColumns[] = 'smtp_password';
        }

        if (self::hasColumn('smtp_encryption')) {
            $selectColumns[] = 'smtp_encryption';
        }

        if (self::hasColumn('email_from_address')) {
            $selectColumns[] = 'email_from_address';
        }

        if (self::hasColumn('email_from_name')) {
            $selectColumns[] = 'email_from_name';
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
            $row['enable_discounts'] = (bool) ($row['enable_discounts'] ?? false);
            $row['enable_returns'] = (bool) ($row['enable_returns'] ?? false);
            $row['enable_multi_store'] = (bool) ($row['enable_multi_store'] ?? false);
            $row['enable_time_clock'] = (bool) ($row['enable_time_clock'] ?? false);
            $row['enable_email_notifications'] = (bool) ($row['enable_email_notifications'] ?? false);
            $row['smtp_host'] = (string) ($row['smtp_host'] ?? '');
            $row['smtp_port'] = (int) ($row['smtp_port'] ?? 587);
            $row['smtp_username'] = (string) ($row['smtp_username'] ?? '');
            $row['smtp_password'] = (string) ($row['smtp_password'] ?? '');
            $row['smtp_encryption'] = (string) ($row['smtp_encryption'] ?? 'tls');
            $row['email_from_address'] = (string) ($row['email_from_address'] ?? '');
            $row['email_from_name'] = (string) ($row['email_from_name'] ?? '');
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
            'enable_discounts' => false,
            'enable_returns' => false,
            'enable_multi_store' => false,
            'enable_time_clock' => false,
            'enable_email_notifications' => false,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'email_from_address' => '',
            'email_from_name' => '',
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
        $supportsDiscounts = self::hasColumn('enable_discounts');
        $supportsReturns = self::hasColumn('enable_returns');
        $supportsMultiStore = self::hasColumn('enable_multi_store');
        $supportsTimeClock = self::hasColumn('enable_time_clock');
        $supportsEmailNotifications = self::hasColumn('enable_email_notifications');
        $supportsSmtpHost = self::hasColumn('smtp_host');
        $supportsSmtpPort = self::hasColumn('smtp_port');
        $supportsSmtpUsername = self::hasColumn('smtp_username');
        $supportsSmtpPassword = self::hasColumn('smtp_password');
        $supportsSmtpEncryption = self::hasColumn('smtp_encryption');
        $supportsEmailFromAddress = self::hasColumn('email_from_address');
        $supportsEmailFromName = self::hasColumn('email_from_name');

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

        if ($supportsDiscounts) {
            $insertColumns[] = 'enable_discounts';
            $valueColumns[] = ':enable_discounts';
        }

        if ($supportsReturns) {
            $insertColumns[] = 'enable_returns';
            $valueColumns[] = ':enable_returns';
        }

        if ($supportsMultiStore) {
            $insertColumns[] = 'enable_multi_store';
            $valueColumns[] = ':enable_multi_store';
        }

        if ($supportsTimeClock) {
            $insertColumns[] = 'enable_time_clock';
            $valueColumns[] = ':enable_time_clock';
        }

        if ($supportsEmailNotifications) {
            $insertColumns[] = 'enable_email_notifications';
            $valueColumns[] = ':enable_email_notifications';
        }

        if ($supportsSmtpHost) {
            $insertColumns[] = 'smtp_host';
            $valueColumns[] = ':smtp_host';
        }

        if ($supportsSmtpPort) {
            $insertColumns[] = 'smtp_port';
            $valueColumns[] = ':smtp_port';
        }

        if ($supportsSmtpUsername) {
            $insertColumns[] = 'smtp_username';
            $valueColumns[] = ':smtp_username';
        }

        if ($supportsSmtpPassword) {
            $insertColumns[] = 'smtp_password';
            $valueColumns[] = ':smtp_password';
        }

        if ($supportsSmtpEncryption) {
            $insertColumns[] = 'smtp_encryption';
            $valueColumns[] = ':smtp_encryption';
        }

        if ($supportsEmailFromAddress) {
            $insertColumns[] = 'email_from_address';
            $valueColumns[] = ':email_from_address';
        }

        if ($supportsEmailFromName) {
            $insertColumns[] = 'email_from_name';
            $valueColumns[] = ':email_from_name';
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

        if ($supportsDiscounts) {
            $updateColumns[] = 'enable_discounts';
        }

        if ($supportsReturns) {
            $updateColumns[] = 'enable_returns';
        }

        if ($supportsMultiStore) {
            $updateColumns[] = 'enable_multi_store';
        }

        if ($supportsTimeClock) {
            $updateColumns[] = 'enable_time_clock';
        }

        if ($supportsEmailNotifications) {
            $updateColumns[] = 'enable_email_notifications';
        }

        if ($supportsSmtpHost) {
            $updateColumns[] = 'smtp_host';
        }

        if ($supportsSmtpPort) {
            $updateColumns[] = 'smtp_port';
        }

        if ($supportsSmtpUsername) {
            $updateColumns[] = 'smtp_username';
        }

        if ($supportsSmtpPassword) {
            $updateColumns[] = 'smtp_password';
        }

        if ($supportsSmtpEncryption) {
            $updateColumns[] = 'smtp_encryption';
        }

        if ($supportsEmailFromAddress) {
            $updateColumns[] = 'email_from_address';
        }

        if ($supportsEmailFromName) {
            $updateColumns[] = 'email_from_name';
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

        if ($supportsDiscounts) {
            $params[':enable_discounts'] = (int) ($data['enable_discounts'] ?? false);
        }

        if ($supportsReturns) {
            $params[':enable_returns'] = (int) ($data['enable_returns'] ?? false);
        }

        if ($supportsMultiStore) {
            $params[':enable_multi_store'] = (int) ($data['enable_multi_store'] ?? false);
        }

        if ($supportsTimeClock) {
            $params[':enable_time_clock'] = (int) ($data['enable_time_clock'] ?? false);
        }

        if ($supportsEmailNotifications) {
            $params[':enable_email_notifications'] = (int) ($data['enable_email_notifications'] ?? false);
        }

        if ($supportsSmtpHost) {
            $params[':smtp_host'] = (string) ($data['smtp_host'] ?? '');
        }

        if ($supportsSmtpPort) {
            $params[':smtp_port'] = (int) ($data['smtp_port'] ?? 587);
        }

        if ($supportsSmtpUsername) {
            $params[':smtp_username'] = (string) ($data['smtp_username'] ?? '');
        }

        if ($supportsSmtpPassword) {
            $params[':smtp_password'] = (string) ($data['smtp_password'] ?? '');
        }

        if ($supportsSmtpEncryption) {
            $params[':smtp_encryption'] = (string) ($data['smtp_encryption'] ?? 'tls');
        }

        if ($supportsEmailFromAddress) {
            $params[':email_from_address'] = (string) ($data['email_from_address'] ?? '');
        }

        if ($supportsEmailFromName) {
            $params[':email_from_name'] = (string) ($data['email_from_name'] ?? '');
        }

        $statement->execute($params);
    }

    public static function taxRatePercent(): float
    {
        $settings = self::get();
        return (float) ($settings['tax_rate_percent'] ?? 8.0);
    }

    public static function isFeatureEnabled(string $feature): bool
    {
        $settings = self::get();
        return (bool) ($settings[$feature] ?? false);
    }
}

