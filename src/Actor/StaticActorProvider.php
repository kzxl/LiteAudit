<?php

declare(strict_types=1);

namespace LiteAudit\Actor;

/**
 * Static in-memory actor provider for testing, CLI tasks, or fixed actor contexts.
 */
final class StaticActorProvider implements ActorProviderInterface
{
    public function __construct(
        private ?string $actorId = 'system',
        private ?string $actorIp = '127.0.0.1',
        private ?string $userAgent = 'CLI/System',
    ) {
    }

    public function setActor(?string $actorId, ?string $actorIp = null, ?string $userAgent = null): self
    {
        $this->actorId = $actorId;
        if ($actorIp !== null) {
            $this->actorIp = $actorIp;
        }
        if ($userAgent !== null) {
            $this->userAgent = $userAgent;
        }
        return $this;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getActorIp(): ?string
    {
        return $this->actorIp;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
