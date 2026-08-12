<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use ErpSync\ProductRepository;
use ErpSync\SyncEngine;

/**
 * ERP -> webshop product sync CLI.
 *
 * Usage:
 *   php bin/sync.php --feed=data/erp_feed_baseline.csv --db=var/webshop.sqlite
 *   php bin/sync.php --feed=data/erp_feed_baseline.csv --db=var/webshop.sqlite --apply
 *   php bin/sync.php --feed=data/erp_feed_update.csv   --db=var/webshop.sqlite --apply --force=TAPE-PKG-48
 *   php bin/sync.php --feed=data/erp_feed_update.csv   --db=var/webshop.sqlite --json
 *
 * Dry-run (no --apply) never writes to the database; it only classifies and
 * reports what would happen. --force=<sku>[,<sku>...] lets an operator apply
 * a flagged (anomalous) change anyway, after reviewing it by hand.
 *
 * Exit codes: 0 = clean sync, 2 = sync ran but something needs a human
 * (flagged or rejected rows present), 1 = usage error / crash.
 */

$opts = getopt('', ['feed:', 'db:', 'apply', 'json', 'force:', 'batch:']);

if (!isset($opts['feed'], $opts['db'])) {
    fwrite(STDERR, "Usage: php bin/sync.php --feed=<csv> --db=<sqlite-file> [--apply] [--json] [--force=SKU,SKU]\n");
    exit(1);
}

$feed = (string) $opts['feed'];
$dbPath = (string) $opts['db'];
$apply = array_key_exists('apply', $opts);
$json = array_key_exists('json', $opts);
$forceSkus = isset($opts['force']) ? array_map('trim', explode(',', (string) $opts['force'])) : [];
$batchId = isset($opts['batch']) ? (string) $opts['batch'] : basename($feed) . '@' . date(DATE_ATOM);

$repository = ProductRepository::file($dbPath);
$engine = new SyncEngine($repository);

$report = $engine->sync($feed, $apply, $batchId, $forceSkus);

if ($json) {
    echo $report->toJson() . "\n";
} else {
    echo ($apply ? 'APPLY' : 'DRY RUN') . "  feed={$feed}  db={$dbPath}  batch={$batchId}\n";
    echo str_repeat('-', 70) . "\n";
    echo $report->toText() . "\n";
}

$counts = $report->counts();
exit(($counts['rejected'] > 0 || $counts['flagged'] > 0) ? 2 : 0);
