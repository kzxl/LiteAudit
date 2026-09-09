<?php

declare(strict_types=1);

namespace LiteAudit\Actor;

/**
 * Interface for resolving current acting user/client contextual information.
 */
interface ActorProviderInterface
{
    /**
     * Get unique identifier for the current user or service account.
     */
    public function getActorId(): ?string;

    /**
     * Get client IP address.
     */
    public function getActorIp(): ?string;

    /**
     * Get client User-Agent header.
     */
    public function getUserAgent(): ?string;
}
