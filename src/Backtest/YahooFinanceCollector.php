<?php
namespace App\Backtest;

class YahooFinanceCollector
{
    private string $cacheDir;

    public function __construct(string $cacheDir = __DIR__ . '/cache')
    {
        $this->cacheDir = $cacheDir;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * Fetch historical OHLC data for a symbol.
     *
     * @param string $symbol   e.g., "AAPL", "VOD.L"
     * @param string $start    e.g., "2023-01-01"
     * @param string $end      e.g., "2023-12-31"
     * @param string $interval "1d" or "1wk"
     * @return YahooFinanceData[]
     */
    public function fetch(string $symbol, string $start, string $end, string $interval = '1d'): array
    {
        $cacheFile = $this->getCacheFilename($symbol, $start, $end, $interval);

        if (file_exists($cacheFile)) {
            $csv =file_get_contents($cacheFile);
        } else {
            $url = $this->buildUrl($symbol, $start, $end, $interval);
            $csv = $this->download($url);
            file_put_contents($cacheFile, $csv);
        }

        return $this->parseData(json_decode($csv, true));
    }

    private function getCacheFilename(string $symbol, string $start, string $end, string $interval): string
    {
        $symbolSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $symbol);
        return "{$this->cacheDir}/{$symbolSafe}_{$start}_{$end}_{$interval}.csv";
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

        return $response;
    }

    private function buildUrl(string $symbol, string $startDate, string $endDate, string $interval): string
    {
        $period1 = strtotime($startDate);
        $period2 = strtotime($endDate);
        return "https://query2.finance.yahoo.com/v8/finance/chart/{$symbol}?interval={$interval}&period1=$period1&period2=$period2";
    }

    private function parseData(array $data): array
    {
        $result = $data['chart']['result'][0];
        $timestamps = $result['timestamp'];
        $quotes = $result['indicators']['quote'][0];
        $adjustedClose = $result['indicators']['adjclose'][0];

        $candles = [];
        foreach ($timestamps as $i => $timestamp) {
            // Yahoo occasionally returns a null close (and null adjclose) for an
            // individual candle in the series — skip it rather than silently casting to 0.
            if ($quotes['close'][$i] === null) {
                continue;
            }

            $candles[] = new YahooFinanceData(
                timestamp: (int)$timestamp,
                open: (float)$quotes['open'][$i],
                high: (float)$quotes['high'][$i],
                low: (float)$quotes['low'][$i],
                close: (float)$quotes['close'][$i],
                adjClose: (float)$adjustedClose['adjclose'][$i],
                volume: (int)$quotes['volume'][$i]
            );
        }

        usort($candles, fn($a, $b) => $a->timestamp <=> $b->timestamp);

        return $candles;
    }
}