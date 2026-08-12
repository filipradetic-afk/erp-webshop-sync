<?php

declare(strict_types=1);

namespace ErpSync;

/**
 * Decides whether an incoming ERP change is safe to auto-apply or needs a
 * human to confirm it first. New products are never flagged; there is
 * nothing to compare them against. Existing products are flagged when the
 * price swings past a configurable threshold, which is the failure mode
 * actually seen in the field: a misplaced decimal or a unit mismatch on the
 * ERP side that would otherwise land straight on the storefront.
 */
final class AnomalyPolicy
{
    public function __construct(private readonly float $priceChangeThreshold = 0.40)
    {
    }

    /** @return string|null Null when safe to auto-apply, otherwise a human-readable reason. */
    public function evaluate(?Product $current, Product $incoming): ?string
    {
        if ($current === null || $current->priceNet <= 0.0) {
            return null;
        }

        $delta = abs($incoming->priceNet - $current->priceNet) / $current->priceNet;
        if ($delta > $this->priceChangeThreshold) {
            $pct = round($delta * 100, 1);
            $limit = round($this->priceChangeThreshold * 100, 1);
            return "price change of {$pct}% ({$current->priceNet} -> {$incoming->priceNet}) "
                . "exceeds the {$limit}% review threshold";
        }

        return null;
    }
}
