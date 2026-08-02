<?php

use App\Backtest\YahooFinanceData;
use App\Line\FormulaEvaluator;
use App\Line\TriggerEvaluationService;
use App\Repository\AlertRepository;
use App\Repository\LineRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\TriggerRepository;
use App\Service\TickerBacktestService;
use App\Service\TriggerAlertService;
use PHPUnit\Framework\TestCase;

class TriggerAlertServiceTest extends TestCase
{
    private PDO $db;
    private PriceHistoryRepository $priceHistoryRepository;
    private LineRepository $lineRepository;
    private TriggerRepository $triggerRepository;
    private AlertRepository $alertRepository;
    private TriggerAlertService $service;

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
        // Mirrors db/migrations/0009_lines.php.
        $this->db->exec('
            CREATE TABLE lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                name VARCHAR(64),
                formula TEXT,
                created_at DATETIME
            )
        ');
        // Mirrors db/migrations/0010_triggers.php.
        $this->db->exec('
            CREATE TABLE triggers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                ticker VARCHAR(16),
                name VARCHAR(64),
                is_active INTEGER,
                created_at DATETIME
            )
        ');
        $this->db->exec('
            CREATE TABLE trigger_conditions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trigger_id INTEGER,
                line_a_id INTEGER,
                line_b_id INTEGER,
                operator VARCHAR(8),
                position INTEGER
            )
        ');
        // Mirrors db/migrations/0011_alerts.php.
        $this->db->exec('
            CREATE TABLE alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trigger_id INTEGER,
                fired_on DATE,
                created_at DATETIME
            )
        ');
        $this->db->exec('CREATE UNIQUE INDEX idx_alerts_trigger_fired_on ON alerts (trigger_id, fired_on)');

        $this->priceHistoryRepository = new PriceHistoryRepository($this->db);
        $this->lineRepository = new LineRepository($this->db);
        $this->triggerRepository = new TriggerRepository($this->db);
        $this->alertRepository = new AlertRepository($this->db);

        $backtestService = new TickerBacktestService($this->priceHistoryRepository);
        $evaluationService = new TriggerEvaluationService($this->lineRepository, new FormulaEvaluator());

        $this->service = new TriggerAlertService(
            $this->triggerRepository,
            $backtestService,
            $evaluationService,
            $this->alertRepository,
        );
    }

    private function bar(string $date, float $close): YahooFinanceData
    {
        return new YahooFinanceData(strtotime($date . ' 12:00:00'), $close, $close, $close, $close, $close, 1000);
    }

    public function testCreatesAnAlertOnlyWhenTheLatestDayIsANewTransition(): void
    {
        // Price ramps 5..10 across 2026-01-01..2026-01-06, then 11 lands as a
        // genuinely new day (2026-01-07).
        $bars = [];
        $day = 1;
        for ($v = 5; $v <= 10; $v++) {
            $bars[] = $this->bar('2026-01-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT), (float) $v);
            $day++;
        }
        $this->priceHistoryRepository->upsertDaily('VOD.L', $bars);

        $this->lineRepository->create(1, 'Price', ['fn' => 'PRICE']);
        $this->lineRepository->create(1, 'Ten', ['fn' => 'CONSTANT', 'value' => 10]);
        $this->triggerRepository->create(1, 'VOD', 'Above ten', 1, 'ABOVE', 2);

        // Not above 10 yet (latest bar = 10) — no alert.
        $this->service->evaluateSymbol('VOD.L');
        $this->assertCount(0, $this->alertRepository->allForUser(1));

        // New day lands: price = 11, now above 10 for the first time.
        $this->priceHistoryRepository->upsertDaily('VOD.L', [$this->bar('2026-01-07', 11.0)]);
        $this->service->evaluateSymbol('VOD.L');
        $alerts = $this->alertRepository->allForUser(1);
        $this->assertCount(1, $alerts);
        $this->assertEquals('2026-01-07', $alerts[0]->getFiredOn());

        // Re-running for the same day (e.g. a cron re-run) must not duplicate.
        $this->service->evaluateSymbol('VOD.L');
        $this->assertCount(1, $this->alertRepository->allForUser(1));
    }

    public function testDisarmedTriggersAreNeverEvaluated(): void
    {
        $this->priceHistoryRepository->upsertDaily('VOD.L', [
            $this->bar('2026-01-01', 9.0),
            $this->bar('2026-01-02', 11.0),
        ]);

        $this->lineRepository->create(1, 'Price', ['fn' => 'PRICE']);
        $this->lineRepository->create(1, 'Ten', ['fn' => 'CONSTANT', 'value' => 10]);
        $this->triggerRepository->create(1, 'VOD', 'Above ten', 1, 'ABOVE', 2);
        $this->triggerRepository->setActive(1, false);

        $this->service->evaluateSymbol('VOD.L');

        $this->assertCount(0, $this->alertRepository->allForUser(1));
    }

    public function testUnrelatedSymbolsAreIgnored(): void
    {
        $this->priceHistoryRepository->upsertDaily('BP.L', [
            $this->bar('2026-01-01', 9.0),
            $this->bar('2026-01-02', 11.0),
        ]);

        $this->lineRepository->create(1, 'Price', ['fn' => 'PRICE']);
        $this->lineRepository->create(1, 'Ten', ['fn' => 'CONSTANT', 'value' => 10]);
        $this->triggerRepository->create(1, 'VOD', 'Above ten', 1, 'ABOVE', 2);

        // BP.L has a crossing, but no trigger watches BP — must be a no-op.
        $this->service->evaluateSymbol('BP.L');

        $this->assertCount(0, $this->alertRepository->allForUser(1));
    }
}
