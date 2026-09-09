<?php

declare(strict_types=1);

namespace LiteAudit\Attribute;

use Attribute;

/**
 * Marks a specific entity property to be ignored from auditing.
 * Sensitive fields (passwords, tokens, salt, secrets) should be tagged with this attribute.
 *
 * Example:
 *   #[AuditIgnore]
 *   public string $passwordHash;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class AuditIgnore
{
}
