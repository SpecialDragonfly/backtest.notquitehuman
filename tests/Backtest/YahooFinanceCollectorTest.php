<?php

use App\Backtest\YahooFinanceCollector;
use PHPUnit\Framework\TestCase;

class YahooFinanceCollectorTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/backtest_test_cache_' . uniqid();
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir . '/*') ?: []);
        @rmdir($this->cacheDir);
    }

    public function testSkipsCandleWithNullClose(): void
    {
        // Yahoo sometimes returns a null quote for the most recent, still-settling
        // candle. It must be skipped, not silently cast to a 0 price.
        $symbol = 'TEST.L';
        $start = '2021-01-01';
        $end = '2021-01-02';
        $interval = '1d';

        $timestamps = [1000, 2000, 3000];
        $payload = [
            'chart' => [
                'result' => [[
                    'timestamp' => $timestamps,
                    'indicators' => [
                        'quote' => [[
                            'open' => [10.0, null, 12.0],
                            'high' => [10.5, null, 12.5],
                            'low' => [9.5, null, 11.5],
                            'close' => [10.0, null, 12.0],
                            'volume' => [100, null, 300],
                        ]],
                        'adjclose' => [[
                            'adjclose' => [10.0, null, 12.0],
                        ]],
                    ],
                ]],
            ],
        ];

        $symbolSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $symbol);
        $cacheFile = "{$this->cacheDir}/{$symbolSafe}_{$start}_{$end}_{$interval}.csv";
        file_put_contents($cacheFile, json_encode($payload));

        $collector = new YahooFinanceCollector($this->cacheDir);
        $candles = $collector->fetch($symbol, $start, $end, $interval);

        $this->assertCount(2, $candles);
        $this->assertEquals(1000, $candles[0]->timestamp);
        $this->assertEquals(3000, $candles[1]->timestamp);
    }
}
