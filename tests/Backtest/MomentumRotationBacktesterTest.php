<?php

use App\Backtest\MomentumRotationBacktester;
use App\Backtest\YahooFinanceData;
use PHPUnit\Framework\TestCase;

class MomentumRotationBacktesterTest extends TestCase
{
    private function monthlySeries(array $closes, int $startMonthOffset = 0): array
    {
        $prices = [];
        foreach (array_values($closes) as $i => $close) {
            // Use the 1st of consecutive months so date('Y-m', ...) buckets cleanly.
            $timestamp = mktime(0, 0, 0, 1 + $startMonthOffset + $i, 15, 2022);
            $prices[] = new YahooFinanceData($timestamp, $close, $close, $close, $close, $close, 1000);
        }
        return $prices;
    }

    public function testCompoundsPureMomentumReturnWithNoCosts(): void
    {
        // A always has the higher trailing return, B is always the laggard.
        $pricesByTicker = [
            'A' => $this->monthlySeries([100, 110, 121]),
            'B' => $this->monthlySeries([100, 102, 104]),
        ];

        $bt = new MomentumRotationBacktester(topN: 1, lookbackMonths: 1, buyCostPercent: 0.0, sellCostPercent: 0.0);
        $result = $bt->run($pricesByTicker);

        $this->assertEquals(2, $result->rebalanceCount);
        $this->assertEqualsWithDelta(10.0, $result->finalReturnPercent, 0.01);
    }

    public function testRotatesHoldingsWhenLeadershipChanges(): void
    {
        // A leads for the first two rebalances, then B overtakes.
        $pricesByTicker = [
            'A' => $this->monthlySeries([100, 110, 121, 121]),
            'B' => $this->monthlySeries([100, 100, 105, 126]),
        ];

        $bt = new MomentumRotationBacktester(topN: 1, lookbackMonths: 1, buyCostPercent: 0.0, sellCostPercent: 0.0);
        $result = $bt->run($pricesByTicker);

        $this->assertCount(3, $result->rebalanceLog);
        $this->assertEquals(['A'], $result->rebalanceLog[0]['holdings']);
        $this->assertEquals(['A'], $result->rebalanceLog[1]['holdings']);
        $this->assertEquals(['B'], $result->rebalanceLog[2]['holdings']);
    }

    public function testAppliesTransactionCostsOnEntryAndExit(): void
    {
        $pricesByTicker = [
            'A' => $this->monthlySeries([100, 110]),
            'B' => $this->monthlySeries([100, 105]),
        ];

        $bt = new MomentumRotationBacktester(topN: 1, lookbackMonths: 1, buyCostPercent: 1.0, sellCostPercent: 0.5);
        $result = $bt->run($pricesByTicker);

        // One entry (buy A, -1.0%) then final liquidation (sell A, -0.5%):
        // 1 * 0.99 * 0.995 = 0.98505 -> -1.495% ~ -1.5%
        $this->assertEqualsWithDelta(-1.5, $result->finalReturnPercent, 0.01);
    }

    public function testMovesToCashWhenRegimeIndexIsBelowItsTrailingAverage(): void
    {
        $pricesByTicker = [
            'A' => $this->monthlySeries([100, 110, 121, 133, 146]),
        ];
        // Flat, then a sharp drop in the final month.
        $regimePrices = $this->monthlySeries([100, 100, 100, 100, 50]);

        $bt = new MomentumRotationBacktester(
            topN: 1,
            lookbackMonths: 1,
            buyCostPercent: 0.0,
            sellCostPercent: 0.0,
            regimeMAMonths: 2
        );
        $result = $bt->run($pricesByTicker, $regimePrices);

        $this->assertCount(4, $result->rebalanceLog);
        $this->assertEquals('risk-on', $result->rebalanceLog[0]['regime']);
        $this->assertEquals(['A'], $result->rebalanceLog[0]['holdings']);
        $this->assertEquals('risk-on', $result->rebalanceLog[2]['regime']);
        $this->assertEquals(['A'], $result->rebalanceLog[2]['holdings']);
        $this->assertEquals('risk-off', $result->rebalanceLog[3]['regime']);
        $this->assertEquals([], $result->rebalanceLog[3]['holdings']);
    }

    public function testRegimeFilterIsOptInAndDoesNotAffectExistingBehaviour(): void
    {
        $pricesByTicker = [
            'A' => $this->monthlySeries([100, 110, 121]),
            'B' => $this->monthlySeries([100, 102, 104]),
        ];

        $bt = new MomentumRotationBacktester(topN: 1, lookbackMonths: 1, buyCostPercent: 0.0, sellCostPercent: 0.0);
        $result = $bt->run($pricesByTicker);

        $this->assertEquals('risk-on', $result->rebalanceLog[0]['regime']);
        $this->assertEqualsWithDelta(10.0, $result->finalReturnPercent, 0.01);
    }
}
