<?php
namespace App\Backtest\Strategies;

use App\Backtest\MACDPoint;
use App\Backtest\TradeSignal;

class LongMACDCrossoverStrategy implements StrategyInterface
{
    public function generateSignals(array $data): array
    {
        /** @var TradeSignal[] $signals */
        $signals = [];
        $prev = null;
        $positionOpen = false;

        /** @var MACDPoint[] $data */
        foreach ($data as $point) {
            if ($prev !== null) {
                if ($prev->macd !== null && $prev->signal !== null && $point->macd !== null && $point->signal !== null) {
                    // Long-only buy signal: MACD crosses above Signal AND MACD > 0
                    if (!$positionOpen && $prev->macd < $prev->signal && $point->macd > $point->signal && $point->macd > 0) {
                        $signals[] = new TradeSignal($point->timestamp, 'BUY', $point->close);
                        $positionOpen = true;
                    }

                    // Close position: MACD crosses below Signal
                    if ($positionOpen && $prev->macd > $prev->signal && $point->macd < $point->signal) {
                        $signals[] = new TradeSignal($point->timestamp, 'SELL', $point->close);
                        $positionOpen = false;
                    }
                }
            }
            $prev = $point;
        }

        // Optional: close last position at end of data
        if ($positionOpen && !empty($data)) {
            $last = end($data);
            $signals[] = new TradeSignal($last->timestamp, 'SELL', $last->close);
        }

        return $signals;
    }
}