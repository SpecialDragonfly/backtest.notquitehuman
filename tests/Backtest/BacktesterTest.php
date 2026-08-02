<?php

use App\Backtest\Backtester;
use App\Backtest\MACDPoint;
use App\Backtest\TradeSignal;
use App\Backtest\YahooFinanceData;
use PHPUnit\Framework\TestCase;

class BacktesterTest extends TestCase
{
    public function testBacktesterAppliesSeparateSlippage(): void
    {
        $priceData = [
            new YahooFinanceData(1, 0, 0, 0, 100, 0, 0),
            new YahooFinanceData(2, 0, 0, 0, 110, 0, 0),
        ];

        $signals = [
            new TradeSignal(1, 'BUY', 100),
            new TradeSignal(2, 'SELL', 110),
        ];

        $bt = new Backtester(buySlippagePercent: 1.0, sellSlippagePercent: 2.0); // 1% buy, 2% sell
        $result = $bt->run($priceData, $signals);

        // Expected: buy price = 101.0, sell price = 107.8
        $this->assertEquals(107.8 - 101.0, $result->trades[0]->getProfit());
    }

    public function testBacktestCalculatesProfit(): void
    {
        $priceData = [
            new YahooFinanceData(1, 0, 0, 0, 100, 0, 0),
            new YahooFinanceData(2, 0, 0, 0, 110, 0, 0),
            new YahooFinanceData(3, 0, 0, 0, 120, 0, 0),
        ];

        $signals = [
            new TradeSignal(1, 'BUY', 100),
            new TradeSignal(3, 'SELL', 120),
        ];

        $bt = new Backtester();
        $result = $bt->run($priceData, $signals);

        $this->assertEquals(1, $result->totalTrades);
        $this->assertEquals(20.0, $result->netProfit);
        $this->assertEquals(100.0, $result->winRate);
        $this->assertGreaterThanOrEqual(0, $result->maxDrawdown);
    }

    public function testOpenTradeClosedAtEnd(): void
    {
        $priceData = [
            new YahooFinanceData(1, 0, 0, 0, 100, 0, 0),
            new YahooFinanceData(2, 0, 0, 0, 105, 0, 0),
        ];

        $signals = [
            new TradeSignal(1, 'BUY', 100),
        ];

        $bt = new Backtester();
        $result = $bt->run($priceData, $signals);

        $this->assertEquals(1, $result->totalTrades);
        $this->assertEquals(5.0, $result->netProfit);
    }

    public function testOpenTradeClosedAtEndWithMACDPointData(): void
    {
        // MACDPoint has no adjClose property, unlike YahooFinanceData. Force-closing
        // an open position against MACDPoint data must use ->close, not ->adjClose.
        $priceData = [
            new MACDPoint(1, 100, null, null, null, null, null),
            new MACDPoint(2, 105, null, null, null, null, null),
        ];

        $signals = [
            new TradeSignal(1, 'BUY', 100),
        ];

        $bt = new Backtester();
        $result = $bt->run($priceData, $signals);

        $this->assertEquals(1, $result->totalTrades);
        $this->assertEquals(5.0, $result->netProfit);
        $this->assertEquals(105.0, $result->trades[0]->sellPrice);
    }
}