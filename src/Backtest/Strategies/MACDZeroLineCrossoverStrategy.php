<?php
namespace App\Backtest\Strategies;

use App\Backtest\MACDPoint;
use App\Backtest\TradeSignal;

class MACDZeroLineCrossoverStrategy implements StrategyInterface
{
    /**
     * Generate trade signals from MACD crossing its zero line.
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

            if ($prev->macd === null || $curr->macd === null) {
                continue;
            }

            if ($prev->macd <= 0 && $curr->macd > 0) {
                // MACD crossed above zero → BUY
                $signals[] = new TradeSignal($curr->timestamp, 'BUY', $curr->close);
            } elseif ($prev->macd >= 0 && $curr->macd < 0) {
                // MACD crossed below zero → SELL
                $signals[] = new TradeSignal($curr->timestamp, 'SELL', $curr->close);
            }
        }

        return $signals;
    }
}
