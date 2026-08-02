<?php

use App\Backtest\PriceRSIPoint;
use App\Backtest\Strategies\MeanReversionRSIStrategy;
use PHPUnit\Framework\TestCase;

class MeanReversionRSIStrategyTest extends TestCase
{
    public function testBuysOnOversoldBounceAndSellsOnOverbought(): void
    {
        $data = [
            new PriceRSIPoint(time(), 100, 25.0),
            new PriceRSIPoint(time() + 86400, 101, 35.0), // bounce above 30 -> BUY
            new PriceRSIPoint(time() + 172800, 105, 60.0), // still in position, no exit yet
            new PriceRSIPoint(time() + 259200, 108, 72.0), // overbought -> SELL
        ];

        $strategy = new MeanReversionRSIStrategy();
        $signals = $strategy->generateSignals($data);

        $this->assertCount(2, $signals);
        $this->assertEquals('BUY', $signals[0]->type);
        $this->assertEquals(101.0, $signals[0]->price);
        $this->assertEquals('SELL', $signals[1]->type);
        $this->assertEquals(108.0, $signals[1]->price);
    }

    public function testSellsWhenBounceFailsBackBelowMidline(): void
    {
        $data = [
            new PriceRSIPoint(time(), 100, 25.0),
            new PriceRSIPoint(time() + 86400, 101, 35.0), // bounce above 30 -> BUY
            new PriceRSIPoint(time() + 172800, 103, 55.0), // still in position
            new PriceRSIPoint(time() + 259200, 99, 45.0), // falls back below midline -> SELL
        ];

        $strategy = new MeanReversionRSIStrategy();
        $signals = $strategy->generateSignals($data);

        $this->assertCount(2, $signals);
        $this->assertEquals('BUY', $signals[0]->type);
        $this->assertEquals('SELL', $signals[1]->type);
        $this->assertEquals(99.0, $signals[1]->price);
    }
}
