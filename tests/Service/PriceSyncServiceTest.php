<?php

use App\Backtest\TickerUniverse;
use App\Backtest\YahooFinanceClient;
use App\Backtest\YahooFinanceData;
use App\Repository\PriceHistoryRepository;
use App\Service\PriceSyncService;
use PHPUnit\Framework\TestCase;

class PriceSyncServiceTest extends TestCase
{
    private PDO $db;
    private PriceHistoryRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Mirrors db/migrations/0005_price_history.php.
        $this->db->exec('
            CREATE TABLE price_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                symbol VARCHAR(16),
                date DATE,
                open DECIMAL(12,4),
                high DECIMAL(12,4),
                low DECIMAL(12,4),
                close DECIMAL(12,4),
                adj_close DECIMAL(12,4),
                volume BIGINT
            )
        ');
        $this->db->exec('CREATE UNIQUE INDEX idx_symbol_date ON price_history (symbol, date)');

        $this->repository = new PriceHistoryRepository($this->db);
    }

    /**
     * @return YahooFinanceClient&object{calls: list<array{0: string, 1: string, 2: string}>}
     */
    private function recordingClient(int $barsPerCall = 1): YahooFinanceClient
    {
        return new class ($barsPerCall) extends YahooFinanceClient {
            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $calls = [];

            public function __construct(private int $barsPerCall) {}

            public function fetchRange(string $symbol, string $start, string $end): array
            {
                $this->calls[] = [$symbol, $start, $end];
                $bars = [];
                for ($i = 0; $i < $this->barsPerCall; $i++) {
                    $bars[] = new YahooFinanceData(strtotime($start) + $i * 86400, 1.0, 1.0, 1.0, 1.0, 1.0, 100);
                }
                return $bars;
            }
        };
    }

    public function testBackfillsFromLongHistoryStartWhenSymbolHasNoStoredData(): void
    {
        $client = $this->recordingClient();
        $service = new PriceSyncService($client, $this->repository, delayMicroseconds: 0);

        $stored = $service->sync('VOD.L');

        $this->assertEquals(1, $stored);
        $this->assertEquals(['VOD.L', '2008-01-01', date('Y-m-d')], $client->calls[0]);
    }

    public function testOnlyFetchesFromTheDayAfterTheLatestStoredDate(): void
    {
        $this->repository->upsertDaily('VOD.L', [
            new YahooFinanceData(strtotime('2026-01-10'), 100, 100, 100, 100, 100, 1000),
        ]);

        $client = $this->recordingClient();
        (new PriceSyncService($client, $this->repository, delayMicroseconds: 0))->sync('VOD.L');

        $this->assertEquals(['VOD.L', '2026-01-11', date('Y-m-d')], $client->calls[0]);
    }

    public function testSkipsFetchingWhenAlreadySyncedToday(): void
    {
        $today = date('Y-m-d');
        $this->repository->upsertDaily('VOD.L', [
            new YahooFinanceData(strtotime($today), 100, 100, 100, 100, 100, 1000),
        ]);

        $client = $this->recordingClient();
        $stored = (new PriceSyncService($client, $this->repository, delayMicroseconds: 0))->sync('VOD.L');

        $this->assertEquals(0, $stored);
        $this->assertEmpty($client->calls);
    }

    public function testSyncPersistsFetchedPricesViaTheRepository(): void
    {
        $client = $this->recordingClient(barsPerCall: 3);
        $service = new PriceSyncService($client, $this->repository, delayMicroseconds: 0);

        $stored = $service->sync('VOD.L');

        $this->assertEquals(3, $stored);
        $this->assertEquals('2008-01-03', $this->repository->latestDateFor('VOD.L'));
    }

    public function testSyncAllCoversTheFullTickerUniversePlusTheRegimeIndex(): void
    {
        $client = $this->recordingClient();
        $service = new PriceSyncService($client, $this->repository, delayMicroseconds: 0);

        $service->syncAll();

        $requestedSymbols = array_column($client->calls, 0);
        $this->assertContains('^FTSE', $requestedSymbols);
        $this->assertContains('VOD.L', $requestedSymbols);
        $this->assertCount(count(TickerUniverse::blueChipsAndIndexTrackers()) + 1, $requestedSymbols);
    }
}
