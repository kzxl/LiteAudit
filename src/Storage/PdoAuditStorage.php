<?php

declare(strict_types=1);

namespace LiteAudit\Storage;

use DateTimeImmutable;
use LiteAudit\Model\{AuditAction, AuditRecord};
use PDO;

/**
 * Enterprise PDO storage backend for relational databases (SQLite, MySQL, PostgreSQL, SQL Server).
 */
final class PdoAuditStorage implements AuditStorageInterface
{
    private PDO $pdo;
    private string $tableName;

    public function __construct(PDO $pdo, string $tableName = 'audit_logs')
    {
        $this->pdo = $pdo;
        $this->tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName) ?: 'audit_logs';
    }

    /**
     * Automatically create the audit log table and indexes if not already present.
     */
    public function createSchemaIfNotExists(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'sqlite' => "
                CREATE TABLE IF NOT EXISTS {$this->tableName} (
                    seq INTEGER PRIMARY KEY AUTOINCREMENT,
                    id TEXT UNIQUE NOT NULL,
                    entity_class TEXT NOT NULL,
                    entity_id TEXT NOT NULL,
                    action TEXT NOT NULL,
                    diff TEXT NOT NULL,
                    old_values TEXT NULL,
                    new_values TEXT NULL,
                    actor_id TEXT NULL,
                    actor_ip TEXT NULL,
                    user_agent TEXT NULL,
                    metadata TEXT NULL,
                    tag TEXT NULL,
                    created_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tableName}_entity ON {$this->tableName} (entity_class, entity_id);
                CREATE INDEX IF NOT EXISTS idx_{$this->tableName}_created ON {$this->tableName} (created_at);
            ",
            'mysql' => "
                CREATE TABLE IF NOT EXISTS {$this->tableName} (
                    seq BIGINT AUTO_INCREMENT PRIMARY KEY,
                    id VARCHAR(64) UNIQUE NOT NULL,
                    entity_class VARCHAR(255) NOT NULL,
                    entity_id VARCHAR(128) NOT NULL,
                    action VARCHAR(32) NOT NULL,
                    diff LONGTEXT NOT NULL,
                    old_values LONGTEXT NULL,
                    new_values LONGTEXT NULL,
                    actor_id VARCHAR(128) NULL,
                    actor_ip VARCHAR(45) NULL,
                    user_agent VARCHAR(255) NULL,
                    metadata LONGTEXT NULL,
                    tag VARCHAR(64) NULL,
                    created_at VARCHAR(32) NOT NULL,
                    INDEX idx_{$this->tableName}_entity (entity_class, entity_id),
                    INDEX idx_{$this->tableName}_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'pgsql' => "
                CREATE TABLE IF NOT EXISTS {$this->tableName} (
                    seq BIGSERIAL PRIMARY KEY,
                    id VARCHAR(64) UNIQUE NOT NULL,
                    entity_class VARCHAR(255) NOT NULL,
                    entity_id VARCHAR(128) NOT NULL,
                    action VARCHAR(32) NOT NULL,
                    diff TEXT NOT NULL,
                    old_values TEXT NULL,
                    new_values TEXT NULL,
                    actor_id VARCHAR(128) NULL,
                    actor_ip VARCHAR(45) NULL,
                    user_agent VARCHAR(255) NULL,
                    metadata TEXT NULL,
                    tag VARCHAR(64) NULL,
                    created_at VARCHAR(32) NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tableName}_entity ON {$this->tableName} (entity_class, entity_id);
                CREATE INDEX IF NOT EXISTS idx_{$this->tableName}_created ON {$this->tableName} (created_at);
            ",
            default => "
                CREATE TABLE {$this->tableName} (
                    seq INTEGER PRIMARY KEY,
                    id VARCHAR(64) UNIQUE NOT NULL,
                    entity_class VARCHAR(255) NOT NULL,
                    entity_id VARCHAR(128) NOT NULL,
                    action VARCHAR(32) NOT NULL,
                    diff TEXT NOT NULL,
                    old_values TEXT NULL,
                    new_values TEXT NULL,
                    actor_id VARCHAR(128) NULL,
                    actor_ip VARCHAR(45) NULL,
                    user_agent VARCHAR(255) NULL,
                    metadata TEXT NULL,
                    tag VARCHAR(64) NULL,
                    created_at VARCHAR(32) NOT NULL
                )
            ",
        };

        $this->pdo->exec($sql);
    }

    public function save(AuditRecord $record): void
    {
        $this->saveBatch([$record]);
    }

    public function saveBatch(array $records): void
    {
        if (empty($records)) {
            return;
        }

        $sql = "INSERT INTO {$this->tableName} (
            id, entity_class, entity_id, action, diff, old_values, new_values,
            actor_id, actor_ip, user_agent, metadata, tag, created_at
        ) VALUES (
            :id, :entity_class, :entity_id, :action, :diff, :old_values, :new_values,
            :actor_id, :actor_ip, :user_agent, :metadata, :tag, :created_at
        )";

        $stmt = $this->pdo->prepare($sql);

        $inTx = $this->pdo->inTransaction();
        if (!$inTx) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($records as $rec) {
                $stmt->execute([
                    ':id' => $rec->id,
                    ':entity_class' => $rec->entityClass,
                    ':entity_id' => (string)$rec->entityId,
                    ':action' => $rec->action->value,
                    ':diff' => json_encode($rec->diff, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ':old_values' => $rec->oldValues !== null ? json_encode($rec->oldValues, JSON_UNESCAPED_UNICODE) : null,
                    ':new_values' => $rec->newValues !== null ? json_encode($rec->newValues, JSON_UNESCAPED_UNICODE) : null,
                    ':actor_id' => $rec->actorId,
                    ':actor_ip' => $rec->actorIp,
                    ':user_agent' => $rec->userAgent,
                    ':metadata' => !empty($rec->metadata) ? json_encode($rec->metadata, JSON_UNESCAPED_UNICODE) : null,
                    ':tag' => $rec->tag,
                    ':created_at' => $rec->timestamp->format('Y-m-d H:i:s.u'),
                ]);
            }
            if (!$inTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTx) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function find(string $id): ?AuditRecord
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tableName} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapRowToRecord($row) : null;
    }

    public function findByEntity(string $entityClass, string|int $entityId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->tableName}
            WHERE entity_class = :entity_class AND entity_id = :entity_id
            ORDER BY seq DESC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':entity_class', $entityClass, PDO::PARAM_STR);
        $stmt->bindValue(':entity_id', (string)$entityId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $records = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $records[] = $this->mapRowToRecord($row);
        }

        return $records;
    }

    public function findAll(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->tableName}
            ORDER BY seq DESC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $records = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $records[] = $this->mapRowToRecord($row);
        }

        return $records;
    }

    public function count(?string $entityClass = null, string|int|null $entityId = null): int
    {
        if ($entityClass === null) {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$this->tableName}");
            return (int)$stmt->fetchColumn();
        }

        if ($entityId === null) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->tableName} WHERE entity_class = :entity_class");
            $stmt->execute([':entity_class' => $entityClass]);
            return (int)$stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM {$this->tableName}
            WHERE entity_class = :entity_class AND entity_id = :entity_id
        ");
        $stmt->execute([
            ':entity_class' => $entityClass,
            ':entity_id' => (string)$entityId,
        ]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToRecord(array $row): AuditRecord
    {
        return new AuditRecord(
            id: (string)$row['id'],
            entityClass: (string)$row['entity_class'],
            entityId: $row['entity_id'],
            action: AuditAction::from((string)$row['action']),
            diff: json_decode((string)$row['diff'], true) ?? [],
            oldValues: $row['old_values'] !== null ? json_decode((string)$row['old_values'], true) : null,
            newValues: $row['new_values'] !== null ? json_decode((string)$row['new_values'], true) : null,
            actorId: $row['actor_id'] !== null ? (string)$row['actor_id'] : null,
            actorIp: $row['actor_ip'] !== null ? (string)$row['actor_ip'] : null,
            userAgent: $row['user_agent'] !== null ? (string)$row['user_agent'] : null,
            timestamp: new DateTimeImmutable((string)$row['created_at']),
            metadata: $row['metadata'] !== null ? json_decode((string)$row['metadata'], true) : [],
            tag: $row['tag'] !== null ? (string)$row['tag'] : null,
        );
    }
}
