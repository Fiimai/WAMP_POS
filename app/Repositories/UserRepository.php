<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class UserRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(?string $query = null): array
    {
        $pdo = Database::connection();

        $sql = 'SELECT id, full_name, username, email, role, is_active, created_at
                FROM users
                WHERE 1=1';

        $params = [];
        if ($query !== null && $query !== '') {
            $sql .= ' AND (full_name LIKE :q OR username LIKE :q OR email LIKE :q)';
            $params[':q'] = '%' . $query . '%';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 400';

        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT id, full_name, username, email, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $pdo = Database::connection();
        $sql = 'SELECT 1 FROM users WHERE username = :username';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
        }
        $sql .= ' LIMIT 1';

        $statement = $pdo->prepare($sql);
        $statement->bindValue(':username', $username, PDO::PARAM_STR);
        if ($excludeId !== null) {
            $statement->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $trimmed = trim($email);
        if ($trimmed === '') {
            return false;
        }

        $pdo = Database::connection();
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
        }
        $sql .= ' LIMIT 1';

        $statement = $pdo->prepare($sql);
        $statement->bindValue(':email', $trimmed, PDO::PARAM_STR);
        if ($excludeId !== null) {
            $statement->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function create(string $fullName, string $username, ?string $email, string $passwordHash, string $role, bool $isActive): int
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, role, is_active)
             VALUES (:full_name, :username, :email, :password_hash, :role, :is_active)'
        );
        $statement->bindValue(':full_name', $fullName, PDO::PARAM_STR);
        $statement->bindValue(':username', $username, PDO::PARAM_STR);
        $statement->bindValue(':email', $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $statement->bindValue(':role', $role, PDO::PARAM_STR);
        $statement->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
        $statement->execute();

        return (int) $pdo->lastInsertId();
    }

    public function updateProfile(int $id, string $fullName, string $username, ?string $email, string $role, bool $isActive): bool
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'UPDATE users
             SET full_name = :full_name,
                 username = :username,
                 email = :email,
                 role = :role,
                 is_active = :is_active
             WHERE id = :id'
        );
        $statement->bindValue(':full_name', $fullName, PDO::PARAM_STR);
        $statement->bindValue(':username', $username, PDO::PARAM_STR);
        $statement->bindValue(':email', $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':role', $role, PDO::PARAM_STR);
        $statement->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function setActive(int $id, bool $active): bool
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        $statement->bindValue(':active', $active ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }
}

