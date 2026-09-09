<?php

declare(strict_types=1);

namespace LiteAudit\Model;

/**
 * Supported audit action types.
 */
enum AuditAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';

    /**
     * Human-readable verb.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATE => 'Created',
            self::UPDATE => 'Updated',
            self::DELETE => 'Deleted',
        };
    }
}
