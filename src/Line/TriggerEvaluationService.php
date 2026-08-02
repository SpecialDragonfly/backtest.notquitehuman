<?php

namespace App\Line;

use App\Backtest\YahooFinanceData;
use App\Domain\Trigger;
use App\Domain\TriggerCondition;
use App\Repository\LineRepository;

/**
 * A Trigger's state on a given day is the AND across all its conditions;
 * "2 lines crossing" is just the false/null -> true edge of that state for a
 * single-condition trigger — no separate crossover-specific logic needed,
 * and ANDing several conditions together later falls out of the same model.
 */
class TriggerEvaluationService
{
    public function __construct(
        private LineRepository $lineRepository,
        private FormulaEvaluator $formulaEvaluator,
    ) {}

    /**
     * One entry per bar in $prices; null while any condition's lines are
     * still warming up (e.g. inside an SMA's period).
     *
     * @param YahooFinanceData[] $prices
     * @return array<int, ?bool>
     */
    public function evaluateStates(Trigger $trigger, array $prices): array
    {
        $conditionStates = array_map(
            fn(TriggerCondition $c) => $this->evaluateCondition($c, $prices),
            $trigger->getConditions(),
        );

        $states = [];
        foreach ($prices as $i => $p) {
            $dayStates = array_map(fn(array $cs) => $cs[$i], $conditionStates);
            $states[] = in_array(null, $dayStates, true) ? null : !in_array(false, $dayStates, true);
        }
        return $states;
    }

    /**
     * Every day the trigger's overall state transitioned from false/null to
     * true — the historical "would have fired" view. Purely a read: never
     * writes to the alerts table.
     *
     * @param array<int, ?bool> $states Same length/order as $prices
     * @param YahooFinanceData[] $prices
     * @return string[] Y-m-d dates, chronological
     */
    public function findTransitionDates(array $states, array $prices): array
    {
        $dates = [];
        $prev = null;
        foreach ($states as $i => $state) {
            if ($state === true && $prev !== true) {
                $dates[] = $prices[$i]->getDate();
            }
            $prev = $state;
        }
        return $dates;
    }

    /**
     * True only if the state transitioned from false/null to true on the
     * very last bar. This — not findTransitionDates() — is what the live
     * daily-sync cron checks, since a trigger created today would otherwise
     * see its entire past as a flood of "new" transitions.
     *
     * @param array<int, ?bool> $states
     */
    public function firedOnLatestBar(array $states): bool
    {
        $count = count($states);
        if ($count < 2) {
            return false;
        }
        return $states[$count - 1] === true && $states[$count - 2] !== true;
    }

    /**
     * @param YahooFinanceData[] $prices
     * @return array<int, ?bool>
     */
    private function evaluateCondition(TriggerCondition $condition, array $prices): array
    {
        $lineA = $this->lineRepository->find($condition->getLineAId());
        $lineB = $this->lineRepository->find($condition->getLineBId());
        if ($lineA === null || $lineB === null) {
            return array_fill(0, count($prices), null);
        }

        $a = $this->formulaEvaluator->evaluate($lineA->getFormula(), $prices);
        $b = $this->formulaEvaluator->evaluate($lineB->getFormula(), $prices);

        $states = [];
        foreach ($a as $i => $pointA) {
            $av = $pointA['v'];
            $bv = $b[$i]['v'] ?? null;
            $states[] = ($av === null || $bv === null)
                ? null
                : ($condition->getOperator() === TriggerCondition::OPERATOR_ABOVE ? $av > $bv : $av < $bv);
        }
        return $states;
    }
}
