# Technical plan: backtest.notquitehuman.new

## Vision

Backtesting UK equity strategies against Yahoo's historical data is worth
building up properly, not just running as a one-off CLI script. The site
already has a working "full lab" (live rebalance tool, strategy-comparison
page, custom parameter re-runs) sat on top of the momentum-rotation and
MACD/RSI/ADX engines ported from the `Playground/backtest` prototype. The
piece worth investing in next is the data layer underneath all of it: instead
of fetching a specific date range/interval from Yahoo on demand and caching
the raw response per-query, store daily OHLCV history permanently per ticker
and derive whatever weekly/monthly view a strategy needs from it. That turns
"backtesting" from a script that re-downloads its inputs every run into an
actual growing dataset the site owns.

Everything here stays in service of the existing constraint: reporting must
stay honest and outlier-resistant (medians/hit-rates/concentration, not just
means) and must never oversell a backtest as a guarantee of future returns.

## Current state (already built, uncommitted)

A working Slim 4 app already exists at this path, mirroring
`notquitehuman.new`'s conventions (plain-PHP config, no ORM, PDO + Phinx,
hand-wired DI, PSR-15 bearer/cookie auth). Nothing is committed to git yet —
this is the first thing to do once the plan below is agreed.

- **Auth**: `users` / `auth_tokens` tables, `AuthController` /
  `AuthTokenService` / `UserRepository` / `TokenAuthMiddleware`, login form at
  `/login`, cookie `backtest_token`. `bin/create-user.php` creates the one
  login user by hand (no self-serve registration — this is a personal tool).
- **Dashboard** (`/`): live rebalance tool, fixed at Top5/12mo/regime-on (the
  config validated in the CLI backtest). Shows target vs. current holdings
  (`momentum_holdings` table) and BUY/SELL/HOLD instructions; "Apply" writes
  the new target as current holdings.
- **Lab** (`/lab`, `/lab/custom`): a cached strategy-comparison grid (Buy &
  Hold vs. 6 MACD/RSI/ADX variants, Daily/Weekly, full ticker universe — run
  on demand via a button, cached in `backtest_runs` since it's ~66 live
  fetches) and a custom momentum-rotation form (topN/lookback/regime,
  uncached, runs the full 2008-onward history live).
- **Domain code** (`src/Backtest/`): `MomentumRotationBacktester`,
  `Backtester`, `MACDCalculator`/`RSICalculator`/`ADXCalculator`, the six
  `Strategies\*`, `TickerUniverse`, `YahooFinanceCollector`,
  `YahooFinanceData` — ported directly from the prototype, tests carried over.
- **Data today**: `YahooFinanceCollector::fetch($symbol, $start, $end,
  $interval)` checks for an exact-match cache file
  (`var/price-cache/{symbol}_{start}_{end}_{interval}.csv`) and re-downloads
  the whole range from Yahoo's undocumented chart API if it misses. This is
  why the cache directory already has 5+ overlapping files per ticker (each
  caller's slightly different date range/interval is a cache miss) — it works
  for a script run occasionally, but doesn't accumulate into a dataset and
  every cold cache means dozens of Yahoo requests back to 2008.
- **Docker**: `docker-compose.yml` defines `php` (custom Dockerfile,
  php:8.5-fpm) + `nginx` (port `9091:80` locally) — deliberately no `db`
  service, since this site shares the `notquitehuman.new` stack's MariaDB
  server rather than getting its own container. `DB_NAME=notquitehuman_backtest`,
  `DB_USER=backtest`, connecting via `host.docker.internal:3306`.
- **Deploy**: `bin/deploy.sh` mirrors the main site's script (git fetch +
  ff-only merge + Twig cache clear), assuming a `backtest_notquitehuman`
  container on the server — that container doesn't exist yet.

## The main piece of work: a real price history store

Replace the exact-match file cache with a `price_history` table that holds
one row per (symbol, date) forever, plus a sync step that only fetches what's
missing. Aggregation to weekly/monthly stays a pure function over whatever
daily rows are in the table — no separate weekly/monthly storage.

### Schema (new Phinx migration, `0005_price_history.php`)

```php
$table = $this->table('price_history');
$table->addColumn('symbol', 'string', ['limit' => 16])   // Yahoo symbol, e.g. "VOD.L", "^FTSE"
      ->addColumn('date', 'date')
      ->addColumn('open', 'decimal', ['precision' => 12, 'scale' => 4])
      ->addColumn('high', 'decimal', ['precision' => 12, 'scale' => 4])
      ->addColumn('low', 'decimal', ['precision' => 12, 'scale' => 4])
      ->addColumn('close', 'decimal', ['precision' => 12, 'scale' => 4])
      ->addColumn('adj_close', 'decimal', ['precision' => 12, 'scale' => 4])
      ->addColumn('volume', 'biginteger')
      ->addIndex(['symbol', 'date'], ['unique' => true, 'name' => 'idx_symbol_date'])
      ->create();
```

Store the Yahoo symbol directly (not the LSE-style ticker) so there's no
ambiguity at the storage layer — `TickerUniverse::toYahooSymbol()` stays the
one place that mapping lives, used only when deciding what to sync.

### New/changed classes

- **`YahooFinanceClient`** (rename/trim of `YahooFinanceCollector`): just the
  HTTP call + JSON parse, no file caching. `fetchRange($symbol, $start, $end):
  YahooFinanceData[]`, always daily — weekly/monthly are derived locally, so
  the client only ever needs `1d` from Yahoo.
