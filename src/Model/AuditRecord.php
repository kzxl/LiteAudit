<?php

declare(strict_types=1);

namespace LiteAudit\Model;

use DateTimeImmutable;
use JsonSerializable;

/**
 * Immutable value object representing a single recorded audit event.
 */
final readonly class AuditRecord implements JsonSerializable
{
    /**
     * @param string $id Unique identifier for the audit log entry
     * @param string $entityClass Fully qualified class name of the target entity
     * @param string|int $entityId Primary key / identifier of the target entity
     * @param AuditAction $action The mutation action (create, update, delete)
     * @param array<string, array{old: mixed, new: mixed}> $diff Field-level changes
     * @param ?array<string, mixed> $oldValues Complete previous snapshot before change
     * @param ?array<string, mixed> $newValues Complete new snapshot after change
     * @param ?string $actorId Identifier of user or service that triggered the change
     * @param ?string $actorIp Client IP address
     * @param ?string $userAgent Client HTTP user-agent
     * @param DateTimeImmutable $timestamp Point in time when mutation was recorded
     * @param array<string, mixed> $metadata Arbitrary contextual data (request ID, route, tags)
     * @param ?string $tag Optional entity category tag
     */
    public function __construct(
        public string $id,
        public string $entityClass,
        public string|int $entityId,
        public AuditAction $action,
        public array $diff,
        public ?array $oldValues = null,
        public ?array $newValues = null,
        public ?string $actorId = null,
        public ?string $actorIp = null,
        public ?string $userAgent = null,
        public DateTimeImmutable $timestamp = new DateTimeImmutable(),
        public array $metadata = [],
        public ?string $tag = null,
    ) {
    }

    /**
     * Check if a specific field was modified.
     */
    public function hasChanged(string $field): bool
    {
        return array_key_exists($field, $this->diff);
    }

    /**
     * Get old value of a modified field.
     */
    public function getOldValue(string $field): mixed
    {
        return $this->diff[$field]['old'] ?? null;
    }

    /**
     * Get new value of a modified field.
     */
    public function getNewValue(string $field): mixed
    {
        return $this->diff[$field]['new'] ?? null;
    }

    /**
     * List of all modified field names.
     *
     * @return list<string>
     */
    public function getChangedFields(): array
    {
        return array_keys($this->diff);
    }

    /**
     * Convert to standard array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'entity_class' => $this->entityClass,
            'entity_id' => $this->entityId,
            'action' => $this->action->value,
            'diff' => $this->diff,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'actor_id' => $this->actorId,
            'actor_ip' => $this->actorIp,
            'user_agent' => $this->userAgent,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
            'tag' => $this->tag,
        ];
    }

    /**
     * Serialize to JSON representation.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
