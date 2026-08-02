<?php
namespace App\Backtest;

class MACDADXPoint
{
    public function __construct(
        public readonly int $timestamp,
        public readonly float $close,
        public readonly ?float $macd,
        public readonly ?float $signal,
        public readonly ?float $adx
    ) {}

    public function getDate(): string
    {
        return date('Y-m-d', $this->timestamp);
    }
}
