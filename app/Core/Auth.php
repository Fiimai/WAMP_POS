<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

final class Auth
{
    public static function user(): ?array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $user = User::findById($userId);
        if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
            return null;
        }

        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function hasRole(array $roles): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        return in_array((string) $user['role'], $roles, true);
    }

    public static function hasCapability(string $capability): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        return Permissions::has((string) $user['role'], $capability);
    }

    public static function requireCapability(string $capability): void
    {
        $user = self::user();
        if ($user === null) {
            header('Location: login.php');
            exit;
        }

        if (!Permissions::has((string) $user['role'], $capability)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    public static function requirePageAuth(array $roles = []): array
    {
        $user = self::user();
        if ($user === null) {
            header('Location: login.php');
            exit;
        }

        if ($roles !== [] && !in_array((string) $user['role'], $roles, true)) {
            http_response_code(403);
            exit('Forbidden');
        }

        return $user;
    }

    public static function requireApiAuth(array $roles = []): array
    {
        $user = self::user();
        if ($user === null) {
            self::jsonError(401, 'Unauthorized');
        }

        if ($roles !== [] && !in_array((string) $user['role'], $roles, true)) {
            self::jsonError(403, 'Forbidden');
        }

        return $user;
    }

    public static function validateCsrfFromRequest(): void
    {
        $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
        $requestToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
            self::jsonError(419, 'Invalid CSRF token');
        }
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function clientIp(): string
    {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            $candidate = trim((string) ($parts[0] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $realIp = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if ($realIp !== '') {
            return $realIp;
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public static function jsonError(int $statusCode, string $message): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}
