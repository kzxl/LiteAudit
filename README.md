# LiteAudit

[![PHP 8.2+](https://img.shields.io/badge/php-8.2%2B-blue.svg)](https://www.php.net/)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen.svg)]()

High-performance, zero-dependency enterprise audit trail and entity change tracking engine for PHP 8.2+. Generates compact Git-like JSON diffs, respects declarative PHP 8.2 attributes (`#[Auditable]`, `#[AuditIgnore]`), and seamlessly bridges into `LiteORM` and PSR-7/15 microservices.

---

## Key Features

- **Compact Git-Like Diffs**: Calculates field-level deltas (`['field' => ['old' => ..., 'new' => ...]]`) while automatically skipping no-op updates.
- **PHP 8.2 Declarative Attributes**:
  - `#[Auditable(events: ['create', 'update', 'delete'], ignore: ['secret'], tag: 'finance')]` on entity classes.
  - `#[AuditIgnore]` on sensitive properties (passwords, tokens, salt) to strip them from storage and diffs.
- **Pluggable Storage Backends**:
  - `PdoAuditStorage`: Production SQL storage (SQLite, MySQL, PostgreSQL, SQL Server) with microsecond precision and deterministic sequence ordering.
  - `MemoryAuditStorage`: Fast in-memory buffer for unit testing, batch operations, and CLI workers.
- **Flexible Actor Providers**:
  - `StaticActorProvider`: In-memory or CLI context.
  - `Psr7ActorProvider`: Extracts client IP (including `X-Forwarded-For`), User-Agent, and authenticated user ID directly from PSR-7 `ServerRequestInterface`.
- **LiteORM Integration**:
  - Zero-overhead `LiteOrmAuditBridge`: Automatically captures all `insert`, `update`, and `delete` entity changes flushed through `LiteORM\EntityManager`.
- **Enterprise Standards**: Strictly adheres to `declare(strict_types=1);`, `readonly` classes, typed properties, and zero external bloat.

---

## Installation

```bash
composer require kzxl/lite-audit
```

---

## Quick Start

### 1. Standalone Auditing

```php
use LiteAudit\AuditManager;
use LiteAudit\Model\AuditAction;
use LiteAudit\Storage\PdoAuditStorage;
use LiteAudit\Actor\StaticActorProvider;

$pdo = new PDO('sqlite:app.db');
$storage = new PdoAuditStorage($pdo);
$storage->createSchemaIfNotExists();

$actorProvider = new StaticActorProvider('usr_100', '192.168.1.1', 'MyApp/1.0');
$manager = new AuditManager($storage, $actorProvider);

// Audit an entity update
$oldState = ['name' => 'Widget', 'price' => 10.00, 'status' => 'draft'];
$newState = ['name' => 'Widget Pro', 'price' => 15.00, 'status' => 'active'];

$record = $manager->audit(
    entity: $myEntity,
    action: AuditAction::UPDATE,
    oldValues: $oldState,
    newValues: $newState,
    metadata: ['source' => 'web_portal']
);

// Compact diff output:
// [
//   'name' => ['old' => 'Widget', 'new' => 'Widget Pro'],
//   'price' => ['old' => 10.0, 'new' => 15.0],
//   'status' => ['old' => 'draft', 'new' => 'active']
// ]
```

---

### 2. Entity Attributes

```php
use LiteAudit\Attribute\Auditable;
use LiteAudit\Attribute\AuditIgnore;

#[Auditable(events: ['create', 'update', 'delete'], tag: 'user_management')]
class User
{
    public int $id;
    public string $email;
    public string $role;

    #[AuditIgnore]
    public string $passwordHash; // Automatically omitted from diffs and snapshots
}
```

---

### 3. LiteORM Automatic Bridge

```php
use LiteORM\EntityManager;
use LiteAudit\AuditManager;
use LiteAudit\Bridge\LiteOrmAuditBridge;
use LiteAudit\Storage\PdoAuditStorage;

$em = new EntityManager('sqlite:app.db');
$storage = new PdoAuditStorage($em->getConnection()->getWriteConnection());
$storage->createSchemaIfNotExists();

$auditManager = new AuditManager($storage);

// Register bridge once
LiteOrmAuditBridge::register($em, $auditManager);

// Everything flushed via EntityManager is now audited automatically!
$user = new User();
$user->email = 'admin@example.com';
$user->role = 'editor';
$em->persist($user);
$em->flush(); // Triggers CREATE audit record

$user->role = 'superadmin';
$em->flush(); // Triggers UPDATE audit record with diff ['role' => ['old' => 'editor', 'new' => 'superadmin']]
```

---

### 4. Querying History

```php
// Retrieve chronological audit trail for a specific entity
$history = $manager->getHistory(User::class, $user->id, limit: 20);

foreach ($history as $record) {
    echo sprintf(
        "[%s] Action: %s by %s (Changed: %s)\n",
        $record->timestamp->format('Y-m-d H:i:s'),
        $record->action->value,
        $record->actorId ?? 'anonymous',
        implode(', ', $record->getChangedFields())
    );
}
```

---

## Testing

```bash
composer test
```

Runs the test suite using PHPUnit 11 with 100% pass rate.

---

## License

Apache-2.0.
