<?php

namespace App\Backtest\Strategies;

use App\Backtest\MACDPoint;
use App\Backtest\TradeSignal;

interface StrategyInterface
{
    /**
     * @param MACDPoint[] $data
     * @return TradeSignal[]
     */
    public function generateSignals(array $data): array;
}