<?php

namespace App\Controller;

use App\Repository\MomentumHoldingsRepository;
use App\Service\MomentumRotationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

/**
 * The live rebalance tool — web equivalent of the CLI prototype's
 * rebalance.php. Fixed config (Top5, 12mo lookback, regime filter on), the
 * combination validated in the CLI backtest.
 */
class DashboardController
{
    private const TOP_N = 5;
    private const LOOKBACK_MONTHS = 12;
    private const REGIME_ON = true;

    public function __construct(
        private Environment $twig,
        private MomentumRotationService $rotationService,
        private MomentumHoldingsRepository $holdingsRepository,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $target = $this->rotationService->getCurrentTarget(self::TOP_N, self::LOOKBACK_MONTHS, self::REGIME_ON);
        $current = $this->holdingsRepository->getCurrentHoldings();

        $toBuy = array_values(array_diff($target['holdings'], $current));
        $toSell = array_values(array_diff($current, $target['holdings']));
        $toHold = array_values(array_intersect($current, $target['holdings']));

        $response->getBody()->write($this->twig->render('dashboard/index.html.twig', [
            'asOfMonth' => $target['month'],
            'regime' => $target['regime'],
            'targetHoldings' => $target['holdings'],
            'currentHoldings' => $current,
            'toBuy' => $toBuy,
            'toSell' => $toSell,
            'toHold' => $toHold,
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
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
