<?php

declare(strict_types=1);

namespace ErpSync;

/**
 * Immutable value object for one product row, either freshly parsed from an
 * ERP feed or hydrated back from the webshop's products table.
 */
final class Product
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly float $priceNet,
        public readonly int $stockQty,
        public readonly string $status,
        public readonly string $sourceUpdatedAt
    ) {
    }

    /**
     * Field-level diff against an incoming version of the same product.
     * Deliberately excludes sourceUpdatedAt: that field always changes on a
     * resend and is not itself a business change worth auditing.
     *
     * @return array<string,array{0:mixed,1:mixed}> field => [old, new]
     */
    public function diff(Product $incoming): array
    {
        $diffs = [];
        foreach (['name', 'priceNet', 'stockQty', 'status'] as $field) {
            $old = $this->{$field};
            $new = $incoming->{$field};
            if ($old !== $new) {
                $diffs[$field] = [$old, $new];
            }
        }
        return $diffs;
    }
}
