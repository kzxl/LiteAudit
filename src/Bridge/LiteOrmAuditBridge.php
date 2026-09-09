<?php

declare(strict_types=1);

namespace LiteAudit\Bridge;

use LiteAudit\AuditManager;
use LiteAudit\Model\AuditAction;
use LiteORM\EntityManager;

/**
 * Bridge connecting LiteORM EntityManager lifecycle events with LiteAudit.
 */
final class LiteOrmAuditBridge
{
    /**
     * Attach LiteAudit to an EntityManager instance.
     * All entity insertions, updates, and removals flushed via the EntityManager
     * will automatically be audited according to their attributes and rules.
     *
     * @param EntityManager $em The LiteORM entity manager
     * @param AuditManager $auditManager The audit manager instance
     * @param array<string, mixed> $defaultMetadata Extra contextual metadata attached to every audit entry
     */
    public static function register(
        EntityManager $em,
        AuditManager $auditManager,
        array $defaultMetadata = [],
    ): void {
        $em->on('*', function (string $event, object $entity, ?array $old, ?array $new) use ($auditManager, $defaultMetadata): void {
            $action = match ($event) {
                'insert' => AuditAction::CREATE,
                'update' => AuditAction::UPDATE,
                'delete' => AuditAction::DELETE,
                default => null,
            };

            if ($action === null) {
                return;
            }

            $auditManager->audit(
                entity: $entity,
                action: $action,
                oldValues: $old,
                newValues: $new,
                metadata: $defaultMetadata,
            );
        });
    }
}
