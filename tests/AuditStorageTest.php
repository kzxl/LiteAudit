<?php

declare(strict_types=1);

namespace LiteAudit\Tests;

use DateTimeImmutable;
use LiteAudit\Model\{AuditAction, AuditRecord};
use LiteAudit\Storage\{MemoryAuditStorage, PdoAuditStorage};
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditStorageTest extends TestCase
{
    private function createDummyRecord(string $id, string $entityId = '1', AuditAction $action = AuditAction::CREATE): AuditRecord
    {
        return new AuditRecord(
            id: $id,
            entityClass: 'App\\Entity\\Product',
            entityId: $entityId,
            action: $action,
            diff: ['name' => ['old' => null, 'new' => 'Laptop']],
            oldValues: null,
            newValues: ['name' => 'Laptop'],
            actorId: 'usr_42',
            actorIp: '192.168.1.10',
            userAgent: 'Mozilla/5.0',
            timestamp: new DateTimeImmutable('2026-09-09 10:00:00'),
            metadata: ['channel' => 'web'],
            tag: 'catalog',
        );
    }

    public function testMemoryAuditStorage(): void
    {
        $storage = new MemoryAuditStorage();

        $rec1 = $this->createDummyRecord('audit-1', '101', AuditAction::CREATE);
        $rec2 = $this->createDummyRecord('audit-2', '101', AuditAction::UPDATE);
        $rec3 = $this->createDummyRecord('audit-3', '102', AuditAction::CREATE);

        $storage->save($rec1);
        $storage->saveBatch([$rec2, $rec3]);

        $this->assertEquals(3, $storage->count());
        $this->assertEquals(2, $storage->count('App\\Entity\\Product', '101'));
        $this->assertEquals(1, $storage->count('App\\Entity\\Product', '102'));

        $this->assertNotNull($storage->find('audit-2'));
        $this->assertNull($storage->find('audit-nonexistent'));

        $history101 = $storage->findByEntity('App\\Entity\\Product', '101');
        $this->assertCount(2, $history101);
        // Newest first
        $this->assertEquals('audit-2', $history101[0]->id);
        $this->assertEquals('audit-1', $history101[1]->id);

        $storage->clear();
        $this->assertEquals(0, $storage->count());
    }

    public function testPdoAuditStorageWithSqlite(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $storage = new PdoAuditStorage($pdo, 'my_audit_logs');
        $storage->createSchemaIfNotExists();

        $rec1 = $this->createDummyRecord('audit-pdo-1', '201', AuditAction::CREATE);
        $rec2 = $this->createDummyRecord('audit-pdo-2', '201', AuditAction::UPDATE);

        $storage->save($rec1);
        $storage->save($rec2);

        $this->assertEquals(2, $storage->count());
        $this->assertEquals(2, $storage->count('App\\Entity\\Product', '201'));

        $fetched = $storage->find('audit-pdo-1');
        $this->assertNotNull($fetched);
        $this->assertEquals('App\\Entity\\Product', $fetched->entityClass);
        $this->assertEquals('201', $fetched->entityId);
        $this->assertEquals(AuditAction::CREATE, $fetched->action);
        $this->assertEquals('usr_42', $fetched->actorId);
        $this->assertEquals('192.168.1.10', $fetched->actorIp);
        $this->assertEquals('catalog', $fetched->tag);
        $this->assertEquals(['channel' => 'web'], $fetched->metadata);
        $this->assertEquals(['name' => ['old' => null, 'new' => 'Laptop']], $fetched->diff);

        $history = $storage->findByEntity('App\\Entity\\Product', '201', limit: 10);
        $this->assertCount(2, $history);
        $this->assertEquals('audit-pdo-2', $history[0]->id);
    }
}
