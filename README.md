# erp-webshop-sync

A small, dependency-free PHP tool that syncs a daily ERP/MIS product-master
feed into a webshop's product table safely: validated, idempotent, with
anomalous changes quarantined for a human instead of applied blind, and every
applied change written to an audit trail.

This is a generic, from-scratch demo built to show the pattern behind
real ERP-to-webshop integration work, not client code. The fictional
"Riverside Wholesale" catalogue below is made up for this repository.

## Why

A recurring failure mode in ERP-to-webshop integrations is not the happy
path, it is what happens when the feed itself is wrong: a decimal point
shifted on the ERP side, a re-sent export that is older than what the
webshop already has, a row with a negative stock quantity from a bad export
job. A sync script that blindly upserts whatever the feed says will publish
that error straight to the storefront. The fix is not cleverness, it is
discipline: validate every row, compare against what is already stored,
apply only what is safe, and put anything unusual in front of a person
before it reaches a customer.

## Features

- **Row-level validation**: SKU format, price range, non-negative integer
  stock, allowed status values, parseable date. A bad row is rejected and
  reported; it never aborts the rest of the batch.
- **Stale-feed guard**: an incoming row is only applied if its `updated_at`
  is newer than what is already stored for that SKU, so accidentally
  re-running an old export can never regress newer data.
- **Anomaly quarantine**: a price swing past a configurable threshold
  (default 40%) is flagged and left untouched instead of auto-applied. An
  operator can approve it explicitly with `--force=SKU`, and the override is
  recorded in the audit trail.
- **Idempotent by construction**: running the same feed twice changes
  nothing on the second run. Proven by a test, not just claimed.
- **Full audit trail**: every applied create/update writes a field-level
  before/after row (SKU, batch, field, old value, new value, reason,
  timestamp). Flagged and rejected rows are never silently dropped, they are
  reported instead.
- **Dry-run by default**: classification and reporting happen every run;
  nothing is written to the database unless `--apply` is passed.
- **Real SQL**: SQLite via PDO, prepared statements, an `ON CONFLICT`
  upsert, and a transaction wrapping the whole batch (rolled back on any
  unexpected error). No ORM in the way.
- **Zero Composer dependencies**: a ~15-line PSR-4-style autoloader in
  `bootstrap.php` is the only "framework" involved. Clone it and run it.

## Requirements

- PHP 8.1+ (uses readonly properties, enums-style constants, and
  constructor-default `new` expressions).
- The `pdo_sqlite` extension. This ships with PHP and is enabled by default
  on most Linux/macOS installs. On some Windows stacks (XAMPP/Laragon) it is
  present but commented out in `php.ini`; either uncomment
  `extension=pdo_sqlite`, or run once with:

  ```bash
  php -d extension=pdo_sqlite bin/sync.php ...
  ```

## Quick start

```bash
# 1. Dry run against an empty webshop: shows what would happen, writes nothing.
php bin/sync.php --feed=data/erp_feed_baseline.csv --db=var/webshop.sqlite

# 2. Apply it for real.
php bin/sync.php --feed=data/erp_feed_baseline.csv --db=var/webshop.sqlite --apply

# 3. The next day's ERP export: price changes, a restock, a discontinued item,
#    one anomalous price drop, one invalid row, one stale resend, one new SKU.
php bin/sync.php --feed=data/erp_feed_update.csv --db=var/webshop.sqlite --apply

# 4. An operator reviews the flagged anomaly and approves it explicitly.
php bin/sync.php --feed=data/erp_feed_update.csv --db=var/webshop.sqlite --apply --force=TAPE-PKG-48
```

### Captured output

Step 2, applying the 12-row baseline catalogue to an empty database:

```
APPLY  feed=data/erp_feed_baseline.csv  db=var/webshop.sqlite  batch=...
----------------------------------------------------------------------
created=12  updated=0  unchanged=0  stale=0  flagged=0  rejected=0  (total=12)
```

Re-applying that exact same file a second time (idempotency proof, no
`--apply` output was skipped, no data changed):

