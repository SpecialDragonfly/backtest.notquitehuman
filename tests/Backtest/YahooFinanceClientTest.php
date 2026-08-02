<?php

use App\Backtest\YahooFinanceClient;
use PHPUnit\Framework\TestCase;

class YahooFinanceClientTest extends TestCase
{
    private function clientReturning(string $json): YahooFinanceClient
    {
        return new class ($json) extends YahooFinanceClient {
            public function __construct(private string $json) {}
            protected function download(string $url): string
            {
                return $this->json;
            }
        };
    }

    public function testSkipsCandleWithNullCloseAndSortsChronologically(): void
    {
        // Yahoo sometimes returns a null quote for the most recent, still-settling
        // candle, and candles aren't guaranteed to already be in order.
        $payload = [
            'chart' => [
                'result' => [[
                    'timestamp' => [3000, 1000, 2000],
                    'indicators' => [
                        'quote' => [[
                            'open' => [12.0, 10.0, null],
                            'high' => [12.5, 10.5, null],
                            'low' => [11.5, 9.5, null],
                            'close' => [12.0, 10.0, null],
                            'volume' => [300, 100, null],
                        ]],
                        'adjclose' => [[
                            'adjclose' => [12.0, 10.0, null],
                        ]],
                    ],
                ]],
            ],
        ];

        $client = $this->clientReturning(json_encode($payload));
        $candles = $client->fetchRange('TEST.L', '2021-01-01', '2021-01-02');

        $this->assertCount(2, $candles);
        $this->assertEquals(1000, $candles[0]->timestamp);
        $this->assertEquals(3000, $candles[1]->timestamp);
    }

    public function testReturnsEmptyArrayWhenYahooHasNoResultForTheSymbol(): void
    {
        $client = $this->clientReturning(json_encode(['chart' => ['result' => null, 'error' => ['code' => 'Not Found']]]));

        $this->assertSame([], $client->fetchRange('BOGUS.L', '2021-01-01', '2021-01-02'));
    }

    public function testParseJsonAppliesTheSameRulesAsFetchRangeToAnAlreadyDownloadedPayload(): void
    {
        // What bin/import-legacy-cache.php feeds in: raw JSON already sitting
        // on disk, no download() call involved at all.
        $payload = [
            'chart' => [
                'result' => [[
                    'timestamp' => [1000, 2000],
                    'indicators' => [
                        'quote' => [[
                            'open' => [10.0, null],
                            'high' => [10.5, null],
                            'low' => [9.5, null],
                            'close' => [10.0, null],
                            'volume' => [100, null],
                        ]],
                        'adjclose' => [['adjclose' => [10.0, null]]],
                    ],
                ]],
            ],
        ];

        $client = new YahooFinanceClient();
        $candles = $client->parseJson(json_encode($payload));

        $this->assertCount(1, $candles);
        $this->assertEquals(1000, $candles[0]->timestamp);
    }
}
