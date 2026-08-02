<?php

use App\Backtest\MACDADXPoint;
use App\Backtest\Strategies\MACDADXFilteredStrategy;
use PHPUnit\Framework\TestCase;

class MACDADXFilteredStrategyTest extends TestCase
{
    public function testBuySuppressedWhenADXBelowThreshold(): void
    {
        $data = [
            new MACDADXPoint(time(), 100, -0.5, -0.2, 15.0),
            new MACDADXPoint(time() + 86400, 101, 0.2, -0.1, 18.0), // MACD crosses up, ADX shows no trend
        ];

        $strategy = new MACDADXFilteredStrategy();
        $signals = $strategy->generateSignals($data);

        $this->assertCount(0, $signals);
    }

    public function testBuyTakenAndSellAlwaysFiresRegardlessOfADX(): void
    {
        $data = [
            new MACDADXPoint(time(), 100, -0.5, -0.2, 30.0),
            new MACDADXPoint(time() + 86400, 101, 0.2, -0.1, 32.0), // MACD crosses up, ADX confirms trend
            new MACDADXPoint(time() + 172800, 99, -0.1, 0.1, 10.0), // MACD crosses down; ADX irrelevant on exit
        ];

        $strategy = new MACDADXFilteredStrategy();
        $signals = $strategy->generateSignals($data);

        $this->assertCount(2, $signals);
        $this->assertEquals('BUY', $signals[0]->type);
        $this->assertEquals('SELL', $signals[1]->type);
    }
}