- **`PriceHistoryRepository`**: `getDailyCloses($symbol, $start, $end):
  YahooFinanceData[]` (reads from `price_history`), `latestDateFor($symbol):
  ?string`, `upsertDaily($symbol, YahooFinanceData[] $prices)` (batched
  insert, `ON DUPLICATE KEY UPDATE` on MySQL / `INSERT OR REPLACE` on SQLite —
  keep this behind one repository method so the engine-specific SQL doesn't
  leak into callers, per the portability constraint).
- **`PriceSyncService`**: `sync($symbol)` — looks up `latestDateFor`, fetches
  only from (latest + 1 day) to today via `YahooFinanceClient`, upserts. First
  run for a symbol with no rows backfills from 2008-01-01. `syncAll()` walks
  `TickerUniverse` + `^FTSE`.
- **`PriceAggregator`**: pure functions over `YahooFinanceData[]` —
  `toMonthly()` (already exists inline as `buildMonthlyCloses()` in
  `MomentumRotationBacktester` — extract it here so both the momentum
  backtester and the MACD/RSI/ADX weekly view can share it) and a new
  `toWeekly()` (last close per ISO week), replacing the direct `interval=1wk`
  Yahoo request in `StrategyComparisonService`.
- **`MomentumRotationService`/`StrategyComparisonService`**: swap their
  `YahooFinanceCollector` dependency for `PriceHistoryRepository` (reads only —
  syncing is a separate, explicit step, not something a page load triggers).

### Sync mechanism

- `bin/sync-prices.php` — CLI, calls `PriceSyncService::syncAll()`, prints
  what it fetched per symbol. Run manually at first.
- Once that's proven out, a host cron (`0 7 * * 1-5`, after Yahoo's data for
  the prior UK trading day has settled) running
  `docker exec backtest_notquitehuman php bin/sync-prices.php` — a manual step
  for you to add to the server's crontab, not something I can install myself.
- Politeness/rate-limit: Yahoo's chart endpoint is undocumented and
  unauthenticated — `syncAll()` should have a small sleep between symbols
  (e.g. 250ms) and a retry-with-backoff on non-200s, so a ~35-symbol daily
  sync doesn't look like abuse.
- Gaps (UK bank holidays, Yahoo occasionally missing a day) are handled the
  same way `YahooFinanceCollector::parseData()` already does — skip nulls
  rather than casting to 0 — so `price_history` just won't have a row for a
  day that never had a close.

### Migration path for existing cached data

The `var/price-cache/*.csv` files already hold real fetched history (some
back to 2008/2000). Write a one-off `bin/import-legacy-cache.php` that parses
every cache file once, dedupes by (symbol, date) keeping the widest range,
and upserts into `price_history` — this seeds the table without re-hitting
Yahoo for data already sitting on disk. Delete `var/price-cache/` once that's
run and confirmed (it's already gitignored via `/var/`, so this is purely a
local cleanup, not a repo change).

## Ticker universe

`TickerUniverse::blueChipsAndIndexTrackers()` currently lists 32 of "the full
~90 blue chips" the prototype's docblock mentions. Growing this is now cheap
— it's just more symbols for `syncAll()` to pull in — but do it deliberately
in a follow-up rather than as part of this data-layer change, so a universe
change and a storage-architecture change aren't both landing in the same
diff.

## Deployment — what's left

Infrastructure pieces still needed, all of which need action from you (I
don't self-manage servers, DNS, or databases):

1. **MariaDB schema + user** on the shared server (run once, on the DB host):
   ```sql
   CREATE DATABASE notquitehuman_backtest CHARACTER SET utf8mb4;
   CREATE USER 'backtest'@'%' IDENTIFIED BY '<pick a password>';
   GRANT ALL PRIVILEGES ON notquitehuman_backtest.* TO 'backtest'@'%';
   ```
   Then set `BACKTEST_DB_PASS` in the server's shell/env (docker-compose.yml
   already reads it from there, not from a committed file).
2. **Production container**: `bin/deploy.sh` assumes a
   `backtest_notquitehuman` container already exists — that's created once by
   running this repo's `docker-compose up -d` on the server, same pattern as
   the main site.
3. **DNS**: `backtest.notquitehuman.co.uk` → your server's IP (A record) —
   manual, at your registrar.
4. **Reverse proxy / vhost**: whatever's in front of `notquitehuman.new` in
   production (nginx host-level vhost, or similar) needs a second server
   block for `backtest.notquitehuman.co.uk` proxying to this stack's nginx
   container — I'd need to see that host-level config to draft the block, but
   applying it is a manual step on the server either way.
5. **Link from the main site**: once the subdomain resolves, add a nav link
   in `notquitehuman.new`'s `templates/base.html.twig` pointing at
   `https://backtest.notquitehuman.co.uk`.

## Suggested order of work

1. Commit the current scaffold as-is (nothing's in git yet) so the data-layer
   change below has a clean diff against a known baseline.
2. `price_history` migration + `PriceHistoryRepository` +
   `PriceAggregator` (extract `toMonthly()`, add `toWeekly()`) + tests.
3. `YahooFinanceClient` (trim `YahooFinanceCollector` down) + `PriceSyncService`
   + `bin/sync-prices.php`, tested against a couple of symbols manually.
4. `bin/import-legacy-cache.php` to seed `price_history` from the existing
   CSVs; run it once; delete `var/price-cache/`.
5. Repoint `MomentumRotationService`/`StrategyComparisonService` at
   `PriceHistoryRepository` instead of `YahooFinanceCollector`; re-run the
   Lab's comparison and custom-run pages to confirm the numbers match what
   the CLI prototype produced (same inputs, same engine — this should be a
   no-op change in output, which is the test that the swap is correct).
6. Server-side steps (DB schema/user, container, DNS, vhost) — handed to you
   as a checklist when we get there.
7. Main-site nav link.
8. Later: grow the ticker universe, a cron for daily sync, walk-forward
   validation, whatever's next.
