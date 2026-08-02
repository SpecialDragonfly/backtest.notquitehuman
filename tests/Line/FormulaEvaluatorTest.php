<?php

use App\Backtest\YahooFinanceData;
use App\Line\FormulaEvaluator;
use PHPUnit\Framework\TestCase;

class FormulaEvaluatorTest extends TestCase
{
    private FormulaEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FormulaEvaluator();
    }

    /**
     * @return YahooFinanceData[]
     */
    private function series(array $closes): array
    {
        $prices = [];
        foreach ($closes as $i => $close) {
            $t = strtotime('2026-01-01') + $i * 86400;
            $prices[] = new YahooFinanceData($t, $close, $close, $close, $close, $close, 1000);
        }
        return $prices;
    }

    public function testPriceReturnsAdjClosePerBar(): void
    {
        $prices = $this->series([10.0, 11.0, 12.0]);
        $result = $this->evaluator->evaluate(['fn' => 'PRICE'], $prices);

        $this->assertEquals([10.0, 11.0, 12.0], array_column($result, 'v'));
        $this->assertEquals($prices[0]->timestamp, $result[0]['t']);
    }

    public function testConstantRepeatsTheValueAcrossEveryBar(): void
    {
        $prices = $this->series([10.0, 11.0, 12.0]);
        $result = $this->evaluator->evaluate(['fn' => 'CONSTANT', 'value' => 70], $prices);

        $this->assertEquals([70.0, 70.0, 70.0], array_column($result, 'v'));
    }

    public function testSmaDelegatesToSeriesMath(): void
    {
        $prices = $this->series([1.0, 2.0, 3.0, 4.0, 5.0]);
        $result = $this->evaluator->evaluate(['fn' => 'SMA', 'period' => 3], $prices);

        $values = array_column($result, 'v');
        $this->assertNull($values[0]);
        $this->assertEqualsWithDelta(2.0, $values[2], 0.0001);
        $this->assertEqualsWithDelta(4.0, $values[4], 0.0001);
    }

    public function testRsiWrapsTheExistingCalculatorAndKeepsOnlyTheRsiValue(): void
    {
        $prices = $this->series(range(100, 119)); // consistent uptrend
        $result = $this->evaluator->evaluate(['fn' => 'RSI', 'period' => 14], $prices);

        $this->assertEquals(100.0, $result[19]['v']); // pure gains, no losses
    }

    public function testSubCombinesTwoNestedFormulasElementWise(): void
    {
        $prices = $this->series([10.0, 10.0, 10.0]);
        $formula = [
            'fn' => 'SUB',
            'args' => [
                ['fn' => 'CONSTANT', 'value' => 10],
                ['fn' => 'CONSTANT', 'value' => 4],
            ],
        ];

        $result = $this->evaluator->evaluate($formula, $prices);

        $this->assertEquals([6.0, 6.0, 6.0], array_column($result, 'v'));
    }

    public function testUnknownFunctionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->evaluator->evaluate(['fn' => 'NONSENSE'], $this->series([1.0]));
    }
}
