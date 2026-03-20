<?php

declare(strict_types=1);

namespace App\Core;

final class Permissions
{
    /**
     * @return array<string>
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'admin' => [
                'users.manage',
                'inventory.adjust',
                'inventory.view',
                'audit.view',
                'settings.manage',
                'products.manage',
                'reports.view',
                'checkout.process',
                'receipts.view',
                'receipts.export',
            ],
            'manager' => [
                'inventory.adjust',
                'inventory.view',
                'reports.view',
                'checkout.process',
                'receipts.view',
                'receipts.export',
            ],
            'cashier' => [
                'checkout.process',
                'receipts.view',
            ],
            default => [],
        };
    }

    public static function has(string $role, string $capability): bool
    {
        return in_array($capability, self::forRole($role), true);
    }
}
