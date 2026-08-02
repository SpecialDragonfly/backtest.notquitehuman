<?php

use App\Backtest\PriceAggregator;
use App\Backtest\YahooFinanceData;
use PHPUnit\Framework\TestCase;

class PriceAggregatorTest extends TestCase
{
    private function bar(string $date, float $open, float $high, float $low, float $close, int $volume): YahooFinanceData
    {
        $timestamp = strtotime($date . ' 12:00:00');
        return new YahooFinanceData($timestamp, $open, $high, $low, $close, $close, $volume);
    }

    public function testToMonthlyKeepsLastCloseOfEachMonth(): void
    {
        $prices = [
            $this->bar('2026-01-10', 100, 105, 95, 100, 1000),
            $this->bar('2026-01-31', 100, 110, 95, 105, 1000),
            $this->bar('2026-02-15', 105, 120, 100, 115, 1000),
        ];

        $monthly = PriceAggregator::toMonthly($prices);

        $this->assertEquals(['2026-01' => 105.0, '2026-02' => 115.0], $monthly);
    }

    public function testToWeeklyAggregatesOpenHighLowCloseVolumePerIsoWeek(): void
    {
        // 2026-01-05 (Mon) to 2026-01-09 (Fri) is ISO week 2026-W02.
        $prices = [
            $this->bar('2026-01-05', 100, 108, 98, 102, 1000),
            $this->bar('2026-01-06', 102, 112, 101, 110, 1500),
            $this->bar('2026-01-09', 110, 115, 90, 95, 2000),
            // Next ISO week starts here.
            $this->bar('2026-01-12', 95, 100, 93, 98, 500),
        ];

        $weekly = PriceAggregator::toWeekly($prices);

        $this->assertCount(2, $weekly);

        $this->assertEquals(100.0, $weekly[0]->open);   // first day's open
        $this->assertEquals(115.0, $weekly[0]->high);   // max high across week
        $this->assertEquals(90.0, $weekly[0]->low);      // min low across week
        $this->assertEquals(95.0, $weekly[0]->close);    // last day's close
        $this->assertEquals(4500, $weekly[0]->volume);   // summed volume

        $this->assertEquals(98.0, $weekly[1]->close);
        $this->assertEquals(500, $weekly[1]->volume);
    }
}
