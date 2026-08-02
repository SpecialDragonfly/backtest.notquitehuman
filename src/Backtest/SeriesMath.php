<?php

namespace App\Backtest;

/**
 * Generic rolling-window arithmetic over an index-aligned float series, with
 * null-padding while there isn't yet enough history for a full window. A
 * fresh, small implementation rather than exposing MACDCalculator's private
 * emaSeries() — keeps that class's tested internals untouched.
 */
class SeriesMath
{
    /**
     * @param array<int, ?float> $values
     * @return array<int, ?float>
     */
    public static function sma(array $values, int $period): array
    {
        $result = [];
        $count = count($values);

        for ($i = 0; $i < $count; $i++) {
            if ($i < $period - 1) {
                $result[] = null;
                continue;
            }

            $window = array_slice($values, $i - $period + 1, $period);
            $result[] = in_array(null, $window, true) ? null : array_sum($window) / $period;
        }

        return $result;
    }

    /**
     * @param array<int, ?float> $values
     * @return array<int, ?float>
     */
    public static function ema(array $values, int $period): array
    {
        $result = [];
        $k = 2 / ($period + 1);
        $ema = null;

        foreach ($values as $i => $v) {
            if ($v === null) {
                $result[] = null;
                continue;
            }

            if ($ema === null) {
                if ($i + 1 >= $period) {
                    $window = array_slice($values, $i + 1 - $period, $period);
                    $ema = in_array(null, $window, true) ? null : array_sum($window) / $period;
                    $result[] = $ema;
                } else {
                    $result[] = null;
                }
                continue;
            }

            $ema = ($v * $k) + ($ema * (1 - $k));
            $result[] = $ema;
        }

        return $result;
    }
}
