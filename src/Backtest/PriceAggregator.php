<?php

namespace App\Backtest;

/**
 * Pure resampling of a chronologically-sorted daily YahooFinanceData[] series
 * into weekly or monthly buckets. Nothing here touches storage — this is the
 * one place both the momentum backtester's monthly view and the
 * MACD/RSI/ADX weekly view derive their bars from, so daily is the only
 * granularity that needs fetching or storing.
 */
class PriceAggregator
{
    /**
     * @param YahooFinanceData[] $prices Chronologically sorted daily prices
     * @return array<string, float> "YYYY-MM" => last close of that month
     */
    public static function toMonthly(array $prices): array
    {
        $monthly = [];
        foreach ($prices as $p) {
            // Last write per month wins, giving the last available close.
            $monthly[date('Y-m', $p->timestamp)] = $p->close;
        }
        return $monthly;
    }

    /**
     * @param YahooFinanceData[] $prices Chronologically sorted daily prices
     * @return YahooFinanceData[] One synthetic bar per ISO week: open of the
     *  week's first day, high/low across the week, close/adjClose of the
     *  week's last day, volume summed. Timestamped on the last day, so
     *  date()-based bucketing downstream behaves the same as for daily bars.
     */
    public static function toWeekly(array $prices): array
    {
        $weeks = [];
        foreach ($prices as $p) {
            $weeks[date('o-\WW', $p->timestamp)][] = $p;
        }

        $result = [];
        foreach ($weeks as $weekPrices) {
            $first = $weekPrices[0];
            $last = end($weekPrices);

            $result[] = new YahooFinanceData(
                timestamp: $last->timestamp,
                open: $first->open,
                high: max(array_map(fn(YahooFinanceData $p) => $p->high, $weekPrices)),
                low: min(array_map(fn(YahooFinanceData $p) => $p->low, $weekPrices)),
                close: $last->close,
                adjClose: $last->adjClose,
                volume: array_sum(array_map(fn(YahooFinanceData $p) => $p->volume, $weekPrices)),
            );
        }

        return $result;
    }
}
