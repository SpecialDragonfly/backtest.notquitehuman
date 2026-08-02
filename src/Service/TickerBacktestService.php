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
use App\Backtest\YahooFinanceData;
use App\Repository\PriceHistoryRepository;

/**
 * Single-ticker, single-strategy view: price history plus one strategy's buy/
 * sell signals, for the dashboard's chart + trade table. Same cost model and
 * history window as StrategyComparisonService, just scoped to one (ticker,
 * strategy) pair instead of the full universe grid.
 */
class TickerBacktestService
{
    private const BUY_COST_PERCENT = 0.6;
    private const SELL_COST_PERCENT = 0.1;
    private const START = '2008-01-01';
    private const MIN_BARS = 40;

    /** Strategy slug => display label, in dropdown order. */
    public const STRATEGIES = [
        'macd-crossover' => 'MACD crossover',
        'long-macd' => 'Long-only MACD',
        'macd-zero-line' => 'MACD zero-line',
        'macd-rsi' => 'MACD + RSI filter',
        'macd-adx' => 'MACD + ADX filter',
        'rsi-mean-reversion' => 'RSI mean reversion',
    ];

    public const DEFAULT_STRATEGY = 'macd-crossover';

    private MACDCalculator $macdCalc;
    private RSICalculator $rsiCalc;
    private ADXCalculator $adxCalc;

    public function __construct(private PriceHistoryRepository $priceHistoryRepository)
    {
        $this->macdCalc = new MACDCalculator();
        $this->rsiCalc = new RSICalculator();
        $this->adxCalc = new ADXCalculator();
    }

    /**
     * @return array{
     *     symbol: string,
     *     hasData: bool,
     *     chart: array{prices: list<array{t: int, c: float}>, signals: list<array{t: int, type: string, p: float}>},
     *     trades: \App\Backtest\TradeResult[],
     *     summary: array{trades: int, winRate: float, compoundReturnPercent: float, maxDrawdownPercent: float, avgHoldingDays: float}|null,
     * }
     */
    public function run(string $ticker, string $strategySlug): array
    {
        $symbol = TickerUniverse::toYahooSymbol($ticker);
        $prices = $this->loadPrices($ticker);

        if (count($prices) < self::MIN_BARS) {
            return [
                'symbol' => $symbol,
                'hasData' => false,
                'chart' => ['prices' => [], 'signals' => []],
                'trades' => [],
                'summary' => null,
            ];
        }

        $macdData = $this->macdCalc->calculate($prices);
        [$strategy, $data] = $this->buildStrategy($strategySlug, $prices, $macdData);
        $signals = $strategy->generateSignals($data);

        $backtester = new Backtester(
            buySlippagePercent: self::BUY_COST_PERCENT,
            sellSlippagePercent: self::SELL_COST_PERCENT,
        );
        $result = $backtester->run($prices, $signals);

        return [
            'symbol' => $symbol,
            'hasData' => true,
            'chart' => [
                // Plotted against adjClose, not raw close — the strategies
                // above (and their signal prices) are all computed from
                // adjClose, so the line and the buy/sell dots need to share
                // that same series or the dots drift off the line wherever
                // dividends/splits make adjClose diverge from close.
                'prices' => array_map(fn(YahooFinanceData $p) => ['t' => $p->timestamp, 'c' => $p->adjClose], $prices),
                'signals' => array_map(fn($s) => ['t' => $s->timestamp, 'type' => $s->type, 'p' => $s->price], $signals),
            ],
            'trades' => array_reverse($result->trades),
            'summary' => $this->summarise($result),
        ];
    }

    /**
     * The same daily price series the chart/strategies are built from —
     * shared with the Lines dashboard overlay so a custom Line evaluates
     * against exactly the same bars as the strategy chart it's plotted on.
     *
     * @return YahooFinanceData[]
     */
    public function loadPrices(string $ticker): array
    {
        $symbol = TickerUniverse::toYahooSymbol($ticker);
        return $this->priceHistoryRepository->getDailyCloses($symbol, self::START, date('Y-m-d'));
    }

    /**
     * @param YahooFinanceData[] $prices
     * @param \App\Backtest\MACDPoint[] $macdData
     * @return array{0: StrategyInterface, 1: array}
     */
    private function buildStrategy(string $slug, array $prices, array $macdData): array
    {
        return match ($slug) {
            'long-macd' => [new LongMACDCrossoverStrategy(), $macdData],
            'macd-zero-line' => [new MACDZeroLineCrossoverStrategy(), $macdData],
            'macd-rsi' => [new MACDRSIFilteredStrategy(), $this->buildMacdRsiData($prices, $macdData)],
            'macd-adx' => [new MACDADXFilteredStrategy(), $this->buildMacdAdxData($prices, $macdData)],
            'rsi-mean-reversion' => [new MeanReversionRSIStrategy(), $this->buildPriceRsiData($prices)],
            default => [new MACDCrossoverStrategy(), $macdData],
        };
    }

    /**
     * @param YahooFinanceData[] $prices
     * @param \App\Backtest\MACDPoint[] $macdData
     * @return MACDRSIPoint[]
     */
    private function buildMacdRsiData(array $prices, array $macdData): array
    {
        $rsiData = $this->rsiCalc->calculate($prices);
        return array_map(
            fn($m, $r) => new MACDRSIPoint($m->timestamp, $m->close, $m->macd, $m->signal, $r->rsi),
            $macdData,
            $rsiData,
        );
    }

    /**
     * @param YahooFinanceData[] $prices
     * @param \App\Backtest\MACDPoint[] $macdData
     * @return MACDADXPoint[]
     */
    private function buildMacdAdxData(array $prices, array $macdData): array
    {
        $adxData = $this->adxCalc->calculate($prices);
        return array_map(
            fn($m, $a) => new MACDADXPoint($m->timestamp, $m->close, $m->macd, $m->signal, $a->adx),
            $macdData,
            $adxData,
        );
    }

    /**
     * @param YahooFinanceData[] $prices
     * @return PriceRSIPoint[]
     */
    private function buildPriceRsiData(array $prices): array
    {
        $rsiData = $this->rsiCalc->calculate($prices);
        return array_map(
            // adjClose, not close — matches RSICalculator (which computes RSI
            // off adjClose) and the chart's price line, so signal dots land
            // on the line instead of at the unadjusted historical price.
            fn($p, $r) => new PriceRSIPoint($p->timestamp, $p->adjClose, $r->rsi),
            $prices,
            $rsiData,
        );
    }

    /**
     * @return array{trades: int, winRate: float, compoundReturnPercent: float, maxDrawdownPercent: float, avgHoldingDays: float}
     */
    private function summarise(\App\Backtest\BacktestResult $result): array
    {
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

        return [
            'trades' => $result->totalTrades,
            'winRate' => $result->winRate,
            'compoundReturnPercent' => round(($equity - 1) * 100, 2),
            'maxDrawdownPercent' => round($maxDrawdownPercent, 2),
            'avgHoldingDays' => $result->totalTrades > 0 ? round($totalDays / $result->totalTrades, 1) : 0.0,
        ];
    }
}
