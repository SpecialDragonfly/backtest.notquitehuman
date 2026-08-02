<?php

namespace App\Controller;

use App\Backtest\TickerUniverse;
use App\Service\TickerBacktestService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

/**
 * The landing page: pick a ticker and a strategy, see its price chart with
 * buy/sell markers and the resulting trade log. Pure exploration tool — no
 * state is written here (that lives in the Lab's live rebalance tool).
 */
class DashboardController
{
    private const DEFAULT_TICKER = 'VUKE';

    public function __construct(
        private Environment $twig,
        private TickerBacktestService $backtestService,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $tickers = TickerUniverse::blueChipsAndIndexTrackers();
        $params = $request->getQueryParams();

        $ticker = (string) ($params['ticker'] ?? self::DEFAULT_TICKER);
        if (!in_array($ticker, $tickers, true)) {
            $ticker = self::DEFAULT_TICKER;
        }

        $strategy = (string) ($params['strategy'] ?? TickerBacktestService::DEFAULT_STRATEGY);
        if (!array_key_exists($strategy, TickerBacktestService::STRATEGIES)) {
            $strategy = TickerBacktestService::DEFAULT_STRATEGY;
        }

        $run = $this->backtestService->run($ticker, $strategy);

        $response->getBody()->write($this->twig->render('dashboard/index.html.twig', [
            'tickers' => $tickers,
            'strategies' => TickerBacktestService::STRATEGIES,
            'selectedTicker' => $ticker,
            'selectedStrategy' => $strategy,
            'symbol' => $run['symbol'],
            'hasData' => $run['hasData'],
            'chartDataJson' => json_encode($run['chart'], JSON_THROW_ON_ERROR),
            'trades' => $run['trades'],
            'summary' => $run['summary'],
        ]));
        return $response;
    }
}
