<?php

namespace App\Service;

use App\Backtest\ADXCalculator;
use App\Backtest\Backtester;
use App\Backtest\MACDADXPoint;
use App\Backtest\MACDCalculator;
use App\Backtest\MACDRSIPoint;
use App\Backtest\PriceRSIPoint;
use App\Backtest\RSICalculator;
use App\Backtest\Strategies\LongMACDCrossoverStrategy;
use App\Backtest\Strategies\MACDADXFilteredStrategy;
use App\Backtest\Strategies\MACDCrossoverStrategy;
use App\Backtest\Strategies\MACDRSIFilteredStrategy;
use App\Backtest\Strategies\MACDZeroLineCrossoverStrategy;
use App\Backtest\Strategies\MeanReversionRSIStrategy;
use App\Backtest\Strategies\StrategyInterface;
use App\Backtest\TickerUniverse;
use App\Backtest\YahooFinanceCollector;
use App\Backtest\YahooFinanceData;

/**
 * Web port of the CLI prototype's compare_timeframes.php: Buy & Hold vs. the
 * MACD/RSI/ADX strategy variants, across the full blue-chip/index universe,
 * Daily and Weekly. Expensive (~66 live price fetches on a cold cache) —
 * callers should run this on demand, not on every page view, and cache the
 * result (see BacktestRunRepository).
 */
class StrategyComparisonService
{
    private const BUY_COST_PERCENT = 0.6;
    private const SELL_COST_PERCENT = 0.1;
    private const START = '2008-01-01';

    private YahooFinanceCollector $collector;
    private MACDCalculator $macdCalc;
    private RSICalculator $rsiCalc;
    private ADXCalculator $adxCalc;

    public function __construct(string $cacheDir)
    {
        $this->collector = new YahooFinanceCollector($cacheDir);
        $this->macdCalc = new MACDCalculator();
        $this->rsiCalc = new RSICalculator();
        $this->adxCalc = new ADXCalculator();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meanSummary: list<array<string, mixed>>, medianSummary: list<array<string, mixed>>}
     */
    public function run(): array
    {
        /** @var array<string, StrategyInterface> $strategies */
        $strategies = [
            'MACD crossover' => new MACDCrossoverStrategy(),
            'Long-only MACD' => new LongMACDCrossoverStrategy(),
            'MACD zero-line' => new MACDZeroLineCrossoverStrategy(),
            'MACD + RSI filter' => new MACDRSIFilteredStrategy(),
            'MACD + ADX filter' => new MACDADXFilteredStrategy(),
            'RSI mean reversion' => new MeanReversionRSIStrategy(),
        ];

        $intervals = ['1d' => 'Daily', '1wk' => 'Weekly'];
        $end = date('Y-m-d');

        $rows = [];

        foreach (TickerUniverse::blueChipsAndIndexTrackers() as $ticker) {
            $symbol = TickerUniverse::toYahooSymbol($ticker);
            foreach ($intervals as $interval => $intervalLabel) {
                $prices = $this->collector->fetch($symbol, self::START, $end, $interval);
                if (count($prices) < 40) {
                    continue;
                }

                $rows[] = array_merge(
                    ['ticker' => $ticker, 'timeframe' => $intervalLabel, 'strategy' => 'Buy & Hold'],
                    $this->computeBuyAndHoldRow($prices),
                );

                $macdData = $this->macdCalc->calculate($prices);
                $rsiData = $this->rsiCalc->calculate($prices);
                $adxData = $this->adxCalc->calculate($prices);

                $macdRsiData = [];
                $macdAdxData = [];
                foreach ($macdData as $i => $point) {
                    $macdRsiData[] = new MACDRSIPoint(
                        timestamp: $point->timestamp,
                        close: $point->close,
                        macd: $point->macd,
                        signal: $point->signal,
                        rsi: $rsiData[$i]->rsi,
                    );
                    $macdAdxData[] = new MACDADXPoint(
                        timestamp: $point->timestamp,
                        close: $point->close,
                        macd: $point->macd,
                        signal: $point->signal,
                        adx: $adxData[$i]->adx,
                    );
                }

                $priceRsiData = [];
                foreach ($prices as $i => $price) {
                    $priceRsiData[] = new PriceRSIPoint(
                        timestamp: $price->timestamp,
                        close: $price->close,
                        rsi: $rsiData[$i]->rsi,
                    );
                }

                foreach ($strategies as $strategyName => $strategy) {
                    $data = match (true) {
                        $strategy instanceof MACDRSIFilteredStrategy => $macdRsiData,
                        $strategy instanceof MACDADXFilteredStrategy => $macdAdxData,
                        $strategy instanceof MeanReversionRSIStrategy => $priceRsiData,
                        default => $macdData,
                    };

                    $signals = $strategy->generateSignals($data);
                    $backtester = new Backtester(
                        buySlippagePercent: self::BUY_COST_PERCENT,
                        sellSlippagePercent: self::SELL_COST_PERCENT,
                    );
                    $result = $backtester->run($data, $signals);

                    $equity = 1.0;
                    $peak = 1.0;
                    $maxDrawdownPercent = 0.0;
                    $totalDays = 0;

                    foreach ($result->trades as $trade) {
                        $equity *= (1 + $trade->getProfitPercent() / 100);
                        $peak = max($peak, $equity);
                        $maxDrawdownPercent = max($maxDrawdownPercent, ($peak - $equity) / $peak * 100);
                        $totalDays += $trade->getDurationDays();
                    }

                    $rows[] = [
                        'ticker' => $ticker,
                        'timeframe' => $intervalLabel,
                        'strategy' => $strategyName,
                        'trades' => $result->totalTrades,
                        'winRate' => $result->winRate,
                        'compoundReturnPercent' => round(($equity - 1) * 100, 2),
                        'maxDrawdownPercent' => round($maxDrawdownPercent, 2),
                        'avgHoldingDays' => $result->totalTrades > 0 ? round($totalDays / $result->totalTrades, 1) : 0.0,
                    ];
                }
            }
        }

        $strategyNames = array_merge(['Buy & Hold'], array_keys($strategies));

        return [
            'rows' => $rows,
            // Mean-based: matches what the CLI tool originally printed.
            'meanSummary' => $this->summarise($rows, $strategyNames, 'mean'),
            // Median/hit-rate based: the outlier-resistant view — a handful of
            // huge single-trade returns can otherwise dominate a mean.
            'medianSummary' => $this->summarise($rows, $strategyNames, 'median'),
        ];
    }

