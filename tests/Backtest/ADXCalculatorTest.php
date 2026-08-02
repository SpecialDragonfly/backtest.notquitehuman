<?php

use App\Backtest\ADXCalculator;
use App\Backtest\ADXPoint;
use App\Backtest\YahooFinanceData;
use PHPUnit\Framework\TestCase;

class ADXCalculatorTest extends TestCase
{
    public function testADXIsHighInAConsistentUptrend(): void
    {
        $prices = [];
        for ($i = 0; $i < 40; $i++) {
            $close = 100 + $i * 2;
            $prices[] = new YahooFinanceData(time() + $i * 86400, $close, $close + 1, $close - 1, $close, $close, 1000);
        }

        $calc = new ADXCalculator(14);
        $adxData = $calc->calculate($prices);
        $last = end($adxData);

        $this->assertInstanceOf(ADXPoint::class, $adxData[0]);
        $this->assertNotNull($last->adx);
        $this->assertGreaterThan(25, $last->adx);
        $this->assertGreaterThan($last->minusDI, $last->plusDI);
    }

    public function testADXIsLowInAChoppyMarket(): void
    {
        $prices = [];
        for ($i = 0; $i < 40; $i++) {
            $close = 100 + ($i % 2 === 0 ? 0 : 2);
            $prices[] = new YahooFinanceData(time() + $i * 86400, $close, $close + 1, $close - 1, $close, $close, 1000);
        }

        $calc = new ADXCalculator(14);
        $adxData = $calc->calculate($prices);
        $last = end($adxData);

        $this->assertNotNull($last->adx);
        $this->assertLessThan(25, $last->adx);
    }
}
