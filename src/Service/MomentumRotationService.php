<?php

namespace App\Service;

use App\Backtest\MomentumRotationBacktester;
use App\Backtest\MomentumRotationResult;
use App\Backtest\TickerUniverse;
use App\Backtest\YahooFinanceCollector;

/**
 * Web-facing wrapper around the ported momentum-rotation engine
 * (App\Backtest\MomentumRotationBacktester). Same UK cost model and regime
 * settings validated in the CLI prototype's momentum_rotation.php /
 * rebalance.php.
 */
class MomentumRotationService
{
    private const BUY_COST_PERCENT = 0.6;  // 0.5% stamp duty + 0.1% spread
    private const SELL_COST_PERCENT = 0.1; // 0.1% spread
    private const REGIME_MA_MONTHS = 10;
    private const REGIME_INDEX_SYMBOL = '^FTSE';

    // Full validated backtest window (includes the 2008 and 2020 crashes).
    private const LONG_HISTORY_START = '2008-01-01';

    private YahooFinanceCollector $collector;

    public function __construct(string $cacheDir)
    {
        $this->collector = new YahooFinanceCollector($cacheDir);
    }

    /**
     * This month's target holdings — only fetches enough trailing history to
     * feed the requested lookback + the regime SMA, not the full backtest
     * range, so this stays fast enough for a dashboard page load.
     *
     * @return array{month: string, regime: string, holdings: string[]}
     */
    public function getCurrentTarget(int $topN, int $lookbackMonths, bool $regimeOn): array
    {
        $start = date('Y-m-d', strtotime('-4 years'));
        [$pricesByTicker, $regimePrices] = $this->fetchUniverse($start, $regimeOn);

        $bt = new MomentumRotationBacktester(
            topN: $topN,
            lookbackMonths: $lookbackMonths,
            buyCostPercent: self::BUY_COST_PERCENT,
            sellCostPercent: self::SELL_COST_PERCENT,
            regimeMAMonths: self::REGIME_MA_MONTHS,
        );
        $result = $bt->run($pricesByTicker, $regimePrices);

        $rebalanceLog = $result->rebalanceLog;
        $latest = end($rebalanceLog);
        if ($latest === false) {
            return ['month' => '', 'regime' => 'risk-on', 'holdings' => []];
        }

        return $latest;
    }

    /**
     * Full historical backtest (2008 onward) for a given parameter set —
     * used by the Lab's custom-run form. Not persisted.
     */
    public function runCustomBacktest(int $topN, int $lookbackMonths, bool $regimeOn): MomentumRotationResult
    {
        [$pricesByTicker, $regimePrices] = $this->fetchUniverse(self::LONG_HISTORY_START, $regimeOn);

        $bt = new MomentumRotationBacktester(
            topN: $topN,
            lookbackMonths: $lookbackMonths,
            buyCostPercent: self::BUY_COST_PERCENT,
            sellCostPercent: self::SELL_COST_PERCENT,
            regimeMAMonths: self::REGIME_MA_MONTHS,
        );

        return $bt->run($pricesByTicker, $regimePrices);
    }

    /**
     * @return array{0: array<string, \App\Backtest\YahooFinanceData[]>, 1: \App\Backtest\YahooFinanceData[]|null}
     */
    private function fetchUniverse(string $start, bool $regimeOn): array
    {
        $end = date('Y-m-d');

        $pricesByTicker = [];
        foreach (TickerUniverse::blueChipsAndIndexTrackers() as $ticker) {
            $symbol = TickerUniverse::toYahooSymbol($ticker);
            $prices = $this->collector->fetch($symbol, $start, $end, '1d');
            if (count($prices) < 60) {
                continue; // not enough history to be a useful momentum candidate
            }
            $pricesByTicker[$ticker] = $prices;
        }

        $regimePrices = $regimeOn ? $this->collector->fetch(self::REGIME_INDEX_SYMBOL, $start, $end, '1d') : null;

        return [$pricesByTicker, $regimePrices];
    }
}
