<?php

declare(strict_types=1);

namespace ErpSync;

/** The classification outcome for a single SKU from a single sync run. */
final class ChangeResult
{
    /** @param array<string,array{0:mixed,1:mixed}> $fieldDiffs */
    public function __construct(
        public readonly string $sku,
        public readonly string $type,
        public readonly ?string $reason = null,
        public readonly array $fieldDiffs = []
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'type' => $this->type,
            'reason' => $this->reason,
            'fields_changed' => array_keys($this->fieldDiffs),
        ];
    }
}
