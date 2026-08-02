<?php
namespace App\Backtest;

class MACDRSIPoint
{
    public function __construct(
        public readonly int $timestamp,
        public readonly float $close,
        public readonly ?float $macd,
        public readonly ?float $signal,
        public readonly ?float $rsi
    ) {}

    public function getDate(): string
    {
        return date('Y-m-d', $this->timestamp);
    }
}
