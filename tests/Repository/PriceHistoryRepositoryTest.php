<?php

use App\Backtest\YahooFinanceData;
use App\Repository\PriceHistoryRepository;
use PHPUnit\Framework\TestCase;

class PriceHistoryRepositoryTest extends TestCase
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

    private function bar(string $date, float $close): YahooFinanceData
    {
        $timestamp = strtotime($date . ' 12:00:00');
        return new YahooFinanceData($timestamp, $close, $close, $close, $close, $close, 1000);
    }

    public function testUpsertThenGetDailyClosesRoundTripsInDateOrder(): void
    {
        $this->repository->upsertDaily('VOD.L', [
            $this->bar('2026-01-10', 100.0),
            $this->bar('2026-01-05', 95.0),
        ]);

        $prices = $this->repository->getDailyCloses('VOD.L', '2026-01-01', '2026-01-31');

        $this->assertCount(2, $prices);
        $this->assertEquals('2026-01-05', $prices[0]->getDate());
        $this->assertEquals(95.0, $prices[0]->close);
        $this->assertEquals('2026-01-10', $prices[1]->getDate());
        $this->assertEquals(100.0, $prices[1]->close);
    }

    public function testGetDailyClosesExcludesRowsOutsideTheRequestedRangeAndSymbol(): void
    {
        $this->repository->upsertDaily('VOD.L', [$this->bar('2026-01-10', 100.0)]);
        $this->repository->upsertDaily('BP.L', [$this->bar('2026-01-10', 200.0)]);

        $prices = $this->repository->getDailyCloses('VOD.L', '2026-02-01', '2026-02-28');

        $this->assertEmpty($prices);
    }

    public function testUpsertDailyReplacesExistingRowsForTheSameSymbolAndDate(): void
    {
        $this->repository->upsertDaily('VOD.L', [$this->bar('2026-01-10', 100.0)]);
        $this->repository->upsertDaily('VOD.L', [$this->bar('2026-01-10', 101.5)]);

        $prices = $this->repository->getDailyCloses('VOD.L', '2026-01-01', '2026-01-31');

        $this->assertCount(1, $prices);
        $this->assertEquals(101.5, $prices[0]->close);
    }

    public function testLatestDateForReturnsNullWhenNothingSyncedYet(): void
    {
        $this->assertNull($this->repository->latestDateFor('VOD.L'));
    }

    public function testLatestDateForReturnsTheMostRecentStoredDate(): void
    {
        $this->repository->upsertDaily('VOD.L', [
            $this->bar('2026-01-05', 95.0),
            $this->bar('2026-01-20', 100.0),
        ]);

        $this->assertEquals('2026-01-20', $this->repository->latestDateFor('VOD.L'));
    }
}
