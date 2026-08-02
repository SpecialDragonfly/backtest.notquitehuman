<?php
namespace App\Backtest;

class PriceRSIPoint
{
    public function __construct(
        public readonly int $timestamp,
        public readonly float $close,
        public readonly ?float $rsi
    ) {}

    public function getDate(): string
    {
        return date('Y-m-d', $this->timestamp);
    }
}
