<?php

declare(strict_types=1);

namespace LiteAudit\Tests;

use LiteAudit\Actor\{Psr7ActorProvider, StaticActorProvider};
use PHPUnit\Framework\TestCase;

final class ActorProviderTest extends TestCase
{
    public function testStaticActorProvider(): void
    {
        $provider = new StaticActorProvider('user_123', '127.0.0.1', 'CLI');

        $this->assertEquals('user_123', $provider->getActorId());
        $this->assertEquals('127.0.0.1', $provider->getActorIp());
        $this->assertEquals('CLI', $provider->getUserAgent());

        $provider->setActor('user_456', '10.0.0.5', 'Browser');
        $this->assertEquals('user_456', $provider->getActorId());
        $this->assertEquals('10.0.0.5', $provider->getActorIp());
        $this->assertEquals('Browser', $provider->getUserAgent());
    }

    public function testPsr7ActorProviderWithMockRequest(): void
    {
        $mockRequest = new class {
            public function getAttribute(string $name): mixed
            {
                return $name === 'user' ? ['id' => 'auth_usr_99', 'name' => 'John'] : null;
            }

            public function getHeaderLine(string $name): string
            {
                return match (strtolower($name)) {
                    'x-forwarded-for' => '203.0.113.195, 70.41.3.18',
                    'user-agent' => 'Mozilla/5.0 PHPUnit',
                    default => '',
                };
            }

            public function getServerParams(): array
            {
                return ['REMOTE_ADDR' => '127.0.0.1'];
            }
        };

        $provider = new Psr7ActorProvider(fn() => $mockRequest, 'user');

        $this->assertEquals('auth_usr_99', $provider->getActorId());
        $this->assertEquals('203.0.113.195', $provider->getActorIp());
        $this->assertEquals('Mozilla/5.0 PHPUnit', $provider->getUserAgent());
    }

    public function testPsr7ActorProviderWhenNoRequest(): void
    {
        $provider = new Psr7ActorProvider(fn() => null);

        $this->assertNull($provider->getActorId());
        $this->assertNull($provider->getActorIp());
        $this->assertNull($provider->getUserAgent());
    }
}