```
created=0  updated=0  unchanged=0  stale=12  flagged=0  rejected=0  (total=12)

STALE:
  BOLT-M8X40 - incoming updated_at (2026-08-01T06:00:00+00:00) is not newer than stored (2026-08-01T06:00:00+00:00)
  ...
```

Step 3, applying the next day's update feed to the same database:

```
created=1  updated=4  unchanged=5  stale=1  flagged=1  rejected=2  (total=14)

CREATED:
  TAPE-DUCT-48

UPDATED:
  BOLT-M8X40 - priceNet, stockQty
  GLOVE-SAFETY-L - stockQty
  PAPER-A4-80 - status
  STRAP-PALLET-BLK - priceNet

FLAGGED:
  TAPE-PKG-48 - price change of 90% (3.9 -> 0.39) exceeds the 40% review threshold

STALE:
  WASHER-M8 - incoming updated_at (2026-07-15T06:00:00+00:00) is not newer than stored (2026-08-01T06:00:00+00:00)

REJECTED:
  LABEL-SHIP-100 - LABEL-SHIP-100: stock_qty must be a non-negative integer: '-40'
  xx - invalid or missing sku: 'xx'
```

`TAPE-PKG-48`'s feed price is 0.39 against a stored 3.90, a 90% drop that is
almost certainly a misplaced decimal on the ERP side. It is flagged, not
applied. The CLI exits with code 2 (clean sync = 0, needs-a-human = 2, crash
= 1) so this is easy to wire into a monitoring check.

Step 4, an operator has looked at `TAPE-PKG-48` and decided the price drop is
real (a genuine clearance), so they approve it by SKU:

```
UPDATED:
  TAPE-PKG-48 - anomaly override forced by operator
```

The audit trail for that SKU then shows both events, queryable with plain
SQL:

```
TAPE-PKG-48 | ...baseline...  | created | (initial load)
TAPE-PKG-48 | ...update...    | updated | priceNet | 3.9 | 0.39 | anomaly override forced by operator
```

Nothing about this price change is invisible: it was flagged, it was
reviewed, it was approved by name, and the before/after value is on record.

## How it works

```
CSV row -> FeedValidator -> Product
              |                |
              | reject         v
              v          compare to stored row (ProductRepository)
          REJECTED              |
                    +-----------+-----------+
                    |                       |
             not newer than          newer than stored
             stored (stale)                 |
                    |                  AnomalyPolicy
                    v                       |
                 STALE            +---------+---------+
                                   |                   |
                              safe change         flagged, unless
                                   |                --force=SKU
                                   v                   |
                          CREATED / UPDATED       FLAGGED / (forced)
                                   |                   |
                          --apply: upsert +     --apply: upsert +
                          audit row(s)          audit row (reason
                                                  = forced override)
```

Every row is classified independently; one bad or anomalous row never blocks
the rest of the batch. In `--apply` mode the whole batch runs inside one SQL
transaction, rolled back if anything unexpected throws partway through.

## Data model

```sql
products (
  sku PRIMARY KEY, name, price_net, stock_qty, status,
  source_updated_at,   -- from the ERP feed; drives the stale-feed guard
  synced_at            -- when this webshop row was last written
)

product_audit (
  id, sku, batch_id, change_type, field, old_value, new_value, reason, changed_at
)
```

One `product_audit` row per changed field on every applied create or update.
Flagged and rejected rows write no audit row and no product row; they exist
only in that run's report, which is the point, nothing questionable reaches
storage silently.

## Anomaly policy

`AnomalyPolicy` compares the incoming price against the currently stored
price and flags anything past a configurable relative threshold (default
40%, tuned per catalogue in a real deployment):

```php
$policy = new AnomalyPolicy(priceChangeThreshold: 0.40);
$reason = $policy->evaluate($currentProduct, $incomingProduct); // null = safe to apply
```

New products are never flagged, there is nothing to compare them against.
This one rule catches the failure mode that actually matters here (a
corrupted price on the ERP side) without trying to model every possible
anomaly, which would just be over-engineering for what this demo needs to
prove.

