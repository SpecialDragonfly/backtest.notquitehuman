<?php
namespace App\Backtest\Strategies;

use App\Backtest\PriceRSIPoint;
use App\Backtest\TradeSignal;

class MeanReversionRSIStrategy implements StrategyInterface
{
    public function __construct(
        private float $oversoldThreshold = 30.0,
        private float $overboughtThreshold = 70.0,
        private float $exitMidline = 50.0
    ) {}

    /**
     * Buy when RSI recovers back above the oversold threshold (a bounce off an
     * oversold low). Sell either on reaching overbought (take the reversion
     * profit) or on falling back below the midline (the bounce failed — cut
     * the position rather than wait indefinitely for overbought).
     *
     * @param PriceRSIPoint[] $data
     * @return TradeSignal[]
     */
    public function generateSignals(array $data): array
    {
        $signals = [];
        $positionOpen = false;

        for ($i = 1; $i < count($data); $i++) {
            $prev = $data[$i - 1];
            $curr = $data[$i];

            if ($prev->rsi === null || $curr->rsi === null) {
                continue;
            }

            if (!$positionOpen) {
                if ($prev->rsi <= $this->oversoldThreshold && $curr->rsi > $this->oversoldThreshold) {
                    $signals[] = new TradeSignal($curr->timestamp, 'BUY', $curr->close);
                    $positionOpen = true;
                }
            } else {
                $reachedOverbought = $curr->rsi >= $this->overboughtThreshold;
                $bounceFailed = $prev->rsi >= $this->exitMidline && $curr->rsi < $this->exitMidline;

                if ($reachedOverbought || $bounceFailed) {
                    $signals[] = new TradeSignal($curr->timestamp, 'SELL', $curr->close);
                    $positionOpen = false;
                }
            }
        }

        if ($positionOpen && !empty($data)) {
            $last = end($data);
            $signals[] = new TradeSignal($last->timestamp, 'SELL', $last->close);
        }

        return $signals;
    }
}
