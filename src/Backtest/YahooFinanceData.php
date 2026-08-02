<?php
namespace App\Backtest;

/**
 * Represents a single Yahoo Finance OHLC data record.
 */
class YahooFinanceData
{
    public function __construct(
        public readonly int $timestamp,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly float $adjClose,
        // float, not int — some tickers' volume exceeds PHP_INT_MAX on the
        // 32-bit ARM production host, silently corrupting an (int) cast there.
        public readonly float $volume
    ) {}

    public function getDate(): string
    {
        return date('Y-m-d', $this->timestamp);
    }
}