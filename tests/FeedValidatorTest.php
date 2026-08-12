<?php

declare(strict_types=1);

namespace ErpSync\Tests;

use ErpSync\FeedValidator;
use ErpSync\ValidationException;

final class FeedValidatorTest extends TestCase
{
    private function validator(): FeedValidator
    {
        return new FeedValidator();
    }

    /** @param array<string,string> $overrides @return array<string,string> */
    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'BOLT-M8X40',
            'name' => 'Steel Hex Bolt M8x40',
            'price_net' => '0.18',
            'stock_qty' => '12000',
            'status' => 'active',
            'updated_at' => '2026-08-01T06:00:00Z',
        ], $overrides);
    }

    public function testValidRowParsesIntoProduct(): void
    {
        $product = $this->validator()->validate($this->baseRow());
        $this->assertSame('BOLT-M8X40', $product->sku);
        $this->assertSame('Steel Hex Bolt M8x40', $product->name);
        $this->assertSame(0.18, $product->priceNet);
        $this->assertSame(12000, $product->stockQty);
        $this->assertSame('active', $product->status);
    }

    public function testMissingSkuIsRejected(): void
    {
        $this->expectValidationError($this->baseRow(['sku' => '']), 'sku');
    }

    public function testSkuTooShortIsRejected(): void
    {
        $this->expectValidationError($this->baseRow(['sku' => 'XX']), 'sku');
    }

    public function testNonNumericPriceIsRejected(): void
    {
        $this->expectValidationError($this->baseRow(['price_net' => 'N/A']), 'price_net');
    }

    public function testZeroPriceIsRejected(): void
    {
        $this->expectValidationError($this->baseRow(['price_net' => '0']), 'price_net');
    }

    public function testNegativeStockIsRejected(): void
    {
        $this->expectValidationError($this->baseRow(['stock_qty' => '-5']), 'stock_qty');
    }

    public function testNonIntegerStockIsRejected(): void
    {
        $this->expectValidationError($this->baseRow(['stock_qty' => '12.5']), 'stock_qty');
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectValidationError($this->baseRow(['status' => 'discontinued']), 'status');
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->expectValidationError($this->baseRow(['updated_at' => 'not-a-date']), 'updated_at');
    }

    /** @param array<string,string> $row */
    private function expectValidationError(array $row, string $expectedSubstring): void
    {
        try {
            $this->validator()->validate($row);
        } catch (ValidationException $e) {
            $this->assertStringContainsString($expectedSubstring, $e->getMessage());
            return;
        }
        throw new \RuntimeException('Expected a ValidationException but none was thrown.');
    }
}
