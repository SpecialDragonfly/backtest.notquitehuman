<?php

namespace App\Controller;

use App\Domain\User;
use App\Repository\BacktestRunRepository;
use App\Repository\MomentumHoldingsRepository;
use App\Service\MomentumRotationService;
use App\Service\StrategyComparisonService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class LabController
{
    private const TOP_N = 5;
    private const LOOKBACK_MONTHS = 12;
    private const REGIME_ON = true;

    public function __construct(
        private Environment $twig,
        private MomentumRotationService $rotationService,
        private StrategyComparisonService $comparisonService,
        private BacktestRunRepository $runRepository,
        private MomentumHoldingsRepository $holdingsRepository,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $cached = $this->runRepository->getLatestComparison();

        $target = $this->rotationService->getCurrentTarget(self::TOP_N, self::LOOKBACK_MONTHS, self::REGIME_ON);
        $current = $this->holdingsRepository->getCurrentHoldings();

        $response->getBody()->write($this->twig->render('lab/index.html.twig', [
            'isAdmin' => $this->isAdmin($request),
            'result' => $cached['result'] ?? null,
            'computedAt' => $cached['computedAt'] ?? null,
            'asOfMonth' => $target['month'],
            'regime' => $target['regime'],
            'targetHoldings' => $target['holdings'],
            'currentHoldings' => $current,
            'toBuy' => array_values(array_diff($target['holdings'], $current)),
            'toSell' => array_values(array_diff($current, $target['holdings'])),
            'toHold' => array_values(array_intersect($current, $target['holdings'])),
            'topN' => self::TOP_N,
            'lookbackMonths' => self::LOOKBACK_MONTHS,
        ]));
        return $response;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function apply(Request $request, Response $response, array $args): Response
    {
        $target = $this->rotationService->getCurrentTarget(self::TOP_N, self::LOOKBACK_MONTHS, self::REGIME_ON);
        $this->holdingsRepository->replaceHoldings($target['holdings']);
        return $response->withHeader('Location', '/lab')->withStatus(302);
    }

    /**
     * Recomputes the full strategy-comparison grid (~66 live price fetches on
     * a cold cache) and caches it — deliberately a manual action, not run on
     * every /lab page view.
     *
     * @param array<string, mixed> $args
     */
    public function run(Request $request, Response $response, array $args): Response
    {
        $result = $this->comparisonService->run();
        $this->runRepository->saveComparison($result);
        return $response->withHeader('Location', '/lab')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function customForm(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write($this->twig->render('lab/custom.html.twig', [
            'isAdmin' => $this->isAdmin($request),
            'result' => null,
        ]));
        return $response;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function customRun(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $topN = $this->intFromBody($body, 'topn', 5);
        $lookback = $this->intFromBody($body, 'lookback', 12);
        $regimeOn = is_array($body) && ($body['regime'] ?? '') === 'on';

        $result = $this->rotationService->runCustomBacktest($topN, $lookback, $regimeOn);

        $rebalanceLog = $result->rebalanceLog;

        $response->getBody()->write($this->twig->render('lab/custom.html.twig', [
            'isAdmin' => $this->isAdmin($request),
            'result' => [
                'topN' => $topN,
                'lookbackMonths' => $lookback,
                'regimeOn' => $regimeOn,
                'return' => $result->finalReturnPercent,
                'maxDrawdown' => $result->maxDrawdownPercent,
                'rebalanceCount' => $result->rebalanceCount,
                'recent' => array_slice($rebalanceLog, -6),
            ],
        ]));
        return $response;
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->getAttribute('user');
        return $user instanceof User && $user->isAdmin();
    }

    private function intFromBody(mixed $body, string $key, int $default): int
    {
        if (!is_array($body) || !isset($body[$key]) || !is_numeric($body[$key])) {
            return $default;
        }
        return (int) $body[$key];
    }
}
