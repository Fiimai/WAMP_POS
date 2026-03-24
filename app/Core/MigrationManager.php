<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Database Migration System
 * Handles schema updates and data migrations safely
 */

class MigrationManager
{
    private \PDO $pdo;
    private string $migrationsTable = 'schema_migrations';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureMigrationsTable();
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->migrationsTable} (
                id VARCHAR(100) PRIMARY KEY,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function runMigrations(array $migrations): array
    {
        $batch = $this->getNextBatch();
        $executed = [];

        foreach ($migrations as $migrationId => $migration) {
            if ($this->isExecuted($migrationId)) {
                continue;
            }

            try {
                $this->pdo->beginTransaction();

                // Run the migration
                if (is_callable($migration)) {
                    $migration($this->pdo);
                } elseif (is_string($migration)) {
                    $this->pdo->exec($migration);
                }

                // Record the migration
                $stmt = $this->pdo->prepare("
                    INSERT INTO {$this->migrationsTable} (id, batch)
                    VALUES (?, ?)
                ");
                $stmt->execute([$migrationId, $batch]);

                $this->pdo->commit();
                $executed[] = $migrationId;

            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw new RuntimeException("Migration {$migrationId} failed: " . $e->getMessage());
            }
        }

        return $executed;
    }

    private function getNextBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) as max_batch FROM {$this->migrationsTable}");
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($result['max_batch'] ?? 0) + 1;
    }

    private function isExecuted(string $migrationId): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->migrationsTable} WHERE id = ?");
        $stmt->execute([$migrationId]);
        return $stmt->fetch() !== false;
    }

    public function rollback(int $steps = 1): array
    {
        // Implementation for rollback would require storing down migrations
        // This is a simplified version
        return [];
    }
}