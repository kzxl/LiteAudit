<?php

declare(strict_types=1);

namespace LiteAudit;

use DateTimeImmutable;
use LiteAudit\Actor\{ActorProviderInterface, StaticActorProvider};
use LiteAudit\Attribute\{Auditable, AuditIgnore};
use LiteAudit\Diff\AuditDiffCalculator;
use LiteAudit\Model\{AuditAction, AuditRecord};
use LiteAudit\Storage\AuditStorageInterface;
use ReflectionClass;

/**
 * Main coordinator and entry point for LiteAudit.
 */
class AuditManager
{
    private AuditStorageInterface $storage;
    private ?ActorProviderInterface $actorProvider;

    /** @var array<string, array{auditable: ?Auditable, ignored: list<string>, pkProperty: string}> Metadata cache */
    private static array $metadataCache = [];

    public function __construct(
        AuditStorageInterface $storage,
        ?ActorProviderInterface $actorProvider = null,
    ) {
        $this->storage = $storage;
        $this->actorProvider = $actorProvider ?? new StaticActorProvider();
    }

    /**
     * Record an audit event for an entity mutation.
     *
     * @param object $entity The entity object being mutated
     * @param AuditAction|string $action Action: create, update, delete
     * @param ?array<string, mixed> $oldValues Snapshot before mutation
     * @param ?array<string, mixed> $newValues Snapshot after mutation
     * @param array<string, mixed> $metadata Extra contextual metadata
     * @return ?AuditRecord Created record, or null if ignored/no changes
     */
    public function audit(
        object $entity,
        AuditAction|string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = [],
    ): ?AuditRecord {
        $actionEnum = is_string($action) ? AuditAction::from(strtolower($action)) : $action;
        $entityClass = get_class($entity);

        $meta = $this->resolveEntityMetadata($entityClass);

        // If #[Auditable] is specified, verify it allows this action
        if ($meta['auditable'] !== null && !$meta['auditable']->allowsAction($actionEnum->value)) {
            return null;
        }

        // Calculate diff
        $diff = AuditDiffCalculator::diff($oldValues, $newValues, $meta['ignored']);

        // If action is update and nothing changed, skip logging
        if ($actionEnum === AuditAction::UPDATE && empty($diff)) {
            return null;
        }

        // Extract entity ID
        $pkProp = $meta['pkProperty'];
        $entityId = $entity->{$pkProp} ?? $newValues[$pkProp] ?? $oldValues[$pkProp] ?? null;

        if ($entityId === null && isset($entity->id)) {
            $entityId = $entity->id;
        }

        if ($entityId === null) {
            $entityId = '0';
        }

        $record = new AuditRecord(
            id: $this->generateUuidV4(),
            entityClass: $entityClass,
            entityId: (string)$entityId,
            action: $actionEnum,
            diff: $diff,
            oldValues: AuditDiffCalculator::filterIgnored($oldValues, $meta['ignored']),
            newValues: AuditDiffCalculator::filterIgnored($newValues, $meta['ignored']),
            actorId: $this->actorProvider?->getActorId(),
            actorIp: $this->actorProvider?->getActorIp(),
            userAgent: $this->actorProvider?->getUserAgent(),
            timestamp: new DateTimeImmutable(),
            metadata: $metadata,
            tag: $meta['auditable']?->tag,
        );

        $this->storage->save($record);

        return $record;
    }

    /**
     * Retrieve audit history for a specific entity.
     *
     * @param string $entityClass
     * @param string|int $entityId
     * @param int $limit
     * @param int $offset
     * @return list<AuditRecord>
     */
    public function getHistory(string $entityClass, string|int $entityId, int $limit = 50, int $offset = 0): array
    {
        return $this->storage->findByEntity($entityClass, $entityId, $limit, $offset);
    }

    public function getStorage(): AuditStorageInterface
    {
        return $this->storage;
    }

    public function getActorProvider(): ?ActorProviderInterface
    {
        return $this->actorProvider;
    }

    public function setActorProvider(?ActorProviderInterface $actorProvider): self
    {
        $this->actorProvider = $actorProvider;
        return $this;
    }

    /**
     * Parse reflection attributes and cache metadata.
     *
     * @return array{auditable: ?Auditable, ignored: list<string>, pkProperty: string}
     */
    private function resolveEntityMetadata(string $class): array
    {
        if (isset(self::$metadataCache[$class])) {
            return self::$metadataCache[$class];
        }

        $ref = new ReflectionClass($class);
        $auditableAttr = null;
        $ignored = [];
        $pkProperty = 'id';

        // Read class attribute #[Auditable]
        $classAttrs = $ref->getAttributes(Auditable::class);
        if (!empty($classAttrs)) {
            /** @var Auditable $auditableAttr */
            $auditableAttr = $classAttrs[0]->newInstance();
            $ignored = array_merge($ignored, $auditableAttr->ignore);
        }

        // Read property attributes
        foreach ($ref->getProperties() as $prop) {
            if (!empty($prop->getAttributes(AuditIgnore::class))) {
                $ignored[] = $prop->getName();
            }

            // Detect primary key if LiteORM attributes exist
            foreach ($prop->getAttributes() as $attr) {
                $shortName = substr(strrchr($attr->getName(), '\\') ?: $attr->getName(), 1);
                if ($shortName === 'PrimaryKey' || $shortName === 'Id') {
                    $pkProperty = $prop->getName();
                }
            }
        }

        $resolved = [
            'auditable' => $auditableAttr,
            'ignored' => array_values(array_unique($ignored)),
            'pkProperty' => $pkProperty,
        ];

        return self::$metadataCache[$class] = $resolved;
    }

    /**
     * Generate standard RFC 4122 v4 UUID without external dependencies.
     */
    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
