<?php

declare(strict_types=1);

namespace ErpSync\Tests;

use ErpSync\ChangeType;
use ErpSync\ProductRepository;
use ErpSync\SyncEngine;

final class SyncEngineTest extends TestCase
{
    /** @param array<int,array<int,string>> $rows */
    private function csv(array $rows): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'erp_sync_test_');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['sku', 'name', 'price_net', 'stock_qty', 'status', 'updated_at']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        return $path;
    }

    public function testFirstRunCreatesEveryValidRow(): void
    {
        $repo = ProductRepository::inMemory();
        $engine = new SyncEngine($repo);
        $csv = $this->csv([
            ['SKU-A', 'Product A', '1.00', '10', 'active', '2026-08-01T06:00:00Z'],
            ['SKU-B', 'Product B', '2.00', '20', 'active', '2026-08-01T06:00:00Z'],
        ]);

        $report = $engine->sync($csv, apply: true, batchId: 'batch-1');

        $counts = $report->counts();
        $this->assertSame(2, $counts[ChangeType::CREATED]);
        $this->assertSame(0, $counts[ChangeType::UPDATED]);
        $this->assertCount(2, $repo->all());
        unlink($csv);
    }

    public function testReRunningTheIdenticalFeedIsANoop(): void
    {
        $repo = ProductRepository::inMemory();
        $engine = new SyncEngine($repo);
        $csv = $this->csv([
            ['SKU-A', 'Product A', '1.00', '10', 'active', '2026-08-01T06:00:00Z'],
        ]);

        $engine->sync($csv, apply: true, batchId: 'batch-1');
        $second = $engine->sync($csv, apply: true, batchId: 'batch-2'); // identical file, identical updated_at

        // Same source updated_at as what is already stored: not newer, so STALE, not UNCHANGED.
        // Either way nothing is written twice; this is the idempotency guarantee.
        $counts = $second->counts();
        $this->assertSame(1, $counts[ChangeType::STALE]);
        $this->assertSame(0, $counts[ChangeType::CREATED]);
        $this->assertSame(0, $counts[ChangeType::UPDATED]);
        unlink($csv);
    }

    public function testDryRunNeverWritesToTheDatabase(): void
    {
        $repo = ProductRepository::inMemory();
        $engine = new SyncEngine($repo);
        $csv = $this->csv([
            ['SKU-A', 'Product A', '1.00', '10', 'active', '2026-08-01T06:00:00Z'],
        ]);

        $report = $engine->sync($csv, apply: false, batchId: 'batch-1');

        $this->assertSame(1, $report->counts()[ChangeType::CREATED]);
        $this->assertCount(0, $repo->all());
        unlink($csv);
    }

    public function testStaleRowNeverOverwritesNewerStoredData(): void
    {
        $repo = ProductRepository::inMemory();
        $engine = new SyncEngine($repo);

        $day2 = $this->csv([
            ['SKU-A', 'Product A', '5.00', '10', 'active', '2026-08-02T06:00:00Z'],
        ]);
        $engine->sync($day2, apply: true, batchId: 'day-2');

        $day1Resent = $this->csv([
            ['SKU-A', 'Product A', '0.01', '10', 'active', '2026-08-01T06:00:00Z'], // older AND wrong price
        ]);
        $report = $engine->sync($day1Resent, apply: true, batchId: 'day-1-resent');

        $this->assertSame(1, $report->counts()[ChangeType::STALE]);
        $stored = $repo->findBySku('SKU-A');
        $this->assertNotNull($stored);
        $this->assertSame(5.00, $stored->priceNet);
        unlink($day2);
        unlink($day1Resent);
    }

    public function testAnomalousPriceChangeIsFlaggedAndNotApplied(): void
    {
        $repo = ProductRepository::inMemory();
        $engine = new SyncEngine($repo);

        $baseline = $this->csv([
            ['SKU-A', 'Product A', '3.90', '10', 'active', '2026-08-01T06:00:00Z'],
        ]);
        $engine->sync($baseline, apply: true, batchId: 'day-1');

        $anomalous = $this->csv([
            ['SKU-A', 'Product A', '0.39', '10', 'active', '2026-08-02T06:00:00Z'], // -90%
        ]);
        $report = $engine->sync($anomalous, apply: true, batchId: 'day-2');

        $this->assertSame(1, $report->counts()[ChangeType::FLAGGED]);
        $stored = $repo->findBySku('SKU-A');
        $this->assertNotNull($stored);
        $this->assertSame(3.90, $stored->priceNet, 'a flagged change must not reach the database');
        // Only the day-1 creation is audited; the flagged day-2 attempt writes nothing.
        $this->assertCount(1, $repo->auditFor('SKU-A'), 'an unapplied (flagged) change adds no new audit row');
        unlink($baseline);
        unlink($anomalous);
    }

    public function testForcedOverrideAppliesAFlaggedChange(): void
    {
        $repo = ProductRepository::inMemory();
        $engine = new SyncEngine($repo);

        $baseline = $this->csv([
            ['SKU-A', 'Product A', '3.90', '10', 'active', '2026-08-01T06:00:00Z'],
        ]);
        $engine->sync($baseline, apply: true, batchId: 'day-1');

        $anomalous = $this->csv([
            ['SKU-A', 'Product A', '0.39', '10', 'active', '2026-08-02T06:00:00Z'],
        ]);
        $report = $engine->sync($anomalous, apply: true, batchId: 'day-2', forceSkus: ['SKU-A']);

        $this->assertSame(1, $report->counts()[ChangeType::UPDATED]);
        $stored = $repo->findBySku('SKU-A');
        $this->assertNotNull($stored);
        $this->assertSame(0.39, $stored->priceNet);
        unlink($baseline);
        unlink($anomalous);
    }

    public function testInvalidRowIsRejectedAndDoesNotAbortTheBatch(): void
    {
        $repo = ProductRepository::inMemory();
        $engine = new SyncEngine($repo);
        $csv = $this->csv([
            ['SKU-A', 'Product A', '1.00', '10', 'active', '2026-08-01T06:00:00Z'],
            ['SKU-B', 'Product B', 'N/A', '10', 'active', '2026-08-01T06:00:00Z'], // bad price
            ['SKU-C', 'Product C', '2.00', '5', 'active', '2026-08-01T06:00:00Z'],
        ]);

        $report = $engine->sync($csv, apply: true, batchId: 'batch-1');

        $counts = $report->counts();
        $this->assertSame(2, $counts[ChangeType::CREATED]);
        $this->assertSame(1, $counts[ChangeType::REJECTED]);
        $this->assertCount(2, $repo->all(), 'the two valid rows must still be applied');
        unlink($csv);
    }

    public function testEveryAppliedChangeLeavesAnAuditTrail(): void
    {
        $repo = ProductRepository::inMemory();
        $engine = new SyncEngine($repo);

        $day1 = $this->csv([
            ['SKU-A', 'Product A', '1.00', '10', 'active', '2026-08-01T06:00:00Z'],
        ]);
        $engine->sync($day1, apply: true, batchId: 'day-1');

        $day2 = $this->csv([
            ['SKU-A', 'Product A', '1.10', '25', 'active', '2026-08-02T06:00:00Z'],
        ]);
        $engine->sync($day2, apply: true, batchId: 'day-2');

        $audit = $repo->auditFor('SKU-A');
        // day-1 creates one audit row (no field-level diff on creation);
        // day-2 changes price_net and stock_qty, so it writes one row per changed field.
        $this->assertCount(3, $audit);
        unlink($day1);
        unlink($day2);
    }
}
