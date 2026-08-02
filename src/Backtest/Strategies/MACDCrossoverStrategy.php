<?php
namespace App\Backtest\Strategies;

use App\Backtest\MACDPoint;
use App\Backtest\TradeSignal;

class MACDCrossoverStrategy implements StrategyInterface
{
    /**
     * Generate trade signals from MACD data.
     *
     * @param MACDPoint[] $data
     * @return TradeSignal[]
     */
    public function generateSignals(array $data): array
    {
        $signals = [];

        for ($i = 1; $i < count($data); $i++) {
            $prev = $data[$i - 1];
            $curr = $data[$i];

            // Skip incomplete data
            if ($prev->macd === null || $prev->signal === null ||
                $curr->macd === null || $curr->signal === null) {
                continue;
            }

            $prevDiff = $prev->macd - $prev->signal;
            $currDiff = $curr->macd - $curr->signal;

            // Crossover detection
            if ($prevDiff <= 0 && $currDiff > 0) {
                // MACD crossed above signal → BUY
                $signals[] = new TradeSignal($curr->timestamp, 'BUY', $curr->close);
            } elseif ($prevDiff >= 0 && $currDiff < 0) {
                // MACD crossed below signal → SELL
                $signals[] = new TradeSignal($curr->timestamp, 'SELL', $curr->close);
            }
        }

        return $signals;
    }
}