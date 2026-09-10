<?php

declare(strict_types=1);

namespace LiteAudit\Tests;

use InvalidArgumentException;
use LiteAudit\AuditManager;
use LiteAudit\Model\AuditAction;
use LiteAudit\Storage\MemoryAuditStorage;
use PHPUnit\Framework\TestCase;

class ProductEntity
{
    public int $id = 1;
    public string $name = 'Keyboard';
    public float $price = 50.0;
    public string $status = 'active';
}

class CustomerEntity
{
    public int $id = 1;
    public string $email = 'user@example.com';
}

final class AuditRevertTest extends TestCase
{
    private MemoryAuditStorage $storage;
    private AuditManager $manager;

    protected function setUp(): void
    {
        $this->storage = new MemoryAuditStorage();
        $this->manager = new AuditManager($this->storage);
    }

    public function testRevertToOldValues(): void
    {
        $product = new ProductEntity();

        $oldSnapshot = [
            'id' => 1,
            'name' => 'Keyboard',
            'price' => 50.0,
            'status' => 'active',
        ];

        // Simulate user updating product
        $product->name = 'Mechanical RGB Keyboard';
        $product->price = 120.0;
        $product->status = 'discounted';

        $newSnapshot = [
            'id' => 1,
            'name' => 'Mechanical RGB Keyboard',
            'price' => 120.0,
            'status' => 'discounted',
        ];

        $record = $this->manager->audit(
            entity: $product,
            action: AuditAction::UPDATE,
            oldValues: $oldSnapshot,
            newValues: $newSnapshot,
        );

        $this->assertNotNull($record);

        // Revert back to old values
        $this->manager->revertTo($product, $record->id, toOldValues: true);

        $this->assertEquals('Keyboard', $product->name);
        $this->assertEquals(50.0, $product->price);
        $this->assertEquals('active', $product->status);
    }

    public function testRevertToNewValues(): void
    {
        $product = new ProductEntity();
        $product->name = 'Old Name';

        $record = $this->manager->audit(
            entity: $product,
            action: AuditAction::UPDATE,
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'Brand New Name'],
        );

        // Reset product to dummy state
        $product->name = 'Intermediate Name';

        // Revert to new values of revision
        $this->manager->revertTo($product, $record->id, toOldValues: false);

        $this->assertEquals('Brand New Name', $product->name);
    }

    public function testThrowsOnRevisionNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Audit revision 'non-existent-id' not found.");

        $product = new ProductEntity();
        $this->manager->revertTo($product, 'non-existent-id');
    }

    public function testThrowsOnEntityClassMismatch(): void
    {
        $product = new ProductEntity();
        $record = $this->manager->audit(
            entity: $product,
            action: AuditAction::UPDATE,
            oldValues: ['name' => 'A'],
            newValues: ['name' => 'B'],
        );

        $customer = new CustomerEntity();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Audit revision entity class mismatch.");

        $this->manager->revertTo($customer, $record->id);
    }
}
