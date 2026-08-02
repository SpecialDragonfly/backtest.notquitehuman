<?php
namespace App\Backtest\Strategies;

use App\Backtest\MACDADXPoint;
use App\Backtest\TradeSignal;

class MACDADXFilteredStrategy implements StrategyInterface
{
    public function __construct(private float $adxTrendThreshold = 25.0) {}

    /**
     * MACD signal-line crossover, but a BUY is only taken when ADX confirms a
     * trending market (>= threshold) — sitting out choppy, range-bound periods
     * where crossover systems tend to whipsaw. SELL always fires on the MACD
     * cross-down, so a position can never get stuck open waiting on ADX.
     *
     * @param MACDADXPoint[] $data
     * @return TradeSignal[]
     */
    public function generateSignals(array $data): array
    {
        $signals = [];
        $positionOpen = false;

        for ($i = 1; $i < count($data); $i++) {
            $prev = $data[$i - 1];
            $curr = $data[$i];

            if ($prev->macd === null || $prev->signal === null || $curr->macd === null || $curr->signal === null) {
                continue;
            }

            $prevDiff = $prev->macd - $prev->signal;
            $currDiff = $curr->macd - $curr->signal;

            if (!$positionOpen && $prevDiff <= 0 && $currDiff > 0) {
                if ($curr->adx !== null && $curr->adx >= $this->adxTrendThreshold) {
                    $signals[] = new TradeSignal($curr->timestamp, 'BUY', $curr->close);
                    $positionOpen = true;
                }
            } elseif ($positionOpen && $prevDiff >= 0 && $currDiff < 0) {
                $signals[] = new TradeSignal($curr->timestamp, 'SELL', $curr->close);
                $positionOpen = false;
            }
        }

        if ($positionOpen && !empty($data)) {
            $last = end($data);
            $signals[] = new TradeSignal($last->timestamp, 'SELL', $last->close);
        }

        return $signals;
    }
}