    /**
     * @param YahooFinanceData[] $prices
     * @return array<string, mixed>
     */
    private function computeBuyAndHoldRow(array $prices): array
    {
        $buyPrice = $prices[0]->close * (1 + self::BUY_COST_PERCENT / 100);
        $peak = 1.0;
        $maxDrawdownPercent = 0.0;

        foreach ($prices as $p) {
            $equity = $p->close / $buyPrice;
            $peak = max($peak, $equity);
            $maxDrawdownPercent = max($maxDrawdownPercent, ($peak - $equity) / $peak * 100);
        }

        $last = end($prices);
        $sellPrice = $last->close * (1 - self::SELL_COST_PERCENT / 100);
        $returnPercent = ($sellPrice - $buyPrice) / $buyPrice * 100;
        $holdingDays = (int) round(($last->timestamp - $prices[0]->timestamp) / 86400);

        return [
            'trades' => 1,
            'winRate' => $returnPercent > 0 ? 100.0 : 0.0,
            'compoundReturnPercent' => round($returnPercent, 2),
            'maxDrawdownPercent' => round($maxDrawdownPercent, 2),
            'avgHoldingDays' => (float) $holdingDays,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param string[] $strategyNames
     * @return list<array<string, mixed>>
     */
    private function summarise(array $rows, array $strategyNames, string $mode): array
    {
        $summary = [];

        foreach ($strategyNames as $strategyName) {
            foreach (['Daily', 'Weekly'] as $timeframe) {
                $subset = array_values(array_filter(
                    $rows,
                    fn(array $r) => $r['strategy'] === $strategyName && $r['timeframe'] === $timeframe,
                ));
                $count = count($subset);
                if ($count === 0) {
                    continue;
                }

                $returns = array_column($subset, 'compoundReturnPercent');
                $profitable = count(array_filter($returns, fn(float $r) => $r > 0));

                if ($mode === 'mean') {
                    $summary[] = [
                        'strategy' => $strategyName,
                        'timeframe' => $timeframe,
                        'trades' => round(array_sum(array_column($subset, 'trades')) / $count, 1),
                        'winRate' => round(array_sum(array_column($subset, 'winRate')) / $count, 1),
                        'return' => round(array_sum($returns) / $count, 2),
                        'maxDrawdown' => round(array_sum(array_column($subset, 'maxDrawdownPercent')) / $count, 2),
                        'profitablePercent' => round($profitable / $count * 100),
                    ];
                } else {
                    sort($returns);
                    $medianReturn = $count % 2 === 1
                        ? $returns[intdiv($count, 2)]
                        : ($returns[$count / 2 - 1] + $returns[$count / 2]) / 2;

                    $summary[] = [
                        'strategy' => $strategyName,
                        'timeframe' => $timeframe,
                        'medianReturn' => round($medianReturn, 2),
                        'hitRate' => round($profitable / $count * 100),
                        'n' => $count,
                    ];
                }
            }
        }

        return $summary;
    }
}
