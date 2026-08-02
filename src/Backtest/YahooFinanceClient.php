<?php

namespace App\Backtest;

/**
 * Thin client for Yahoo Finance's undocumented chart API: one HTTP call in,
 * a chronologically-sorted daily YahooFinanceData[] out. No caching — that's
 * PriceHistoryRepository's job. Only ever requests interval=1d; weekly/
 * monthly views are derived from daily bars via PriceAggregator instead of
 * asking Yahoo for a different interval.
 */
class YahooFinanceClient
{
    /**
     * @return YahooFinanceData[]
     */
    public function fetchRange(string $symbol, string $start, string $end): array
    {
        $url = $this->buildUrl($symbol, $start, $end);
        return $this->parseJson($this->download($url));
    }

    /**
     * Parses a raw Yahoo chart API JSON response (the same shape fetchRange()
     * downloads) into daily bars — exposed so callers with an already-fetched
     * payload (e.g. an on-disk cache from before price_history existed) can
     * reuse the exact same parsing rules instead of duplicating them.
     *
     * @return YahooFinanceData[]
     */
    public function parseJson(string $json): array
    {
        return $this->parse(json_decode($json, true));
    }

    protected function download(string $url): string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response === false ? '' : $response;
    }

    private function buildUrl(string $symbol, string $start, string $end): string
    {
        $period1 = strtotime($start);
        $period2 = strtotime($end);
        return "https://query2.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&period1={$period1}&period2={$period2}";
    }

    /**
     * @param array<string, mixed>|null $data
     * @return YahooFinanceData[]
     */
    private function parse(?array $data): array
    {
        // A missing/invalid symbol or a rate-limited response won't have a
        // result — treat it the same as "nothing new", not a fatal error,
        // since a sync loops over many symbols unattended.
        $result = $data['chart']['result'][0] ?? null;
        if (!is_array($result)) {
            return [];
        }

        $timestamps = $result['timestamp'];
        $quotes = $result['indicators']['quote'][0];
        $adjustedClose = $result['indicators']['adjclose'][0];

        $candles = [];
        foreach ($timestamps as $i => $timestamp) {
            // Yahoo occasionally returns a null close (and null adjclose) for an
            // individual candle in the series — skip it rather than silently
            // casting to 0.
            if ($quotes['close'][$i] === null) {
                continue;
            }

            $candles[] = new YahooFinanceData(
                timestamp: (int) $timestamp,
                open: (float) $quotes['open'][$i],
                high: (float) $quotes['high'][$i],
                low: (float) $quotes['low'][$i],
                close: (float) $quotes['close'][$i],
                adjClose: (float) $adjustedClose['adjclose'][$i],
                volume: (float) $quotes['volume'][$i],
            );
        }

        usort($candles, fn(YahooFinanceData $a, YahooFinanceData $b) => $a->timestamp <=> $b->timestamp);

        return $candles;
    }
}
