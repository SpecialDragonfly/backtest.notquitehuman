<?php
namespace App\Backtest;

class MACDCalculator
{
    public function __construct(
        private int $shortPeriod = 12,
        private int $longPeriod = 26,
        private int $signalPeriod = 9
    ) {}

    /**
     * @param YahooFinanceData[] $prices
     * @return MACDPoint[]
     */
    public function calculate(array $prices): array
    {
        $closes = array_map(fn($p) => $p->adjClose, $prices);
        $timestamps = array_map(fn($p) => $p->timestamp, $prices);

        $emaShort = $this->emaSeries($closes, $this->shortPeriod);
        $emaLong = $this->emaSeries($closes, $this->longPeriod);

        $macdLine = [];
        foreach ($emaShort as $i => $v) {
            $macdLine[$i] = ($v !== null && $emaLong[$i] !== null)
                ? $v - $emaLong[$i]
                : null;
        }

        $signalLine = $this->emaSeries($macdLine, $this->signalPeriod);
        $histogram = [];
        foreach ($macdLine as $i => $macdVal) {
            $histogram[$i] = ($macdVal !== null && $signalLine[$i] !== null)
                ? $macdVal - $signalLine[$i]
                : null;
        }

        $result = [];
        foreach ($timestamps as $i => $ts) {
            $result[] = new MACDPoint(
                timestamp: $ts,
                close: $closes[$i],
                emaShort: $emaShort[$i],
                emaLong: $emaLong[$i],
                macd: $macdLine[$i],
                signal: $signalLine[$i],
                histogram: $histogram[$i]
            );
        }

        return $result;
    }

    /**
     * Compute an EMA series aligned with the input series.
     *
     * @param float[] $values
     * @param int $period
     * @return float[]
     */
    private function emaSeries(array $values, int $period): array
    {
        $result = [];
        $k = 2 / ($period + 1);
        $ema = null;

        foreach ($values as $i => $v) {
            if (!is_numeric($v)) {
                $result[] = null;
                continue;
            }

            if ($ema === null) {
                // Initialize EMA with simple average of first N points
                if ($i + 1 >= $period) {
                    $ema = array_sum(array_slice($values, $i + 1 - $period, $period)) / $period;
                    $result[] = $ema;
                } else {
                    $result[] = null;
                }
            } else {
                $ema = ($v * $k) + ($ema * (1 - $k));
                $result[] = $ema;
            }
        }

        return $result;
    }
}