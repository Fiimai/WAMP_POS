<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use InvalidArgumentException;

final class UserManagementService
{
    private const ALLOWED_ROLES = ['admin', 'manager', 'cashier'];

    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(?string $query = null): array
    {
        return $this->users->listAll($query !== null ? trim($query) : null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->users->findById($id);
    }

    public function createUser(
        string $fullName,
        string $username,
        string $email,
        string $password,
        string $confirmPassword,
        string $role,
        bool $isActive
    ): int {
        $fullName = trim($fullName);
        $username = trim($username);
        $email = trim($email);
        $role = trim($role);

        $this->validateProfile($fullName, $username, $email, $role, null);
        $this->validatePassword($password, $confirmPassword);

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new InvalidArgumentException('Could not hash password.');
        }

        return $this->users->create(
            $fullName,
            $username,
            $email !== '' ? $email : null,
            $passwordHash,
            $role,
            $isActive
        );
    }

    public function updateUser(int $id, string $fullName, string $username, string $email, string $role, bool $isActive): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid user id.');
        }

        $fullName = trim($fullName);
        $username = trim($username);
        $email = trim($email);
        $role = trim($role);

        $this->validateProfile($fullName, $username, $email, $role, $id);

        $ok = $this->users->updateProfile($id, $fullName, $username, $email !== '' ? $email : null, $role, $isActive);
        if (!$ok) {
            throw new InvalidArgumentException('Failed to update user.');
        }
    }

    public function deactivateUser(int $id, int $currentUserId): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid user id.');
        }

        if ($id === $currentUserId) {
            throw new InvalidArgumentException('You cannot deactivate your own account.');
        }

        $this->users->setActive($id, false);
    }

    public function activateUser(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid user id.');
        }

        $this->users->setActive($id, true);
    }

    public function resetPassword(int $id, string $newPassword, string $confirmPassword): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid user id.');
        }

        $this->validatePassword($newPassword, $confirmPassword);

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new InvalidArgumentException('Could not hash password.');
        }

        $ok = $this->users->updatePassword($id, $passwordHash);
        if (!$ok) {
            throw new InvalidArgumentException('Failed to reset password.');
        }
    }

    private function validateProfile(string $fullName, string $username, string $email, string $role, ?int $excludeId): void
    {
        if ($fullName === '' || strlen($fullName) > 120) {
            throw new InvalidArgumentException('Full name is required and must be <= 120 chars.');
        }

        if ($username === '' || strlen($username) > 60 || preg_match('/^[A-Za-z0-9_.-]+$/', $username) !== 1) {
            throw new InvalidArgumentException('Username must be 1-60 chars and use letters/numbers/._- only.');
        }

        if ($this->users->usernameExists($username, $excludeId)) {
            throw new InvalidArgumentException('Username is already in use.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email is invalid.');
        }

        if ($email !== '' && $this->users->emailExists($email, $excludeId)) {
            throw new InvalidArgumentException('Email is already in use.');
        }

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('Invalid role selected.');
        }
    }

    private function validatePassword(string $password, string $confirmPassword): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        if ($password !== $confirmPassword) {
            throw new InvalidArgumentException('Password confirmation does not match.');
        }
    }
}

