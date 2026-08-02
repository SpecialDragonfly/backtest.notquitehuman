<?php

use App\Backtest\SeriesMath;
use PHPUnit\Framework\TestCase;

class SeriesMathTest extends TestCase
{
    public function testSmaIsNullDuringWarmupThenAveragesTheTrailingWindow(): void
    {
        $result = SeriesMath::sma([1.0, 2.0, 3.0, 4.0, 5.0], 3);

        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertEqualsWithDelta(2.0, $result[2], 0.0001); // avg(1,2,3)
        $this->assertEqualsWithDelta(3.0, $result[3], 0.0001); // avg(2,3,4)
        $this->assertEqualsWithDelta(4.0, $result[4], 0.0001); // avg(3,4,5)
    }

    public function testSmaPropagatesNullsThroughAnyWindowThatContainsOne(): void
    {
        $result = SeriesMath::sma([1.0, null, 3.0, 4.0], 2);

        $this->assertNull($result[1]); // window [1, null]
        $this->assertNull($result[2]); // window [null, 3]
        $this->assertEqualsWithDelta(3.5, $result[3], 0.0001); // window [3, 4]
    }

    public function testEmaSeedsWithSimpleAverageThenRecurses(): void
    {
        $result = SeriesMath::ema([1.0, 2.0, 3.0, 4.0, 5.0], 3);

        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertEqualsWithDelta(2.0, $result[2], 0.0001); // seed = avg(1,2,3)
        // k = 2/(3+1) = 0.5; ema[3] = 4*0.5 + 2*0.5 = 3.0
        $this->assertEqualsWithDelta(3.0, $result[3], 0.0001);
        // ema[4] = 5*0.5 + 3.0*0.5 = 4.0
        $this->assertEqualsWithDelta(4.0, $result[4], 0.0001);
    }

    public function testEmaIsConstantOverAFlatSeries(): void
    {
        $result = SeriesMath::ema([10.0, 10.0, 10.0, 10.0], 2);

        $this->assertEqualsWithDelta(10.0, $result[3], 0.0001);
    }
}
