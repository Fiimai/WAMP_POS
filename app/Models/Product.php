<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Product
{
    public static function activeList(?string $query = null): array
    {
        $pdo = Database::connection();

        $sql = 'SELECT p.id, p.name, p.barcode, p.sku, p.unit_price, p.stock_qty, c.name AS category_name
                FROM products p
                INNER JOIN categories c ON c.id = p.category_id
                WHERE p.is_active = 1';

        $params = [];

        if ($query !== null && $query !== '') {
            $sql .= ' AND (p.name LIKE :search OR p.barcode LIKE :search OR p.sku LIKE :search)';
            $params['search'] = '%' . $query . '%';
        }

        $sql .= ' ORDER BY p.name ASC LIMIT 100';

        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id, name, unit_price, stock_qty, is_active FROM products WHERE id = :id LIMIT 1');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        $product = $statement->fetch();

        return $product ?: null;
    }
}

