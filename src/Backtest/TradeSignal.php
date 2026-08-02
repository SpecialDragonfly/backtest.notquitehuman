<?php
namespace App\Backtest;

class TradeSignal
{
    public function __construct(
        public readonly int $timestamp,
        public readonly string $type, // 'BUY' or 'SELL'
        public readonly float $price
    ) {}

    public function getDate(): string
    {
        return date('Y-m-d', $this->timestamp);
    }
}