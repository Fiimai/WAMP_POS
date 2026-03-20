<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class User
{
    public static function findByUsername(string $username): ?array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT id, full_name, username, email, password_hash, role, is_active
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $statement->bindValue(':username', $username, PDO::PARAM_STR);
        $statement->execute();

        $user = $statement->fetch();

        return $user ?: null;
    }

    public static function findById(int $id): ?array
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

        $user = $statement->fetch();

        return $user ?: null;
    }
}
