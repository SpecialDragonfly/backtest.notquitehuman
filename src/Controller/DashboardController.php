<?php

namespace App\Controller;

use App\Domain\Trigger;
use App\Domain\User;
use App\Line\FormulaEvaluator;
use App\Repository\LineRepository;
use App\Repository\TickerRepository;
use App\Repository\TriggerRepository;
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
        private LineRepository $lineRepository,
        private FormulaEvaluator $formulaEvaluator,
        private TriggerRepository $triggerRepository,
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

        // Lines/Triggers are per-user and hidden from guests entirely (same
        // reasoning as the Lab: the guest account is a single shared row, so
        // per-user saved state doesn't make sense there).
        $userLines = $isGuest ? [] : $this->lineRepository->allForUser($user->getId());
        $linesById = [];
        foreach ($userLines as $line) {
            $linesById[$line->getId()] = $line;
        }

        // Every owned Line is evaluated up front (not just ones already
        // plotted somewhere) — the sidebar's drag targets are chart panels
        // built entirely client-side, so any line needs to be draggable onto
        // any panel without a round-trip to fetch its data on drop.
        $chart = $run['chart'];
        $chart['lines'] = [];
        if ($run['hasData'] && !empty($userLines)) {
            $prices = $this->backtestService->loadPrices($ticker);
            foreach ($userLines as $line) {
                $formula = $line->getFormula();
                $chart['lines'][] = [
                    'id' => $line->getId(),
                    'name' => $line->getName(),
                    'scale' => FormulaEvaluator::scaleFor($formula),
                    'points' => $this->formulaEvaluator->evaluate($formula, $prices),
                ];
            }
        }

        // Triggers, scoped to whichever ticker is charted right now — the
        // sidebar next to the chart, not the full cross-ticker /triggers list.
        $tickerTriggerRows = array_map(function (Trigger $trigger) use ($linesById) {
            $condition = $trigger->getConditions()[0] ?? null;
            return [
                'trigger' => $trigger,
                'lineAName' => $condition !== null ? (($linesById[$condition->getLineAId()] ?? null)?->getName() ?? '(deleted line)') : '?',
                'lineBName' => $condition !== null ? (($linesById[$condition->getLineBId()] ?? null)?->getName() ?? '(deleted line)') : '?',
                'operator' => $condition?->getOperator(),
            ];
        }, $isGuest ? [] : $this->triggerRepository->allForUserAndTicker($user->getId(), $ticker));

        // Chart-panel layout (which lines sit on which panel, and how many
        // extra blank panels exist) is client-side/URL-driven state, not
        // persisted server-side — passed straight through for the page's JS
        // to restore on load; it validates line ids itself against the
        // chart.lines payload above, so a stale/tampered value just gets
        // dropped rather than trusted.
        $layout = (string) ($params['layout'] ?? '');

        $response->getBody()->write($this->twig->render('dashboard/index.html.twig', [
            'isAdmin' => $user instanceof User && $user->isAdmin(),
            'isGuest' => $isGuest,
            'tickers' => $tickers,
            'strategies' => TickerBacktestService::STRATEGIES,
            'selectedTicker' => $ticker,
            'selectedStrategy' => $strategy,
            'symbol' => $run['symbol'],
            'hasData' => $run['hasData'],
            'chartDataJson' => json_encode($chart, JSON_THROW_ON_ERROR),
            'trades' => $run['trades'],
            'summary' => $run['summary'],
            'userLines' => $userLines,
            'layout' => $layout,
            'tickerTriggerRows' => $tickerTriggerRows,
        ]));
        return $response;
    }
}
