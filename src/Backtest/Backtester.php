<?php
namespace App\Backtest;

use App\Backtest\Strategies\StrategyInterface;

class Backtester
{
    private float $buySlippagePercent;
    private float $sellSlippagePercent;
    public array $equityCurve = [];

    /**
     * @param float $buySlippagePercent Slippage applied to BUY orders (percent)
     * @param float $sellSlippagePercent Slippage applied to SELL orders (percent)
     */
    public function __construct(
        float $buySlippagePercent = 0.0,
        float $sellSlippagePercent = 0.0
    ) {
        $this->buySlippagePercent = $buySlippagePercent;
        $this->sellSlippagePercent = $sellSlippagePercent;
    }

    /**
     * @param array $priceData
     * @param TradeSignal[] $signals
     * @return BacktestResult
     */
    public function runWithStrategy(array $priceData, array $signals): BacktestResult
    {
        return $this->run($priceData, $signals);
    }

    /**
     * @param YahooFinanceData[]|MACDPoint[] $priceData
     * @param TradeSignal[] $signals
     */
    public function run(array $priceData, array $signals): BacktestResult
    {
        $trades = [];
        $position = null;

        foreach ($signals as $signal) {
            if ($signal->type === 'BUY' && $position === null) {
                // Open position
                $effectivePrice = $signal->price * (1 + $this->buySlippagePercent / 100);
                $position = [
                    'buyTimestamp' => $signal->timestamp,
                    'buyPrice' => $effectivePrice
                ];
            } elseif ($signal->type === 'SELL' && $position !== null) {
                // Close position
                $effectivePrice = $signal->price * (1 - $this->sellSlippagePercent / 100);
                $trades[] = new TradeResult(
                    buyTimestamp: $position['buyTimestamp'],
                    buyPrice: $position['buyPrice'],
                    sellTimestamp: $signal->timestamp,
                    sellPrice: $effectivePrice
                );
                $position = null;
            }
        }

        // Force close any open position at last close price
        if ($position !== null && !empty($priceData)) {
            $last = end($priceData);
            $effectivePrice = $last->close * (1 - $this->sellSlippagePercent / 100);
            $trades[] = new TradeResult(
                buyTimestamp: $position['buyTimestamp'],
                buyPrice: $position['buyPrice'],
                sellTimestamp: $last->timestamp,
                sellPrice: $effectivePrice
            );
        }

        // Populate equityCurve for visualization
        $this->equityCurve = $this->calculateEquityCurve($trades, $priceData);

        return $this->summarize($trades);
    }

    /**
     * Compute metrics for all trades.
     *
     * @param TradeResult[] $trades
     */
    private function summarize(array $trades): BacktestResult
    {
        $wins = 0;
        $losses = 0;
        $netProfit = 0;
        $balance = 0;
        $peak = 0;
        $maxDrawdown = 0;

        foreach ($trades as $trade) {
            $p = $trade->getProfit();
            $netProfit += $p;

            if ($p >= 0) $wins++; else $losses++;

            $balance += $p;
            $peak = max($peak, $balance);
            $drawdown = $peak - $balance;
            $maxDrawdown = max($maxDrawdown, $drawdown);
        }

        $total = count($trades);
        $winRate = $total > 0 ? ($wins / $total) * 100 : 0;

        return new BacktestResult(
            totalTrades: $total,
            wins: $wins,
            losses: $losses,
            netProfit: round($netProfit, 2),
            winRate: round($winRate, 2),
            maxDrawdown: round($maxDrawdown, 2),
            trades: $trades
        );
    }

    /**
     * Returns chart-ready JSON data for HTML visualization
     */
    public function getChartData(array $priceData, array $macdData, array $signals): array
    {
        return [
            'prices' => array_map(fn($p) => ['timestamp' => $p->timestamp, 'close' => $p->adjClose], $priceData),
            'macd' => array_map(fn($m) => ['timestamp' => $m->timestamp, 'macd' => $m->macd, 'signal' => $m->signal], $macdData),
            'signals' => array_map(fn($s) => ['timestamp' => $s->timestamp, 'type' => $s->type, 'price' => $s->price], $signals),
            'equity' => $this->equityCurve
        ];
    }

    private function calculateEquityCurve(array $trades, array $priceData): array
    {
        $equity = [];
        $balance = 1; // starting equity

        $priceMap = [];
        foreach ($priceData as $p) {
            $priceMap[$p->timestamp] = $p->close;
        }

        foreach ($trades as $trade) {
            $balance -= $trade->buyPrice;
            $balance += $trade->sellPrice;
            $equity[] = ['timestamp' => $trade->sellTimestamp, 'balance' => $balance];
        }

        return $equity;
    }
}