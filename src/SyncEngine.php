<?php

declare(strict_types=1);

namespace ErpSync;

/**
 * Orchestrates one ERP feed sync: read -> validate -> compare -> classify ->
 * (apply mode only) write + audit. Nothing reaches the products table without
 * passing validation, the stale-feed guard, and the anomaly policy first.
 */
final class SyncEngine
{
    public function __construct(
        private readonly ProductRepository $repository,
        private readonly FeedValidator $validator = new FeedValidator(),
        private readonly AnomalyPolicy $anomalyPolicy = new AnomalyPolicy()
    ) {
    }

    /**
     * @param string[] $forceSkus SKUs whose flagged anomaly should still be
     *   applied: an explicit human override, recorded in the audit trail.
     */
    public function sync(string $csvPath, bool $apply, string $batchId, array $forceSkus = []): ReconciliationReport
    {
        $results = [];
        $rows = $this->readCsv($csvPath);

        if ($apply) {
            $this->repository->beginTransaction();
        }

        try {
            foreach ($rows as $row) {
                $results[] = $this->processRow($row, $apply, $batchId, $forceSkus);
            }
            if ($apply) {
                $this->repository->commit();
            }
        } catch (\Throwable $e) {
            if ($apply) {
                $this->repository->rollBack();
            }
            throw $e;
        }

        return new ReconciliationReport($results);
    }

    /**
     * @param array<string,string> $row
     * @param string[] $forceSkus
     */
    private function processRow(array $row, bool $apply, string $batchId, array $forceSkus): ChangeResult
    {
        try {
            $incoming = $this->validator->validate($row);
        } catch (ValidationException $e) {
            return new ChangeResult($row['sku'] ?? '(missing)', ChangeType::REJECTED, $e->getMessage());
        }

        $current = $this->repository->findBySku($incoming->sku);

        if ($current !== null && $incoming->sourceUpdatedAt <= $current->sourceUpdatedAt) {
            return new ChangeResult(
                $incoming->sku,
                ChangeType::STALE,
                "incoming updated_at ({$incoming->sourceUpdatedAt}) is not newer than stored ({$current->sourceUpdatedAt})"
            );
        }

        $forced = in_array($incoming->sku, $forceSkus, true);
        if ($current !== null && !$forced) {
            $anomalyReason = $this->anomalyPolicy->evaluate($current, $incoming);
            if ($anomalyReason !== null) {
                return new ChangeResult($incoming->sku, ChangeType::FLAGGED, $anomalyReason);
            }
        }

        if ($current === null) {
            $type = ChangeType::CREATED;
            $diffs = [];
        } else {
            $diffs = $current->diff($incoming);
            $type = $diffs === [] ? ChangeType::UNCHANGED : ChangeType::UPDATED;
        }

        $reason = ($forced && $type !== ChangeType::UNCHANGED) ? 'anomaly override forced by operator' : null;

        if ($apply && in_array($type, [ChangeType::CREATED, ChangeType::UPDATED], true)) {
            $this->repository->upsert($incoming);
            $this->repository->appendAudit($incoming->sku, $batchId, $type, $diffs, $reason);
        }

        return new ChangeResult($incoming->sku, $type, $reason, $diffs);
    }

    /** @return array<int,array<string,string>> */
    private function readCsv(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("feed file not found: {$path}");
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("could not open feed file: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null]) {
                continue; // trailing blank line
            }
            /** @var array<string,string> $combined */
            $combined = array_combine($header, $line);
            $rows[] = $combined;
        }
        fclose($handle);
        return $rows;
    }
}
