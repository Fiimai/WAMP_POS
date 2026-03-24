<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class AuditLogRepository
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function record(
        ?int $actorUserId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $details = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?PDO $pdo = null
    ): void {
        $connection = $pdo ?? Database::connection();

        $statement = $connection->prepare(
            'INSERT INTO audit_logs (
                actor_user_id,
                action,
                entity_type,
                entity_id,
                details_json,
                ip_address,
                user_agent
             ) VALUES (
                :actor_user_id,
                :action,
                :entity_type,
                :entity_id,
                :details_json,
                :ip_address,
                :user_agent
             )'
        );

        $json = null;
        if ($details !== null) {
            $encoded = json_encode($details, JSON_UNESCAPED_SLASHES);
            $json = $encoded === false ? null : $encoded;
        }

        $statement->bindValue(':actor_user_id', $actorUserId, $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':action', $action, PDO::PARAM_STR);
        $statement->bindValue(':entity_type', $entityType, $entityType === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':entity_id', $entityId, $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':details_json', $json, $json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':ip_address', $ipAddress !== null ? substr($ipAddress, 0, 45) : null, $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':user_agent', $userAgent !== null ? substr($userAgent, 0, 255) : null, $userAgent === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
    }
}

