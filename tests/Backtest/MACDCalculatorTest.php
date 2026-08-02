<?php

use App\Backtest\MACDCalculator;
use App\Backtest\MACDPoint;
use App\Backtest\YahooFinanceData;
use PHPUnit\Framework\TestCase;

class MACDCalculatorTest extends TestCase
{
    public function testMACDHasExpectedLength(): void
    {
        $prices = [];
        for ($i = 0; $i < 50; $i++) {
            $prices[] = new YahooFinanceData(
                time() + $i * 86400,
                100,
                100,
                100,
                100 + $i,
                100 + $i,
                1000
            );
        }

        $calc = new MACDCalculator();
        $macdData = $calc->calculate($prices);

        $this->assertCount(50, $macdData);
        $this->assertInstanceOf(MACDPoint::class, $macdData[0]);
    }

    public function testMACDValuesIncreaseWithTrend(): void
    {
        // Upward trend → MACD should generally rise
        $prices = [];
        for ($i = 0; $i < 60; $i++) {
            $prices[] = new YahooFinanceData(
                time() + $i * 86400,
                100,
                100,
                100,
                100 + $i,
                100 + $i,
                1000
            );
        }

        $calc = new MACDCalculator();
        $macdData = $calc->calculate($prices);

        $macdValues = array_column($macdData, 'macd');
        $lastMacd = array_filter($macdValues);
        $this->assertGreaterThan(0, end($lastMacd));
    }
}