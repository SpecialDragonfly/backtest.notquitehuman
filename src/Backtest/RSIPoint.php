<?php
namespace App\Backtest;

class RSIPoint
{
    public function __construct(
        public readonly int $timestamp,
        public readonly ?float $rsi
    ) {}

    public function getDate(): string
    {
        return date('Y-m-d', $this->timestamp);
    }
}
