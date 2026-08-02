<?php

use App\Backtest\YahooFinanceData;
use App\Domain\Trigger;
use App\Domain\TriggerCondition;
use App\Line\FormulaEvaluator;
use App\Line\TriggerEvaluationService;
use App\Repository\LineRepository;
use PHPUnit\Framework\TestCase;

class TriggerEvaluationServiceTest extends TestCase
{
    private PDO $db;
    private LineRepository $lines;
    private TriggerEvaluationService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

        $this->lines = new LineRepository($this->db);
        $this->service = new TriggerEvaluationService($this->lines, new FormulaEvaluator());
    }

    /**
     * @return YahooFinanceData[]
     */
    private function rampSeries(float $start, float $end): array
    {
        $prices = [];
        $i = 0;
        for ($v = $start; $v <= $end; $v++, $i++) {
            $t = strtotime('2026-01-01') + $i * 86400;
            $prices[] = new YahooFinanceData($t, $v, $v, $v, $v, $v, 1000);
        }
        return $prices;
    }

    /**
     * @param array<string, mixed> $formula
     */
    private function lineId(string $name, array $formula): int
    {
        $this->lines->create(1, $name, $formula);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @param TriggerCondition[] $conditions
     */
    private function trigger(array $conditions): Trigger
    {
        return new Trigger(1, 1, 'VOD', 'test', true, new DateTimeImmutable(), $conditions);
    }

    public function testFindTransitionDatesFindsTheSingleCrossingDay(): void
    {
        $prices = $this->rampSeries(5, 15); // index0=5 ... index10=15
        $priceLine = $this->lineId('Price', ['fn' => 'PRICE']);
        $thresholdLine = $this->lineId('Ten', ['fn' => 'CONSTANT', 'value' => 10]);

        $condition = new TriggerCondition(1, $priceLine, $thresholdLine, TriggerCondition::OPERATOR_ABOVE);
        $trigger = $this->trigger([$condition]);

        $states = $this->service->evaluateStates($trigger, $prices);
        $dates = $this->service->findTransitionDates($states, $prices);

        // Price crosses above 10 the day it becomes 11 (index 6).
        $this->assertEquals([$prices[6]->getDate()], $dates);
    }

    public function testAndOfTwoConditionsOnlyFiresWhenBothAreTrue(): void
    {
        $prices = $this->rampSeries(5, 20);
        $priceLine = $this->lineId('Price', ['fn' => 'PRICE']);
        $lowLine = $this->lineId('Ten', ['fn' => 'CONSTANT', 'value' => 10]);
        $highLine = $this->lineId('Thirteen', ['fn' => 'CONSTANT', 'value' => 13]);

        $above = new TriggerCondition(1, $priceLine, $lowLine, TriggerCondition::OPERATOR_ABOVE);
        $below = new TriggerCondition(2, $priceLine, $highLine, TriggerCondition::OPERATOR_BELOW);
        $trigger = $this->trigger([$above, $below]);

        $states = $this->service->evaluateStates($trigger, $prices);
        $dates = $this->service->findTransitionDates($states, $prices);

        // Both conditions true only while 10 < price < 13 (price 11 or 12);
        // the first day that holds is price=11 at index 6.
        $this->assertEquals([$prices[6]->getDate()], $dates);
    }

    public function testFiredOnLatestBarIsTrueOnlyOnTheExactTransitionDay(): void
    {
        $prices = $this->rampSeries(5, 12); // last bar = 12
        $priceLine = $this->lineId('Price', ['fn' => 'PRICE']);
        $thresholdLine = $this->lineId('Eleven', ['fn' => 'CONSTANT', 'value' => 11]);
        $condition = new TriggerCondition(1, $priceLine, $thresholdLine, TriggerCondition::OPERATOR_ABOVE);
        $trigger = $this->trigger([$condition]);

        $states = $this->service->evaluateStates($trigger, $prices);
        $this->assertTrue($this->service->firedOnLatestBar($states));

        // Drop the last bar: latest is now price=11, which is not > 11.
        $statesBeforeCross = $this->service->evaluateStates($trigger, array_slice($prices, 0, -1));
        $this->assertFalse($this->service->firedOnLatestBar($statesBeforeCross));
    }

    public function testNullStatesDuringWarmupAreNeverTreatedAsTransitions(): void
    {
        $prices = $this->rampSeries(1, 10);
        $priceLine = $this->lineId('Price', ['fn' => 'PRICE']);
        $smaLine = $this->lineId('SMA5', ['fn' => 'SMA', 'period' => 5]);
        $condition = new TriggerCondition(1, $priceLine, $smaLine, TriggerCondition::OPERATOR_ABOVE);
        $trigger = $this->trigger([$condition]);

        $states = $this->service->evaluateStates($trigger, $prices);

        $this->assertNull($states[0]);
        $this->assertNull($states[3]); // SMA(5) not ready until index 4
    }
}
