<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class ProductRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(?string $query = null): array
    {
        $pdo = Database::getInstance()->getConnection();

        $sql = 'SELECT p.id, p.name, p.sku, p.unit_price, p.stock_qty, p.is_active, c.name AS category_name
                FROM products p
                INNER JOIN categories c ON c.id = p.category_id
                WHERE 1=1';

        $params = [];
        if ($query !== null && $query !== '') {
            $sql .= ' AND (p.name LIKE :q_name OR p.sku LIKE :q_sku OR p.barcode LIKE :q_barcode)';
            $searchTerm = '%' . $query . '%';
            $params[':q_name'] = $searchTerm;
            $params[':q_sku'] = $searchTerm;
            $params[':q_barcode'] = $searchTerm;
        }

        $sql .= ' ORDER BY p.name ASC LIMIT 300';

        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(string $productName, string $sku, float $price, int $stockQuantity, int $categoryId, ?string $imagePath = null): int
    {
        $pdo = Database::getInstance()->getConnection();

        $statement = $pdo->prepare(
            'INSERT INTO products (category_id, sku, name, unit_price, stock_qty, reorder_level, image_path, is_active)
             VALUES (:category_id, :sku, :name, :unit_price, :stock_qty, 0, :image_path, 1)'
        );

        $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        $statement->bindValue(':sku', $sku, PDO::PARAM_STR);
        $statement->bindValue(':name', $productName, PDO::PARAM_STR);
        $statement->bindValue(':unit_price', $price);
        $statement->bindValue(':stock_qty', $stockQuantity, PDO::PARAM_INT);
        $statement->bindValue(':image_path', $imagePath, PDO::PARAM_STR);
        $statement->execute();

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $pdo = Database::getInstance()->getConnection();
        $statement = $pdo->prepare(
            'SELECT id, category_id, sku, name, unit_price, stock_qty, is_active
             FROM products
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function update(int $id, string $productName, string $sku, float $price, int $stockQuantity, int $categoryId, ?string $imagePath = null): bool
    {
        $pdo = Database::getInstance()->getConnection();
        $statement = $pdo->prepare(
            'UPDATE products
             SET category_id = :category_id,
                 sku = :sku,
                 name = :name,
                 unit_price = :unit_price,
                 stock_qty = :stock_qty,
                 image_path = :image_path
             WHERE id = :id'
        );

        $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        $statement->bindValue(':sku', $sku, PDO::PARAM_STR);
        $statement->bindValue(':name', $productName, PDO::PARAM_STR);
        $statement->bindValue(':unit_price', $price);
        $statement->bindValue(':stock_qty', $stockQuantity, PDO::PARAM_INT);
        $statement->bindValue(':image_path', $imagePath, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function setActive(int $id, bool $active): bool
    {
        $pdo = Database::getInstance()->getConnection();
        $statement = $pdo->prepare(
            'UPDATE products
             SET is_active = :is_active
             WHERE id = :id'
        );
        $statement->bindValue(':is_active', $active ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }
}

