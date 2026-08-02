<?php

namespace App\Controller;

use App\Domain\User;
use App\Repository\TickerRepository;
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

    // The guest account (see db/migrations/0008_seed_guest_user.php) can only
    // chart these — enforced here, not just hidden in the dropdown, so the
    // restriction holds even if a guest edits the ?ticker= query param.
    private const GUEST_TICKERS = ['BARC', 'TSCO', 'VOD', 'NXT', 'SSE'];

    public function __construct(
        private Environment $twig,
        private TickerBacktestService $backtestService,
        private TickerRepository $tickerRepository,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $user = $request->getAttribute('user');
        $isGuest = $user instanceof User && $user->isGuest();

        $tickers = $this->tickerRepository->all();
        if ($isGuest) {
            $tickers = array_values(array_intersect($tickers, self::GUEST_TICKERS));
        }

        $defaultTicker = in_array(self::DEFAULT_TICKER, $tickers, true) ? self::DEFAULT_TICKER : ($tickers[0] ?? self::DEFAULT_TICKER);
        $ticker = (string) ($params['ticker'] ?? $defaultTicker);
        if (!in_array($ticker, $tickers, true)) {
            $ticker = $defaultTicker;
        }

        $strategy = (string) ($params['strategy'] ?? TickerBacktestService::DEFAULT_STRATEGY);
        if (!array_key_exists($strategy, TickerBacktestService::STRATEGIES)) {
            $strategy = TickerBacktestService::DEFAULT_STRATEGY;
        }

        $run = $this->backtestService->run($ticker, $strategy);

        $response->getBody()->write($this->twig->render('dashboard/index.html.twig', [
            'isAdmin' => $user instanceof User && $user->isAdmin(),
            'isGuest' => $isGuest,
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
