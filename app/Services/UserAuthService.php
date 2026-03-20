<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class UserAuthService
{
    public function login(string $emailOrUsername, string $password): array|false
    {
        $identity = trim($emailOrUsername);
        if ($identity === '' || $password === '') {
            return false;
        }

        $pdo = Database::getInstance()->getConnection();

        $statement = $pdo->prepare(
            'SELECT id, full_name, username, email, password_hash, role, is_active
             FROM users
               WHERE (email = :identity_email OR username = :identity_username)
             LIMIT 1'
        );
           $statement->bindValue(':identity_email', $identity, PDO::PARAM_STR);
           $statement->bindValue(':identity_username', $identity, PDO::PARAM_STR);
        $statement->execute();

        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            return false;
        }

        if ((int) $user['is_active'] !== 1) {
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        return $user;
    }
}
