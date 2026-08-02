<?php
namespace App\Backtest;

class TradeResult
{
    public function __construct(
        public readonly int $buyTimestamp,
        public readonly float $buyPrice,
        public readonly int $sellTimestamp,
        public readonly float $sellPrice
    ) {}

    public function getProfit(): float
    {
        return $this->sellPrice - $this->buyPrice;
    }

    public function getProfitPercent(): float
    {
        return ($this->sellPrice - $this->buyPrice) / $this->buyPrice * 100;
    }

    public function getDurationDays(): int
    {
        return (int) round(($this->sellTimestamp - $this->buyTimestamp) / 86400);
    }

    public function summary(): string
    {
        $profit = $this->getProfit();
        $sign = $profit >= 0 ? '+' : '-';
        return sprintf(
            "%s %s%.2f  (buy %.2f → sell %.2f, %d days)",
            date('Y-m-d', $this->sellTimestamp),
            $sign,
            abs($profit),
            $this->buyPrice,
            $this->sellPrice,
            $this->getDurationDays()
        );
    }
}