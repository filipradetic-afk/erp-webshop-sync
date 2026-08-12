<?php

declare(strict_types=1);

namespace ErpSync\Tests;

use ErpSync\AnomalyPolicy;
use ErpSync\Product;

final class AnomalyPolicyTest extends TestCase
{
    private function product(float $price): Product
    {
        return new Product('SKU-1', 'Test Product', $price, 100, 'active', '2026-08-01T06:00:00Z');
    }

    public function testNewProductIsNeverFlagged(): void
    {
        $policy = new AnomalyPolicy(0.40);
        $this->assertNull($policy->evaluate(null, $this->product(0.01)));
    }

    public function testSmallPriceChangeIsNotFlagged(): void
    {
        $policy = new AnomalyPolicy(0.40);
        $this->assertNull($policy->evaluate($this->product(10.00), $this->product(11.00))); // +10%
    }

    public function testLargePriceDropIsFlagged(): void
    {
        $policy = new AnomalyPolicy(0.40);
        $reason = $policy->evaluate($this->product(3.90), $this->product(0.39)); // -90%
        $this->assertNotNull($reason);
        $this->assertStringContainsString('price change', (string) $reason);
    }

    public function testLargePriceIncreaseIsFlagged(): void
    {
        $policy = new AnomalyPolicy(0.40);
        $this->assertNotNull($policy->evaluate($this->product(10.00), $this->product(25.00))); // +150%
    }

    public function testThresholdIsConfigurable(): void
    {
        $strict = new AnomalyPolicy(0.05);
        $this->assertNotNull($strict->evaluate($this->product(10.00), $this->product(10.60))); // +6%
    }

    public function testBoundaryWellUnderThresholdIsNotFlagged(): void
    {
        $policy = new AnomalyPolicy(0.40);
        // +34.2%, deliberately clear of the 40% boundary to avoid float-rounding flakiness.
        $this->assertNull($policy->evaluate($this->product(11.40), $this->product(15.30)));
    }
}
