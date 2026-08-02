<?php

namespace App\Service;

use App\Backtest\TickerUniverse;
use App\Backtest\YahooFinanceClient;
use App\Repository\PriceHistoryRepository;
use App\Repository\TickerRepository;

/**
 * Keeps price_history up to date. A sync only ever fetches from the day
 * after whatever's already stored for a symbol through to today; the first
 * sync for a brand-new symbol backfills from LONG_HISTORY_START instead.
 */
class PriceSyncService
{
    // Matches the validated backtest window (covers the 2008 and 2020 crashes).
    private const LONG_HISTORY_START = '2008-01-01';

    private const REGIME_INDEX_SYMBOL = '^FTSE';

    public function __construct(
        private YahooFinanceClient $client,
        private PriceHistoryRepository $repository,
        private TickerRepository $tickerRepository,
        // Yahoo's chart endpoint is undocumented and unauthenticated — a
        // short pause between requests keeps a full-universe daily sync
        // from looking like abuse. Tests pass 0 so they don't sleep.
        private int $delayMicroseconds = 250_000,
    ) {}

    /**
     * @return int Number of daily rows fetched and stored for this symbol
     */
    public function sync(string $symbol): int
    {
        $latest = $this->repository->latestDateFor($symbol);
        $start = $latest !== null
            ? date('Y-m-d', strtotime($latest . ' +1 day'))
            : self::LONG_HISTORY_START;
        $end = date('Y-m-d');

        if ($start > $end) {
            return 0; // already synced today
        }

        $prices = $this->client->fetchRange($symbol, $start, $end);
        if (empty($prices)) {
            return 0;
        }

        $this->repository->upsertDaily($symbol, $prices);
        return count($prices);
    }

    /**
     * Syncs the full ticker universe plus the FTSE 100 regime index.
     *
     * @return array<string, int> Yahoo symbol => rows stored
     */
    public function syncAll(): array
    {
        $symbols = array_map(
            fn(string $ticker) => TickerUniverse::toYahooSymbol($ticker),
            $this->tickerRepository->all(),
        );
        $symbols[] = self::REGIME_INDEX_SYMBOL;

        $stored = [];
        foreach ($symbols as $symbol) {
            $stored[$symbol] = $this->sync($symbol);
            if ($this->delayMicroseconds > 0) {
                usleep($this->delayMicroseconds);
            }
        }

        return $stored;
    }
}
