<?php

declare(strict_types=1);

namespace ErpSync\Tests;

use ErpSync\ChangeResult;
use ErpSync\ChangeType;
use ErpSync\ReconciliationReport;

final class ReconciliationReportTest extends TestCase
{
    private function sampleReport(): ReconciliationReport
    {
        return new ReconciliationReport([
            new ChangeResult('SKU-A', ChangeType::CREATED),
            new ChangeResult('SKU-B', ChangeType::UPDATED, null, ['priceNet' => [1.0, 1.1]]),
            new ChangeResult('SKU-C', ChangeType::UNCHANGED),
            new ChangeResult('SKU-D', ChangeType::FLAGGED, 'price change of 90% exceeds the 40% review threshold'),
            new ChangeResult('SKU-E', ChangeType::REJECTED, 'invalid price_net'),
            new ChangeResult('SKU-F', ChangeType::STALE, 'incoming updated_at is not newer than stored'),
        ]);
    }

    public function testCountsMatchTheResultSet(): void
    {
        $counts = $this->sampleReport()->counts();
        $this->assertSame(1, $counts[ChangeType::CREATED]);
        $this->assertSame(1, $counts[ChangeType::UPDATED]);
        $this->assertSame(1, $counts[ChangeType::UNCHANGED]);
        $this->assertSame(1, $counts[ChangeType::FLAGGED]);
        $this->assertSame(1, $counts[ChangeType::REJECTED]);
        $this->assertSame(1, $counts[ChangeType::STALE]);
    }

    public function testCountsSumToTotalResults(): void
    {
        $report = $this->sampleReport();
        $this->assertSame(count($report->results()), array_sum($report->counts()));
    }

    public function testByTypeFiltersCorrectly(): void
    {
        $flagged = $this->sampleReport()->byType(ChangeType::FLAGGED);
        $this->assertCount(1, $flagged);
        $this->assertSame('SKU-D', $flagged[0]->sku);
    }

    public function testToJsonRoundTripsCounts(): void
    {
        $decoded = json_decode($this->sampleReport()->toJson(), true);
        $this->assertSame(6, $decoded['total']);
        $this->assertSame(1, $decoded['counts'][ChangeType::CREATED]);
    }

    public function testToTextIncludesEverySkuWithAnIssue(): void
    {
        $text = $this->sampleReport()->toText();
        $this->assertStringContainsString('SKU-D', $text);
        $this->assertStringContainsString('SKU-E', $text);
        $this->assertStringContainsString('SKU-F', $text);
    }
}
