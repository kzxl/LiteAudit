<?php

declare(strict_types=1);

namespace LiteAudit\Tests;

use LiteAudit\Actor\StaticActorProvider;
use LiteAudit\Attribute\{Auditable, AuditIgnore};
use LiteAudit\AuditManager;
use LiteAudit\Model\AuditAction;
use LiteAudit\Storage\MemoryAuditStorage;
use PHPUnit\Framework\TestCase;

#[Auditable(events: ['update', 'delete'], ignore: ['internalSecret'], tag: 'security_users')]
class TestSecuredUser
{
    public int $id = 42;
    public string $name = 'Alice';

    #[AuditIgnore]
    public string $passwordHash = 'hash123';

    public string $internalSecret = 'secret_key';
}

class TestPlainEntity
{
    public int $id = 99;
    public string $title = 'Sample Item';
}

final class AuditManagerTest extends TestCase
{
    public function testAuditingPlainEntity(): void
    {
        $storage = new MemoryAuditStorage();
        $actorProvider = new StaticActorProvider('admin_1', '10.0.0.1', 'AuditClient/1.0');
        $manager = new AuditManager($storage, $actorProvider);

        $entity = new TestPlainEntity();

        $record = $manager->audit(
            entity: $entity,
            action: AuditAction::CREATE,
            oldValues: null,
            newValues: ['id' => 99, 'title' => 'Sample Item'],
        );

        $this->assertNotNull($record);
        $this->assertEquals(TestPlainEntity::class, $record->entityClass);
        $this->assertEquals('99', $record->entityId);
        $this->assertEquals(AuditAction::CREATE, $record->action);
        $this->assertEquals('admin_1', $record->actorId);
        $this->assertEquals('10.0.0.1', $record->actorIp);
        $this->assertEquals('AuditClient/1.0', $record->userAgent);
        $this->assertTrue($record->hasChanged('title'));
        $this->assertEquals('Sample Item', $record->getNewValue('title'));
    }

    public function testAuditableAttributeRestrictsEvents(): void
    {
        $storage = new MemoryAuditStorage();
        $manager = new AuditManager($storage);

        $user = new TestSecuredUser();

        // 'create' is NOT in allowed events ['update', 'delete']
        $createRecord = $manager->audit(
            entity: $user,
            action: AuditAction::CREATE,
            oldValues: null,
            newValues: ['id' => 42, 'name' => 'Alice'],
        );
        $this->assertNull($createRecord);
        $this->assertEquals(0, $storage->count());

        // 'update' IS in allowed events
        $updateRecord = $manager->audit(
            entity: $user,
            action: AuditAction::UPDATE,
            oldValues: ['id' => 42, 'name' => 'Alice'],
            newValues: ['id' => 42, 'name' => 'Alice Updated'],
        );
        $this->assertNotNull($updateRecord);
        $this->assertEquals(1, $storage->count());
        $this->assertEquals('security_users', $updateRecord->tag);
    }

    public function testAuditIgnoresSensitiveProperties(): void
    {
        $storage = new MemoryAuditStorage();
        $manager = new AuditManager($storage);

        $user = new TestSecuredUser();

        $record = $manager->audit(
            entity: $user,
            action: AuditAction::UPDATE,
            oldValues: [
                'name' => 'Alice',
                'passwordHash' => 'old_hash',
                'internalSecret' => 'old_secret',
            ],
            newValues: [
                'name' => 'Alice Smith',
                'passwordHash' => 'new_hash',
                'internalSecret' => 'new_secret',
            ],
        );

        $this->assertNotNull($record);
        $this->assertArrayNotHasKey('passwordHash', $record->diff);
        $this->assertArrayNotHasKey('internalSecret', $record->diff);
        $this->assertArrayHasKey('name', $record->diff);

        // Also check oldValues and newValues have them stripped
        $this->assertArrayNotHasKey('passwordHash', $record->oldValues);
        $this->assertArrayNotHasKey('internalSecret', $record->oldValues);
    }

    public function testUnchangedUpdateSkipsAuditLog(): void
    {
        $storage = new MemoryAuditStorage();
        $manager = new AuditManager($storage);

        $entity = new TestPlainEntity();

        $record = $manager->audit(
            entity: $entity,
            action: AuditAction::UPDATE,
            oldValues: ['id' => 99, 'title' => 'Same'],
            newValues: ['id' => 99, 'title' => 'Same'],
        );

        $this->assertNull($record);
        $this->assertEquals(0, $storage->count());
    }

    public function testRecordJsonSerialization(): void
    {
        $storage = new MemoryAuditStorage();
        $manager = new AuditManager($storage);
        $entity = new TestPlainEntity();

        $record = $manager->audit(
            entity: $entity,
            action: AuditAction::CREATE,
            oldValues: null,
            newValues: ['id' => 99, 'title' => 'Item'],
        );

        $json = json_encode($record);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);

        $this->assertEquals($record->id, $decoded['id']);
        $this->assertEquals('create', $decoded['action']);
        $this->assertEquals('Item', $decoded['diff']['title']['new']);
    }
}
