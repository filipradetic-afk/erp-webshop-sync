<?php

declare(strict_types=1);

namespace ErpSync;

/** Aggregates a batch of ChangeResult rows into counts and printable output. */
final class ReconciliationReport
{
    /** @param ChangeResult[] $results */
    public function __construct(private readonly array $results)
    {
    }

    /** @return ChangeResult[] */
    public function results(): array
    {
        return $this->results;
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $counts = array_fill_keys(ChangeType::ALL, 0);
        foreach ($this->results as $r) {
            $counts[$r->type]++;
        }
        return $counts;
    }

    /** @return ChangeResult[] */
    public function byType(string $type): array
    {
        return array_values(array_filter($this->results, static fn (ChangeResult $r) => $r->type === $type));
    }

    public function toText(): string
    {
        $counts = $this->counts();
        $lines = [];
        $lines[] = sprintf(
            'created=%d  updated=%d  unchanged=%d  stale=%d  flagged=%d  rejected=%d  (total=%d)',
            $counts[ChangeType::CREATED],
            $counts[ChangeType::UPDATED],
            $counts[ChangeType::UNCHANGED],
            $counts[ChangeType::STALE],
            $counts[ChangeType::FLAGGED],
            $counts[ChangeType::REJECTED],
            count($this->results)
        );

        $detailTypes = [ChangeType::CREATED, ChangeType::UPDATED, ChangeType::FLAGGED, ChangeType::STALE, ChangeType::REJECTED];
        foreach ($detailTypes as $type) {
            $rows = $this->byType($type);
            if ($rows === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = strtoupper($type) . ':';
            foreach ($rows as $r) {
                $detail = $r->reason ?? implode(', ', array_keys($r->fieldDiffs));
                $lines[] = "  {$r->sku}" . ($detail !== '' ? " - {$detail}" : '');
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts(),
            'total' => count($this->results),
            'results' => array_map(static fn (ChangeResult $r) => $r->toArray(), $this->results),
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
