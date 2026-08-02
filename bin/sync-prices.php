#!/usr/bin/env php
<?php

// Fetches whatever's new since the last sync for the full ticker universe
// plus the FTSE 100 regime index, and stores it in price_history. Run
// manually for now: php bin/sync-prices.php
// (A daily cron entry running this inside the container is a later step —
// see technical-plan.md.)

use App\Backtest\TickerUniverse;
use App\Backtest\YahooFinanceClient;
use App\Repository\PriceHistoryRepository;
use App\Service\PriceSyncService;

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

$service = new PriceSyncService(new YahooFinanceClient(), new PriceHistoryRepository($pdo));

fwrite(STDOUT, "Syncing " . (count(TickerUniverse::blueChipsAndIndexTrackers()) + 1) . " symbols...\n");

$stored = $service->syncAll();

$totalRows = 0;
foreach ($stored as $symbol => $rows) {
    if ($rows > 0) {
        fwrite(STDOUT, "  {$symbol}: {$rows} new row(s)\n");
    }
    $totalRows += $rows;
}

fwrite(STDOUT, "Done. {$totalRows} new row(s) stored across " . count($stored) . " symbols.\n");
