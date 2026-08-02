<?php

namespace App\Service;

use App\Backtest\TickerUniverse;
use App\Line\TriggerEvaluationService;
use App\Repository\AlertRepository;
use App\Repository\TriggerRepository;

/**
 * The live half of alerting — called from bin/sync-prices.php for every
 * Yahoo symbol that got new rows in today's sync. Only ever checks whether a
 * trigger's state just flipped true on the latest bar
 * (TriggerEvaluationService::firedOnLatestBar()), never the full history —
 * so a brand-new trigger's entire past never floods in as live alerts the
 * first time this runs for its ticker. The historical "would have fired"
 * view (TriggerController) is a completely separate, read-only computation.
 */
class TriggerAlertService
{
    public function __construct(
        private TriggerRepository $triggerRepository,
        private TickerBacktestService $backtestService,
        private TriggerEvaluationService $triggerEvaluationService,
        private AlertRepository $alertRepository,
    ) {}

    public function evaluateSymbol(string $yahooSymbol): void
    {
        $tickers = array_filter(
            $this->triggerRepository->distinctActiveTickers(),
            fn(string $ticker) => TickerUniverse::toYahooSymbol($ticker) === $yahooSymbol,
        );

        foreach ($tickers as $ticker) {
            $prices = $this->backtestService->loadPrices($ticker);
            if (count($prices) < 2) {
                continue;
            }

            foreach ($this->triggerRepository->allActiveForTicker($ticker) as $trigger) {
                $states = $this->triggerEvaluationService->evaluateStates($trigger, $prices);
                if (!$this->triggerEvaluationService->firedOnLatestBar($states)) {
                    continue;
                }

                $firedOn = $prices[count($prices) - 1]->getDate();
                if (!$this->alertRepository->existsFor($trigger->getId(), $firedOn)) {
                    $this->alertRepository->create($trigger->getId(), $firedOn);
                }
            }
        }
    }
}
