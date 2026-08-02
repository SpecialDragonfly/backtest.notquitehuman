<?php
namespace App\Backtest;

class ADXPoint
{
    public function __construct(
        public readonly int $timestamp,
        public readonly ?float $adx,
        public readonly ?float $plusDI,
        public readonly ?float $minusDI
    ) {}

    public function getDate(): string
    {
        return date('Y-m-d', $this->timestamp);
    }
}