## Tests

No PHPUnit, no Composer: a ~40-line custom assertion base class
(`tests/TestCase.php`) and a runner that discovers `tests/*Test.php`.

```bash
php tests/run.php
```

```
28/28 tests passed
```

What the suite actually proves, not just exercises:

- **Validation**: every rejected-row rule (bad SKU, non-numeric or
  out-of-range price, negative/non-integer stock, invalid status, unparsable
  date) has a dedicated test.
- **Anomaly policy**: small changes pass, large price swings in either
  direction are flagged, the threshold is configurable, and a boundary case
  well clear of the cutoff is not a false positive.
- **Idempotency**: re-running an identical feed produces zero creates and
  zero updates (`testReRunningTheIdenticalFeedIsANoop`).
- **Stale-feed guard**: an older, re-sent row can never overwrite newer
  stored data, even when its values would otherwise look like a legitimate
  change (`testStaleRowNeverOverwritesNewerStoredData`).
- **Anomaly quarantine**: a flagged change is provably not written to the
  database, and provably has no audit row of its own beyond whatever already
  existed (`testAnomalousPriceChangeIsFlaggedAndNotApplied`).
- **Forced override**: `--force` applies a flagged change and the override
  reason is recorded (`testForcedOverrideAppliesAFlaggedChange`).
- **Dry-run isolation**: a dry run classifies correctly but writes nothing
  (`testDryRunNeverWritesToTheDatabase`).
- **Batch resilience**: one invalid row is rejected without aborting the
  valid rows around it (`testInvalidRowIsRejectedAndDoesNotAbortTheBatch`).
- **Audit completeness**: every applied change leaves exactly the expected
  field-level audit rows (`testEveryAppliedChangeLeavesAnAuditTrail`).

## Project structure

```
bootstrap.php              zero-dependency PSR-4-style autoloader + extension check
bin/sync.php                CLI entry point
src/
  Product.php                immutable value object + field diff
  FeedValidator.php           raw CSV row -> Product, or a ValidationException
  AnomalyPolicy.php            flags unsafe price swings
  ChangeType.php / ChangeResult.php   classification vocabulary
  ProductRepository.php        PDO/SQLite storage: schema, upsert, audit log
  SyncEngine.php                orchestrates read -> validate -> classify -> apply
  ReconciliationReport.php      counts + text/JSON reporting
data/
  erp_feed_baseline.csv        fictional day-1 catalogue (12 SKUs)
  erp_feed_update.csv          fictional day-2 feed exercising every code path
tests/
  TestCase.php, run.php        the micro test framework
  *Test.php                    28 test methods across validation, policy, engine, report
```

## Honest scope

This is a from-scratch, sanitized demo sized to show the integration
pattern end to end: validation, idempotency, anomaly handling, audit,
dry-run-first operation. It is not a full webshop or ERP connector. Left out
on purpose, because a real deployment needs them wired to actual
infrastructure rather than demonstrated in isolation: fetching the feed over
SFTP, notifying a human when something is flagged or rejected (Slack/email),
multi-currency and tax handling, and a MySQL/PostgreSQL backend for a
production-scale catalogue instead of a single SQLite file.

## Author

**Filip Radetić**: Business Process Automation & Applied AI Engineer. I
build and run production automation and integration systems: this repo
covers the ERP/webshop/product-data side of that work;
[multi-llm-failover](https://github.com/filipradetic-afk/multi-llm-failover)
covers multi-provider LLM orchestration, and
[realtime-analytics-pipeline](https://github.com/filipradetic-afk/realtime-analytics-pipeline)
covers data-platform architecture at scale.

- GitHub: https://github.com/filipradetic-afk
- LinkedIn: https://www.linkedin.com/in/filip-radetic
- Portfolio: https://aiagencydx.com
- Email: filip.radetic@gmail.com

Based in Tuttlingen, Germany. EU citizen. Working languages: English (C1),
Croatian (native), German (A2, improving).

## License

MIT, see [LICENSE](LICENSE).
