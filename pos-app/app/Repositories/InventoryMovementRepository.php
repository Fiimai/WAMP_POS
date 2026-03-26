<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class InventoryMovementRepository
{
    public function record(
        int $productId,
        int $changedByUserId,
        string $movementType,
        int $qtyChange,
        int $stockBefore,
        int $stockAfter,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
        ?PDO $pdo = null
    ): void {
        $connection = $pdo ?? Database::connection();

        $statement = $connection->prepare(
            'INSERT INTO inventory_movements (
                product_id,
                changed_by_user_id,
                movement_type,
                qty_change,
                stock_before,
                stock_after,
                reference_type,
                reference_id,
                notes
             ) VALUES (
                :product_id,
                :changed_by_user_id,
                :movement_type,
                :qty_change,
                :stock_before,
                :stock_after,
                :reference_type,
                :reference_id,
                :notes
             )'
        );

        $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $statement->bindValue(':changed_by_user_id', $changedByUserId, PDO::PARAM_INT);
        $statement->bindValue(':movement_type', $movementType, PDO::PARAM_STR);
        $statement->bindValue(':qty_change', $qtyChange, PDO::PARAM_INT);
        $statement->bindValue(':stock_before', $stockBefore, PDO::PARAM_INT);
        $statement->bindValue(':stock_after', $stockAfter, PDO::PARAM_INT);
        $statement->bindValue(':reference_type', $referenceType, $referenceType === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':reference_id', $referenceId, $referenceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':notes', $notes, $notes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 80): array
    {
        $limit = max(1, min(300, $limit));
        $pdo = Database::connection();

        $statement = $pdo->prepare(
            'SELECT m.id, m.movement_type, m.qty_change, m.stock_before, m.stock_after, m.reference_type, m.reference_id, m.notes, m.created_at,
                    p.name AS product_name, p.sku,
                    u.full_name AS changed_by_name
             FROM inventory_movements m
             INNER JOIN products p ON p.id = m.product_id
             INNER JOIN users u ON u.id = m.changed_by_user_id
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT :limit_rows'
        );
        $statement->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

