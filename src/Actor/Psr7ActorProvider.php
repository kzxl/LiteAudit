<?php

declare(strict_types=1);

namespace LiteAudit\Actor;

/**
 * PSR-7 compatible actor provider.
 * Extracts actor information from HTTP ServerRequest without hard-coupling to a specific framework.
 */
final class Psr7ActorProvider implements ActorProviderInterface
{
    /** @var callable(): mixed */
    private $requestResolver;

    private string $userAttribute;

    /**
     * @param callable(): mixed $requestResolver Callable returning current PSR-7 ServerRequestInterface or null
     * @param string $userAttribute Attribute name in request storing the authenticated user/token
     */
    public function __construct(callable $requestResolver, string $userAttribute = 'user')
    {
        $this->requestResolver = $requestResolver;
        $this->userAttribute = $userAttribute;
    }

    public function getActorId(): ?string
    {
        $request = ($this->requestResolver)();
        if (!$request || !method_exists($request, 'getAttribute')) {
            return null;
        }

        $user = $request->getAttribute($this->userAttribute);
        if ($user === null) {
            return null;
        }

        if (is_scalar($user)) {
            return (string)$user;
        }

        if (is_array($user)) {
            return (string)($user['sub'] ?? $user['id'] ?? $user['username'] ?? json_encode($user));
        }

        if (is_object($user)) {
            if (isset($user->id)) return (string)$user->id;
            if (isset($user->sub)) return (string)$user->sub;
            if (method_exists($user, 'getId')) return (string)$user->getId();
            if (method_exists($user, '__toString')) return (string)$user;
        }

        return null;
    }

    public function getActorIp(): ?string
    {
        $request = ($this->requestResolver)();
        if (!$request) {
            return null;
        }

        if (method_exists($request, 'getHeaderLine')) {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $ips = explode(',', $forwarded);
                return trim($ips[0]);
            }
        }

        if (method_exists($request, 'getServerParams')) {
            $params = $request->getServerParams();
            return $params['REMOTE_ADDR'] ?? null;
        }

        return null;
    }

    public function getUserAgent(): ?string
    {
        $request = ($this->requestResolver)();
        if (!$request || !method_exists($request, 'getHeaderLine')) {
            return null;
        }

        $ua = $request->getHeaderLine('User-Agent');
        return $ua !== '' ? $ua : null;
    }
}
