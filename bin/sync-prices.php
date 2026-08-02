#!/usr/bin/env php
<?php

// Fetches whatever's new since the last sync for the full ticker universe
// plus the FTSE 100 regime index, and stores it in price_history.
// Run manually or via cron: php bin/sync-prices.php

use App\Repository\TickerRepository;
use App\Service\PriceSyncService;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$service = containerGet($container, PriceSyncService::class);
$logger = containerGet($container, LoggerInterface::class);
$tickers = containerGet($container, TickerRepository::class);

fwrite(STDOUT, "Syncing " . (count($tickers->all()) + 1) . " symbols...\n");

$stored = $service->syncAll();

$totalRows = 0;
foreach ($stored as $symbol => $rows) {
    if ($rows > 0) {
        fwrite(STDOUT, "  {$symbol}: {$rows} new row(s)\n");
    }
    $totalRows += $rows;
}

$summary = "Done. {$totalRows} new row(s) stored across " . count($stored) . " symbols.";
fwrite(STDOUT, "{$summary}\n");
$logger->info($summary, ['rows_by_symbol' => $stored]);
