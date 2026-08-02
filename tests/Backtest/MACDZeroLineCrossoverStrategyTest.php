<?php

use App\Backtest\MACDPoint;
use App\Backtest\Strategies\MACDZeroLineCrossoverStrategy;
use PHPUnit\Framework\TestCase;

class MACDZeroLineCrossoverStrategyTest extends TestCase
{
    public function testGeneratesBuyAndSellOnZeroLineCross(): void
    {
        $macdData = [
            new MACDPoint(time(), 100, null, null, -0.3, -0.2, null),
            new MACDPoint(time() + 86400, 101, null, null, 0.2, -0.1, null), // cross above zero
            new MACDPoint(time() + 172800, 102, null, null, 0.4, 0.1, null),
            new MACDPoint(time() + 259200, 103, null, null, -0.1, 0.2, null), // cross below zero
        ];

        $strategy = new MACDZeroLineCrossoverStrategy();
        $signals = $strategy->generateSignals($macdData);

        $this->assertCount(2, $signals);
        $this->assertEquals('BUY', $signals[0]->type);
        $this->assertEquals('SELL', $signals[1]->type);
    }

    public function testIgnoresSignalLineMovementWithoutZeroCross(): void
    {
        // Signal-line crossover happens here but MACD never crosses zero — a
        // zero-line strategy must stay flat.
        $macdData = [
            new MACDPoint(time(), 100, null, null, 0.5, 0.6, null),
            new MACDPoint(time() + 86400, 101, null, null, 0.7, 0.4, null), // signal-line cross, but macd stays positive
        ];

        $strategy = new MACDZeroLineCrossoverStrategy();
        $signals = $strategy->generateSignals($macdData);

        $this->assertCount(0, $signals);
    }
}
