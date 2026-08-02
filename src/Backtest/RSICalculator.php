<?php
namespace App\Backtest;

class RSICalculator
{
    public function __construct(private int $period = 14) {}

    /**
     * @param YahooFinanceData[] $prices
     * @return RSIPoint[]
     */
    public function calculate(array $prices): array
    {
        $result = [];
        $avgGain = null;
        $avgLoss = null;

        foreach ($prices as $i => $price) {
            if ($i < $this->period) {
                $result[] = new RSIPoint($price->timestamp, null);
                continue;
            }

            if ($avgGain === null) {
                // Seed with a simple average of the first $period changes
                $gains = 0.0;
                $losses = 0.0;
                for ($j = $i - $this->period + 1; $j <= $i; $j++) {
                    $change = $prices[$j]->adjClose - $prices[$j - 1]->adjClose;
                    $gains += max($change, 0.0);
                    $losses += max(-$change, 0.0);
                }
                $avgGain = $gains / $this->period;
                $avgLoss = $losses / $this->period;
            } else {
                $change = $price->adjClose - $prices[$i - 1]->adjClose;
                $avgGain = ($avgGain * ($this->period - 1) + max($change, 0.0)) / $this->period;
                $avgLoss = ($avgLoss * ($this->period - 1) + max(-$change, 0.0)) / $this->period;
            }

            $rsi = $avgLoss == 0.0
                ? ($avgGain == 0.0 ? 50.0 : 100.0)
                : 100 - (100 / (1 + $avgGain / $avgLoss));

            $result[] = new RSIPoint($price->timestamp, $rsi);
        }

        return $result;
    }
}
