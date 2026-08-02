<?php

namespace App\Controller;

use App\Backtest\TickerUniverse;
use App\Repository\PriceHistoryRepository;
use App\Repository\TickerRepository;
use App\Service\PriceSyncService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AdminTickerController
{
    // LSE-style: letters/digits, optionally with a single trailing or
    // mid-ticker "." (e.g. "BP.", "BT.A") — mirrors TickerUniverse::toYahooSymbol.
    private const TICKER_PATTERN = '/^[A-Z0-9]{1,8}(\.[A-Z0-9]{1,2})?\.?$/';

    public function __construct(
        private Environment $twig,
        private TickerRepository $tickerRepository,
        private PriceHistoryRepository $priceHistoryRepository,
        private PriceSyncService $priceSyncService,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $coverage = $this->priceHistoryRepository->getCoverageSummary();

        $rows = array_map(function (string $ticker) use ($coverage) {
            $symbol = TickerUniverse::toYahooSymbol($ticker);
            $info = $coverage[$symbol] ?? null;
            return [
                'ticker' => $ticker,
                'symbol' => $symbol,
                'firstDate' => $info['firstDate'] ?? null,
                'lastDate' => $info['lastDate'] ?? null,
                'days' => $info['days'] ?? 0,
            ];
        }, $this->tickerRepository->all());

        $params = $request->getQueryParams();

        $response->getBody()->write($this->twig->render('admin/tickers.html.twig', [
            'isAdmin' => true,
            'rows' => $rows,
            'error' => $params['error'] ?? null,
            'added' => $params['added'] ?? null,
        ]));
        return $response;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function add(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $ticker = strtoupper(trim(is_array($body) && is_string($body['ticker'] ?? null) ? $body['ticker'] : ''));

        if ($ticker === '' || !preg_match(self::TICKER_PATTERN, $ticker)) {
            return $this->redirectWithError($response, 'That doesn\'t look like a valid LSE-style ticker (e.g. VOD, BP., BT.A).');
        }

        if ($this->tickerRepository->exists($ticker)) {
            return $this->redirectWithError($response, "\"{$ticker}\" is already tracked.");
        }

        $this->tickerRepository->add($ticker);

        // Backfill immediately rather than waiting for the next daily cron,
        // so the ticker has usable data (and shows up correctly on this
        // page) as soon as it's added.
        $symbol = TickerUniverse::toYahooSymbol($ticker);
        $rows = $this->priceSyncService->sync($symbol);

        if ($rows === 0) {
            return $response
                ->withHeader('Location', '/admin/tickers?error=' . urlencode(
                    "\"{$ticker}\" was added, but no historical data came back from Yahoo for \"{$symbol}\" — check the symbol; it'll retry on the next daily sync."
                ))
                ->withStatus(302);
        }

        return $response
            ->withHeader('Location', '/admin/tickers?added=' . urlencode("{$ticker} ({$rows} day(s) backfilled)"))
            ->withStatus(302);
    }

    private function redirectWithError(Response $response, string $message): Response
    {
        return $response->withHeader('Location', '/admin/tickers?error=' . urlencode($message))->withStatus(302);
    }
}
