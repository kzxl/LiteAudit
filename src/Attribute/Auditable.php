<?php

declare(strict_types=1);

namespace LiteAudit\Attribute;

use Attribute;

/**
 * Marks a class as auditable by LiteAudit.
 *
 * Example:
 *   #[Auditable(events: ['create', 'update', 'delete'], ignore: ['password', 'token'])]
 *   class User { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Auditable
{
    /**
     * @param list<string> $events Monitored events: 'create', 'update', 'delete'
     * @param list<string> $ignore Property names to exclude from audit diffs
     * @param ?string $tag Optional categorization tag for auditing
     */
    public function __construct(
        public array $events = ['create', 'update', 'delete'],
        public array $ignore = [],
        public ?string $tag = null,
    ) {
    }

    /**
     * Check if a specific action is auditable.
     */
    public function allowsAction(string $action): bool
    {
        return in_array(strtolower($action), array_map('strtolower', $this->events), true);
    }
}
