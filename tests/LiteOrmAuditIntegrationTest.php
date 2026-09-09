<?php

declare(strict_types=1);

namespace LiteAudit\Tests;

use LiteAudit\Actor\StaticActorProvider;
use LiteAudit\Attribute\{Auditable, AuditIgnore};
use LiteAudit\AuditManager;
use LiteAudit\Bridge\LiteOrmAuditBridge;
use LiteAudit\Model\AuditAction;
use LiteAudit\Storage\PdoAuditStorage;
use LiteORM\Attribute\{AutoIncrement, Column, Entity, Id, Table};
use LiteORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

#[Entity]
#[Table('audited_articles')]
#[Auditable(events: ['create', 'update', 'delete'], tag: 'editorial')]
class AuditedArticle
{
    #[Id, AutoIncrement]
    public int $id;

    #[Column(length: 200)]
    public string $title;

    #[Column]
    public float $price;

    #[AuditIgnore]
    #[Column(nullable: true)]
    public ?string $internalDraftNotes = null;
}

final class LiteOrmAuditIntegrationTest extends TestCase
{
    private EntityManager $em;
    private AuditManager $auditManager;
    private PdoAuditStorage $storage;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->em = new EntityManager('sqlite::memory:');
        $this->pdo = $this->em->getConnection()->getWriteConnection();

        // Create entity table
        $this->em->createTable(AuditedArticle::class);

        // Setup Audit Storage and Manager
        $this->storage = new PdoAuditStorage($this->pdo, 'audit_logs');
        $this->storage->createSchemaIfNotExists();

        $actorProvider = new StaticActorProvider('editor_john', '192.168.1.50', 'Mozilla/5.0');
        $this->auditManager = new AuditManager($this->storage, $actorProvider);

        // Register bridge
        LiteOrmAuditBridge::register($this->em, $this->auditManager, [
            'environment' => 'testing',
        ]);
    }

    public function testCompleteEntityAuditLifecycle(): void
    {
        // 1. CREATE
        $article = new AuditedArticle();
        $article->title = 'Original Article';
        $article->price = 19.99;
        $article->internalDraftNotes = 'Secret draft remarks';

        $this->em->persist($article);
        $this->em->flush();

        $this->assertNotNull($article->id);
        $articleId = $article->id;

        // Verify create audit entry
        $history = $this->storage->findByEntity(AuditedArticle::class, $articleId);
        $this->assertCount(1, $history);

        $createRec = $history[0];
        $this->assertEquals(AuditAction::CREATE, $createRec->action);
        $this->assertEquals('editor_john', $createRec->actorId);
        $this->assertEquals('editorial', $createRec->tag);
        $this->assertEquals('Original Article', $createRec->getNewValue('title'));
        $this->assertArrayNotHasKey('internalDraftNotes', $createRec->diff); // Ignored

        // 2. UPDATE
        $article->title = 'Revised Article Title';
        $article->price = 24.99;
        $article->internalDraftNotes = 'New secret remarks';
        $this->em->flush();

        // Verify update audit entry
        $history = $this->storage->findByEntity(AuditedArticle::class, $articleId);
        $this->assertCount(2, $history);

        $updateRec = $history[0]; // Newest first
        $this->assertEquals(AuditAction::UPDATE, $updateRec->action);
        $this->assertTrue($updateRec->hasChanged('title'));
        $this->assertEquals('Original Article', $updateRec->getOldValue('title'));
        $this->assertEquals('Revised Article Title', $updateRec->getNewValue('title'));
        $this->assertEquals(19.99, $updateRec->getOldValue('price'));
        $this->assertEquals(24.99, $updateRec->getNewValue('price'));
        $this->assertArrayNotHasKey('internalDraftNotes', $updateRec->diff);

        // 3. NO-OP FLUSH (no changes -> no new audit log)
        $this->em->flush();
        $this->assertCount(2, $this->storage->findByEntity(AuditedArticle::class, $articleId));

        // 4. DELETE
        $this->em->remove($article);
        $this->em->flush();

        // Verify delete audit entry
        $history = $this->storage->findByEntity(AuditedArticle::class, $articleId);
        $this->assertCount(3, $history);

        $deleteRec = $history[0];
        $this->assertEquals(AuditAction::DELETE, $deleteRec->action);
        $this->assertEquals('Revised Article Title', $deleteRec->getOldValue('title'));
    }
}
