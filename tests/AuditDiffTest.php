<?php

declare(strict_types=1);

namespace LiteAudit\Tests;

use DateTimeImmutable;
use LiteAudit\Diff\AuditDiffCalculator;
use PHPUnit\Framework\TestCase;

final class AuditDiffTest extends TestCase
{
    public function testCreateDiffIncludesAllNewFields(): void
    {
        $new = ['name' => 'Alice', 'age' => 30, 'role' => 'admin'];
        $diff = AuditDiffCalculator::diff(null, $new);

        $this->assertCount(3, $diff);
        $this->assertEquals(['old' => null, 'new' => 'Alice'], $diff['name']);
        $this->assertEquals(['old' => null, 'new' => 30], $diff['age']);
    }

    public function testDeleteDiffIncludesAllOldFields(): void
    {
        $old = ['name' => 'Alice', 'age' => 30];
        $diff = AuditDiffCalculator::diff($old, null);

        $this->assertCount(2, $diff);
        $this->assertEquals(['old' => 'Alice', 'new' => null], $diff['name']);
        $this->assertEquals(['old' => 30, 'new' => null], $diff['age']);
    }

    public function testUpdateDiffOnlyContainsChangedFields(): void
    {
        $old = ['id' => 1, 'name' => 'Bob', 'status' => 'draft', 'view_count' => 10];
        $new = ['id' => 1, 'name' => 'Bob', 'status' => 'active', 'view_count' => 15];

        $diff = AuditDiffCalculator::diff($old, $new);

        $this->assertCount(2, $diff);
        $this->assertArrayNotHasKey('id', $diff);
        $this->assertArrayNotHasKey('name', $diff);
        $this->assertEquals(['old' => 'draft', 'new' => 'active'], $diff['status']);
        $this->assertEquals(['old' => 10, 'new' => 15], $diff['view_count']);
    }

    public function testIgnoredFieldsAreExcludedFromDiff(): void
    {
        $old = ['username' => 'john', 'password' => 'secret1', 'email' => 'john@test.com'];
        $new = ['username' => 'john', 'password' => 'secret2', 'email' => 'john@new.com'];

        $diff = AuditDiffCalculator::diff($old, $new, ignored: ['password']);

        $this->assertCount(1, $diff);
        $this->assertArrayNotHasKey('password', $diff);
        $this->assertEquals(['old' => 'john@test.com', 'new' => 'john@new.com'], $diff['email']);
    }

    public function testDateTimeIsNormalizedProperly(): void
    {
        $dt1 = new DateTimeImmutable('2026-01-01 10:00:00');
        $dt2 = new DateTimeImmutable('2026-01-01 12:00:00');

        $diff = AuditDiffCalculator::diff(['updated_at' => $dt1], ['updated_at' => $dt2]);

        $this->assertCount(1, $diff);
        $this->assertEquals([
            'old' => '2026-01-01 10:00:00',
            'new' => '2026-01-01 12:00:00',
        ], $diff['updated_at']);
    }

    public function testIdenticalStatesReturnEmptyDiff(): void
    {
        $old = ['id' => 1, 'name' => 'Alice'];
        $new = ['id' => 1, 'name' => 'Alice'];

        $diff = AuditDiffCalculator::diff($old, $new);
        $this->assertEmpty($diff);
    }
}
