<?php

declare(strict_types=1);

/**
 * Feature Flag System for Gradual Rollouts
 */

class FeatureFlags
{
    private static ?array $flags = null;
    private static ?array $userOverrides = null;

    /**
     * Check if a feature is enabled for a user
     */
    public static function isEnabled(string $feature, ?array $user = null): bool
    {
        // Check user-specific overrides first
        if ($user && self::isUserOverrideEnabled($feature, $user)) {
            return true;
        }

        // Check global feature flags
        $flags = self::getFlags();
        return (bool) ($flags[$feature] ?? false);
    }

    /**
     * Enable feature for specific users (for beta testing)
     */
    public static function enableForUsers(string $feature, array $userIds): void
    {
        $overrides = self::getUserOverrides();
        $overrides[$feature] = array_merge($overrides[$feature] ?? [], $userIds);
        self::saveUserOverrides($overrides);
    }

    /**
     * Check percentage-based rollout
     */
    public static function isInRollout(string $feature, int $percentage, ?array $user = null): bool
    {
        if (!$user) {
            return false;
        }

        $userId = $user['id'] ?? 0;
        $hash = crc32($feature . $userId);
        $rolloutValue = abs($hash) % 100;

        return $rolloutValue < $percentage;
    }

    private static function getFlags(): array
    {
        if (self::$flags === null) {
            // In a real system, this would come from database/cache
            self::$flags = [
                'loyalty_program' => true,
                'advanced_reporting' => false,
                'multi_store' => true,
                'beta_feature_x' => false,
            ];
        }
        return self::$flags;
    }

    private static function getUserOverrides(): array
    {
        if (self::$userOverrides === null) {
            // Load from database or cache
            self::$userOverrides = [];
        }
        return self::$userOverrides;
    }

    private static function isUserOverrideEnabled(string $feature, array $user): bool
    {
        $overrides = self::getUserOverrides();
        $userId = $user['id'] ?? 0;

        return in_array($userId, $overrides[$feature] ?? []);
    }

    private static function saveUserOverrides(array $overrides): void
    {
        // Save to database/cache
        self::$userOverrides = $overrides;
    }
}

// Usage examples:
/*
// Check if loyalty program is enabled
if (FeatureFlags::isEnabled('loyalty_program', $currentUser)) {
    // Show loyalty features
}

// Gradual rollout to 25% of users
if (FeatureFlags::isInRollout('beta_feature', 25, $currentUser)) {
    // Show beta feature
}

// Enable for specific beta users
FeatureFlags::enableForUsers('beta_feature', [1, 5, 10, 15]);
*/