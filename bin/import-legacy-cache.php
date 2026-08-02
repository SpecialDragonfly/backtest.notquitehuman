#!/usr/bin/env php
<?php

// One-off: seeds price_history from the exact-match Yahoo cache files under
// var/price-cache/ (left over from before price_history existed), so the
// table starts populated without re-hitting Yahoo for data already on disk.
// Only *_1d.csv files are read — price_history is daily-only; weekly views
// are derived from it via App\Backtest\PriceAggregator.
//
// Run once: php bin/import-legacy-cache.php
// Safe to re-run — upsertDaily() replaces rows for the same (symbol, date).

use App\Backtest\TickerUniverse;
use App\Backtest\YahooFinanceClient;
use App\Backtest\YahooFinanceData;
use App\Repository\PriceHistoryRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

function env(string $key, string $default): string
{
    $envValue = $_ENV[$key] ?? null;
    if (is_string($envValue)) {
        return $envValue;
    }
    $getenvValue = getenv($key);
    return $getenvValue !== false && $getenvValue !== '' ? $getenvValue : $default;
}

if (env('DB_CONNECTION', 'sqlite') === 'mysql') {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'notquitehuman_backtest'),
    );
    $pdo = new PDO($dsn, env('DB_USER', ''), env('DB_PASS', ''));
} else {
    $pdo = new PDO('sqlite:' . dirname(__DIR__) . '/var/data.db');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cacheDir = dirname(__DIR__) . '/var/price-cache';
$client = new YahooFinanceClient();
$repository = new PriceHistoryRepository($pdo);

$symbols = array_map(
    fn(string $ticker) => TickerUniverse::toYahooSymbol($ticker),
    TickerUniverse::blueChipsAndIndexTrackers(),
);
$symbols[] = '^FTSE';

$totalImported = 0;

foreach ($symbols as $symbol) {
    // Mirrors YahooFinanceCollector::getCacheFilename()'s sanitisation, so
    // this matches exactly what was written to disk for each symbol.
    $symbolSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $symbol);
    $files = glob("{$cacheDir}/{$symbolSafe}_*_1d.csv") ?: [];

    if (empty($files)) {
        fwrite(STDOUT, "  {$symbol}: no cached daily files found, skipping\n");
        continue;
    }

    // Cache files for the same symbol often overlap (different callers asked
    // for different date ranges) — merge them keyed by date rather than
    // picking one file, since duplicates should carry identical values from
    // the same underlying Yahoo data.
    /** @var array<string, YahooFinanceData> $byDate */
    $byDate = [];
    foreach ($files as $file) {
        $json = file_get_contents($file);
        if ($json === false) {
            continue;
        }
        foreach ($client->parseJson($json) as $bar) {
            $byDate[$bar->getDate()] = $bar;
        }
    }

    $bars = array_values($byDate);
    usort($bars, fn(YahooFinanceData $a, YahooFinanceData $b) => $a->timestamp <=> $b->timestamp);

    $repository->upsertDaily($symbol, $bars);
    $totalImported += count($bars);

    fwrite(STDOUT, "  {$symbol}: imported " . count($bars) . " day(s) from " . count($files) . " cache file(s)\n");
}

fwrite(STDOUT, "Done. {$totalImported} day(s) imported across " . count($symbols) . " symbols.\n");
