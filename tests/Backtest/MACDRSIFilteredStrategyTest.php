<?php

use App\Backtest\MACDRSIPoint;
use App\Backtest\Strategies\MACDRSIFilteredStrategy;
use PHPUnit\Framework\TestCase;

class MACDRSIFilteredStrategyTest extends TestCase
{
    public function testBuySuppressedWhenRSIDoesNotConfirm(): void
    {
        $data = [
            new MACDRSIPoint(time(), 100, -0.5, -0.2, 40.0),
            new MACDRSIPoint(time() + 86400, 101, 0.2, -0.1, 45.0), // MACD crosses up, RSI still below 50
        ];

        $strategy = new MACDRSIFilteredStrategy();
        $signals = $strategy->generateSignals($data);

        $this->assertCount(0, $signals);
    }

    public function testBuyTakenAndSellAlwaysFiresRegardlessOfRSI(): void
    {
        $data = [
            new MACDRSIPoint(time(), 100, -0.5, -0.2, 48.0),
            new MACDRSIPoint(time() + 86400, 101, 0.2, -0.1, 55.0), // MACD crosses up, RSI confirms
            new MACDRSIPoint(time() + 172800, 99, -0.1, 0.1, 30.0), // MACD crosses down; RSI irrelevant on exit
        ];

        $strategy = new MACDRSIFilteredStrategy();
        $signals = $strategy->generateSignals($data);

        $this->assertCount(2, $signals);
        $this->assertEquals('BUY', $signals[0]->type);
        $this->assertEquals('SELL', $signals[1]->type);
    }
}
