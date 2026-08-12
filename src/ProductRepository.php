<?php

declare(strict_types=1);

namespace ErpSync;

/**
 * PDO/SQLite-backed storage for the webshop's product table and its change
 * audit log. Deliberately not an ORM: the schema and the upsert are plain,
 * visible SQL over prepared statements.
 */
final class ProductRepository
{
    private \PDO $pdo;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    public static function inMemory(): self
    {
        return new self(new \PDO('sqlite::memory:'));
    }

    public static function file(string $path): self
    {
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return new self(new \PDO('sqlite:' . $path));
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS products (
                sku                TEXT PRIMARY KEY,
                name               TEXT NOT NULL,
                price_net          REAL NOT NULL,
                stock_qty          INTEGER NOT NULL,
                status             TEXT NOT NULL,
                source_updated_at  TEXT NOT NULL,
                synced_at          TEXT NOT NULL
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS product_audit (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                sku          TEXT NOT NULL,
                batch_id     TEXT NOT NULL,
                change_type  TEXT NOT NULL,
                field        TEXT,
                old_value    TEXT,
                new_value    TEXT,
                reason       TEXT,
                changed_at   TEXT NOT NULL
            )
            SQL);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_sku ON product_audit(sku)');
    }

    public function findBySku(string $sku): ?Product
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = :sku');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** @return Product[] */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM products ORDER BY sku');
        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function upsert(Product $p): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO products (sku, name, price_net, stock_qty, status, source_updated_at, synced_at)
            VALUES (:sku, :name, :price_net, :stock_qty, :status, :source_updated_at, :synced_at)
            ON CONFLICT(sku) DO UPDATE SET
                name = excluded.name,
                price_net = excluded.price_net,
                stock_qty = excluded.stock_qty,
                status = excluded.status,
                source_updated_at = excluded.source_updated_at,
                synced_at = excluded.synced_at
            SQL);

        $stmt->execute([
            'sku' => $p->sku,
            'name' => $p->name,
            'price_net' => $p->priceNet,
            'stock_qty' => $p->stockQty,
            'status' => $p->status,
            'source_updated_at' => $p->sourceUpdatedAt,
            'synced_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
    }

    /** @param array<string,array{0:mixed,1:mixed}> $fieldDiffs */
    public function appendAudit(string $sku, string $batchId, string $changeType, array $fieldDiffs, ?string $reason = null): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO product_audit (sku, batch_id, change_type, field, old_value, new_value, reason, changed_at)
            VALUES (:sku, :batch_id, :change_type, :field, :old_value, :new_value, :reason, :changed_at)
            SQL);

        if ($fieldDiffs === []) {
            $stmt->execute([
                'sku' => $sku, 'batch_id' => $batchId, 'change_type' => $changeType,
                'field' => null, 'old_value' => null, 'new_value' => null,
                'reason' => $reason, 'changed_at' => $now,
            ]);
            return;
        }

        foreach ($fieldDiffs as $field => [$old, $new]) {
            $stmt->execute([
                'sku' => $sku, 'batch_id' => $batchId, 'change_type' => $changeType,
                'field' => $field, 'old_value' => (string) $old, 'new_value' => (string) $new,
                'reason' => $reason, 'changed_at' => $now,
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function auditFor(string $sku): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_audit WHERE sku = :sku ORDER BY id');
        $stmt->execute(['sku' => $sku]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Product
    {
        return new Product(
            (string) $row['sku'],
            (string) $row['name'],
            (float) $row['price_net'],
            (int) $row['stock_qty'],
            (string) $row['status'],
            (string) $row['source_updated_at']
        );
    }
}
