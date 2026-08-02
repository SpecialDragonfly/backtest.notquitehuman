<?php

use App\Backtest\RSICalculator;
use App\Backtest\RSIPoint;
use App\Backtest\YahooFinanceData;
use PHPUnit\Framework\TestCase;

class RSICalculatorTest extends TestCase
{
    public function testRSIIsHighInAConsistentUptrend(): void
    {
        $prices = [];
        for ($i = 0; $i < 20; $i++) {
            $prices[] = new YahooFinanceData(time() + $i * 86400, 100, 100, 100, 100 + $i, 100 + $i, 1000);
        }

        $calc = new RSICalculator(14);
        $rsiData = $calc->calculate($prices);

        $this->assertInstanceOf(RSIPoint::class, $rsiData[0]);
        $this->assertNull($rsiData[13]->rsi); // not enough data yet
        $this->assertEquals(100.0, $rsiData[19]->rsi); // pure gains, no losses
    }

    public function testRSIIsLowInAConsistentDowntrend(): void
    {
        $prices = [];
        for ($i = 0; $i < 20; $i++) {
            $prices[] = new YahooFinanceData(time() + $i * 86400, 100, 100, 100, 200 - $i, 200 - $i, 1000);
        }

        $calc = new RSICalculator(14);
        $rsiData = $calc->calculate($prices);

        $this->assertEquals(0.0, $rsiData[19]->rsi); // pure losses, no gains
    }

    public function testRSIIsNeutralWhenPriceIsFlat(): void
    {
        $prices = [];
        for ($i = 0; $i < 20; $i++) {
            $prices[] = new YahooFinanceData(time() + $i * 86400, 100, 100, 100, 100, 100, 1000);
        }

        $calc = new RSICalculator(14);
        $rsiData = $calc->calculate($prices);

        $this->assertEquals(50.0, $rsiData[19]->rsi);
    }
}
