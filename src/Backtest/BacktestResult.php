<?php
namespace App\Backtest;

class BacktestResult
{
    public function __construct(
        public readonly int $totalTrades,
        public readonly int $wins,
        public readonly int $losses,
        public readonly float $netProfit,
        public readonly float $winRate,
        public readonly float $maxDrawdown,
        /** @var TradeResult[] */
        public readonly array $trades
    ) {}
}