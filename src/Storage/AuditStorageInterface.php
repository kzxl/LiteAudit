<?php

declare(strict_types=1);

namespace LiteAudit\Storage;

use LiteAudit\Model\AuditRecord;

/**
 * Contract for audit trail log storage engines.
 */
interface AuditStorageInterface
{
    /**
     * Persist a single audit record.
     */
    public function save(AuditRecord $record): void;

    /**
     * Persist multiple audit records efficiently in a single operation.
     *
     * @param list<AuditRecord> $records
     */
    public function saveBatch(array $records): void;

    /**
     * Find a single audit record by its unique ID.
     */
    public function find(string $id): ?AuditRecord;

    /**
     * Retrieve audit history for a specific entity instance, ordered newest to oldest.
     *
     * @param string $entityClass Fully qualified class name
     * @param string|int $entityId Target entity ID
     * @param int $limit Maximum records to return
     * @param int $offset Pagination offset
     * @return list<AuditRecord>
     */
    public function findByEntity(string $entityClass, string|int $entityId, int $limit = 50, int $offset = 0): array;

    /**
     * Retrieve audit log entries across all entities.
     *
     * @param int $limit
     * @param int $offset
     * @return list<AuditRecord>
     */
    public function findAll(int $limit = 50, int $offset = 0): array;

    /**
     * Count audit records, optionally filtered by entity class and ID.
     */
    public function count(?string $entityClass = null, string|int|null $entityId = null): int;
}
