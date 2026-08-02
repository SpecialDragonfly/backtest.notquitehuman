<?php

use App\Backtest\MACDPoint;
use App\Backtest\Strategies\MACDCrossoverStrategy;
use PHPUnit\Framework\TestCase;

class MACDCrossoverStrategyTest extends TestCase
{
    public function testGeneratesBuyAndSellSignals(): void
    {
        // Simplified MACD data with one clear crossover
        $macdData = [
            new MACDPoint(time(), 100, null, null, -0.5, -0.2, null),
            new MACDPoint(time()+86400, 101, null, null, 0.2, -0.1, null), // cross up
            new MACDPoint(time()+172800, 102, null, null, 0.3, 0.1, null),
            new MACDPoint(time()+259200, 103, null, null, -0.1, 0.2, null), // cross down
        ];

        $strategy = new MACDCrossoverStrategy();
        $signals = $strategy->generateSignals($macdData);

        $this->assertCount(2, $signals);
        $this->assertEquals('BUY', $signals[0]->type);
        $this->assertEquals('SELL', $signals[1]->type);
    }
}