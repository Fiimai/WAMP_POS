<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class CategoryRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $pdo = Database::getInstance()->getConnection();
        $statement = $pdo->prepare(
            'SELECT id, name, slug, is_active
             FROM categories
             ORDER BY is_active DESC, name ASC'
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array
    {
        $pdo = Database::getInstance()->getConnection();
        $statement = $pdo->prepare(
            'SELECT id, name
             FROM categories
             WHERE is_active = 1
             ORDER BY name ASC'
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function slugExists(string $slug): bool
    {
        $pdo = Database::getInstance()->getConnection();
        $statement = $pdo->prepare('SELECT 1 FROM categories WHERE slug = :slug LIMIT 1');
        $statement->bindValue(':slug', $slug, PDO::PARAM_STR);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function create(string $name, string $slug): int
    {
        $pdo = Database::getInstance()->getConnection();
        $statement = $pdo->prepare(
            'INSERT INTO categories (name, slug, is_active)
             VALUES (:name, :slug, 1)'
        );
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':slug', $slug, PDO::PARAM_STR);
        $statement->execute();

        return (int) $pdo->lastInsertId();
    }

    public function setActive(int $categoryId, bool $active): bool
    {
        $pdo = Database::getInstance()->getConnection();
        $statement = $pdo->prepare(
            'UPDATE categories
             SET is_active = :is_active
             WHERE id = :id'
        );
        $statement->bindValue(':is_active', $active ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);

        return $statement->execute();
    }
}

