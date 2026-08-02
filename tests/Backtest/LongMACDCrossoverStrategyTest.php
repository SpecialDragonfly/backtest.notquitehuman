<?php

use App\Backtest\MACDPoint;
use App\Backtest\Strategies\LongMACDCrossoverStrategy;
use PHPUnit\Framework\TestCase;

class LongMACDCrossoverStrategyTest extends TestCase
{
    public function testGeneratesLongOnlyBuySignal(): void
    {
        $macdData = [
            new MACDPoint(time(), 100, null, null, -0.5, -0.2, null),
            new MACDPoint(time() + 86400, 101, null, null, 0.2, -0.1, null), // cross up, macd > 0
            new MACDPoint(time() + 172800, 102, null, null, -0.1, 0.1, null), // cross down
        ];

        $strategy = new LongMACDCrossoverStrategy();
        $signals = $strategy->generateSignals($macdData);

        $this->assertCount(2, $signals);
        $this->assertEquals('BUY', $signals[0]->type);
        $this->assertEquals('SELL', $signals[1]->type);
    }

    public function testClosesOpenPositionAtEndOfData(): void
    {
        // Position opens and never gets a down-cross before the data ends: the
        // strategy must force-close using the *last data point*, not crash.
        $macdData = [
            new MACDPoint(time(), 100, null, null, -0.5, -0.2, null),
            new MACDPoint(time() + 86400, 101, null, null, 0.2, -0.1, null), // BUY: cross up, macd > 0
            new MACDPoint(time() + 172800, 105, null, null, 0.3, 0.1, null), // still above signal
        ];

        $strategy = new LongMACDCrossoverStrategy();
        $signals = $strategy->generateSignals($macdData);

        $this->assertCount(2, $signals);
        $this->assertEquals('BUY', $signals[0]->type);

        $last = $macdData[count($macdData) - 1];
        $this->assertEquals('SELL', $signals[1]->type);
        $this->assertEquals($last->timestamp, $signals[1]->timestamp);
        $this->assertEquals($last->close, $signals[1]->price);
    }
}
