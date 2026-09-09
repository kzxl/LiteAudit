<?php

declare(strict_types=1);

namespace LiteAudit\Storage;

use LiteAudit\Model\AuditRecord;

/**
 * In-memory audit storage engine.
 * Useful for unit testing, CLI batches, and ephemeral auditing.
 */
final class MemoryAuditStorage implements AuditStorageInterface
{
    /** @var list<AuditRecord> */
    private array $records = [];

    public function save(AuditRecord $record): void
    {
        $this->records[] = $record;
    }

    public function saveBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->records[] = $record;
        }
    }

    public function find(string $id): ?AuditRecord
    {
        foreach ($this->records as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }
        return null;
    }

    public function findByEntity(string $entityClass, string|int $entityId, int $limit = 50, int $offset = 0): array
    {
        $matched = [];
        $targetId = (string)$entityId;

        // Search in reverse order (newest first)
        for ($i = count($this->records) - 1; $i >= 0; $i--) {
            $record = $this->records[$i];
            if ($record->entityClass === $entityClass && (string)$record->entityId === $targetId) {
                $matched[] = $record;
            }
        }

        return array_slice($matched, $offset, $limit);
    }

    public function findAll(int $limit = 50, int $offset = 0): array
    {
        $reversed = array_reverse($this->records);
        return array_slice($reversed, $offset, $limit);
    }

    public function count(?string $entityClass = null, string|int|null $entityId = null): int
    {
        if ($entityClass === null) {
            return count($this->records);
        }

        $targetId = $entityId !== null ? (string)$entityId : null;
        $count = 0;

        foreach ($this->records as $record) {
            if ($record->entityClass === $entityClass) {
                if ($targetId === null || (string)$record->entityId === $targetId) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Clear all stored records.
     */
    public function clear(): void
    {
        $this->records = [];
    }

    /**
     * Get all raw records.
     *
     * @return list<AuditRecord>
     */
    public function all(): array
    {
        return $this->records;
    }
}
