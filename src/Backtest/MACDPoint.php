<?php
namespace App\Backtest;

class MACDPoint
{
    public function __construct(
        public readonly int $timestamp,
        public readonly float $close,
        public readonly ?float $emaShort,
        public readonly ?float $emaLong,
        public readonly ?float $macd,
        public readonly ?float $signal,
        public readonly ?float $histogram
    ) {}

    public function getDate(): string
    {
        return date('Y-m-d', $this->timestamp);
    }
}