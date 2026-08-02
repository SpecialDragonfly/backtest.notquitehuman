<?php
namespace App\Backtest;

class ADXCalculator
{
    public function __construct(private int $period = 14) {}

    /**
     * Wilder's Average Directional Index, using raw high/low/close (not
     * adjusted close) since this measures intraday range/direction, not
     * dividend/split-adjusted returns.
     *
     * @param YahooFinanceData[] $prices
     * @return ADXPoint[]
     */
    public function calculate(array $prices): array
    {
        $n = count($prices);
        $period = $this->period;

        $plusDM = array_fill(0, $n, 0.0);
        $minusDM = array_fill(0, $n, 0.0);
        $tr = array_fill(0, $n, 0.0);

        for ($i = 1; $i < $n; $i++) {
            $upMove = $prices[$i]->high - $prices[$i - 1]->high;
            $downMove = $prices[$i - 1]->low - $prices[$i]->low;

            $plusDM[$i] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDM[$i] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;

            $tr[$i] = max(
                $prices[$i]->high - $prices[$i]->low,
                abs($prices[$i]->high - $prices[$i - 1]->close),
                abs($prices[$i]->low - $prices[$i - 1]->close)
            );
        }

        $plusDI = array_fill(0, $n, null);
        $minusDI = array_fill(0, $n, null);
        $dx = array_fill(0, $n, null);

        $smoothedTR = null;
        $smoothedPlusDM = null;
        $smoothedMinusDM = null;

        for ($i = 1; $i < $n; $i++) {
            if ($i < $period) {
                continue;
            }

            if ($smoothedTR === null) {
                // Seed with a simple sum of the first $period values
                $smoothedTR = array_sum(array_slice($tr, $i - $period + 1, $period));
                $smoothedPlusDM = array_sum(array_slice($plusDM, $i - $period + 1, $period));
                $smoothedMinusDM = array_sum(array_slice($minusDM, $i - $period + 1, $period));
            } else {
                $smoothedTR = $smoothedTR - ($smoothedTR / $period) + $tr[$i];
                $smoothedPlusDM = $smoothedPlusDM - ($smoothedPlusDM / $period) + $plusDM[$i];
                $smoothedMinusDM = $smoothedMinusDM - ($smoothedMinusDM / $period) + $minusDM[$i];
            }

            if ($smoothedTR == 0.0) {
                continue;
            }

            $plusDI[$i] = 100 * $smoothedPlusDM / $smoothedTR;
            $minusDI[$i] = 100 * $smoothedMinusDM / $smoothedTR;

            $diSum = $plusDI[$i] + $minusDI[$i];
            $dx[$i] = $diSum == 0.0 ? 0.0 : 100 * abs($plusDI[$i] - $minusDI[$i]) / $diSum;
        }

        // Wilder-smooth DX into ADX: seed with a simple average of the first
        // $period DX values, then smooth thereafter.
        $adx = array_fill(0, $n, null);
        $smoothedADX = null;
        $dxStartIndex = $period * 2 - 1;

        for ($i = $dxStartIndex; $i < $n; $i++) {
            if ($smoothedADX === null) {
                $window = array_slice($dx, $i - $period + 1, $period);
                if (in_array(null, $window, true)) {
                    continue;
                }
                $smoothedADX = array_sum($window) / $period;
            } else {
                $smoothedADX = (($smoothedADX * ($period - 1)) + $dx[$i]) / $period;
            }
            $adx[$i] = $smoothedADX;
        }

        $result = [];
        foreach ($prices as $i => $p) {
            $result[] = new ADXPoint($p->timestamp, $adx[$i], $plusDI[$i], $minusDI[$i]);
        }

        return $result;
    }
}
