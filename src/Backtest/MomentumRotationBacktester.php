<?php
namespace App\Backtest;

class MomentumRotationBacktester
{
    public function __construct(
        private int $topN = 5,
        private int $lookbackMonths = 6,
        private float $buyCostPercent = 0.6,
        private float $sellCostPercent = 0.1,
        private int $regimeMAMonths = 10
    ) {}

    /**
     * Cross-sectional momentum: each month, rank the universe by trailing
     * return and hold the top N equally-weighted, rebalancing monthly.
     * Tickers that remain in the top N across a rebalance are carried over
     * without a round-trip cost; only actual entries/exits are charged.
     *
     * If $regimePrices is given, the portfolio moves entirely to cash for any
     * month where the regime index closes below its own trailing
     * $regimeMAMonths-month average (the monthly analogue of a 200-day MA
     * trend filter) rather than ranking and holding momentum leaders.
     *
     * @param array<string, YahooFinanceData[]> $pricesByTicker Daily prices keyed by ticker
     * @param YahooFinanceData[]|null $regimePrices Daily prices for a broad market index
     */
    public function run(array $pricesByTicker, ?array $regimePrices = null): MomentumRotationResult
    {
        $monthlyCloses = $this->buildMonthlyCloses($pricesByTicker);
        $months = $this->sortedMonthKeys($monthlyCloses);
        $regimeMonthlyCloses = $regimePrices !== null
            ? $this->buildMonthlyCloses(['REGIME' => $regimePrices])['REGIME']
            : null;

        $holdings = [];
        $equity = 1.0;
        $peak = 1.0;
        $maxDrawdownPercent = 0.0;
        $equityCurve = [];
        $rebalanceLog = [];

        for ($i = $this->lookbackMonths; $i < count($months); $i++) {
            $month = $months[$i];
            $prevMonth = $months[$i - 1];

            if (!empty($holdings)) {
                $returns = [];
                foreach ($holdings as $ticker) {
                    if (isset($monthlyCloses[$ticker][$month], $monthlyCloses[$ticker][$prevMonth])) {
                        $returns[] = $monthlyCloses[$ticker][$month] / $monthlyCloses[$ticker][$prevMonth] - 1;
                    }
                }
                if (!empty($returns)) {
                    $portfolioReturn = array_sum($returns) / count($returns);
                    $equity *= (1 + $portfolioReturn);
                }
            }
            // Logged every month, holdings or cash, so the curve has no gaps.
            $peak = max($peak, $equity);
            $maxDrawdownPercent = max($maxDrawdownPercent, ($peak - $equity) / $peak * 100);
            $equityCurve[] = ['month' => $month, 'equity' => $equity];

            $riskOn = $this->isRiskOn($regimeMonthlyCloses, $month);

            if ($riskOn) {
                $lookbackMonth = $months[$i - $this->lookbackMonths];
                $scores = [];
                foreach ($monthlyCloses as $ticker => $closes) {
                    if (isset($closes[$month], $closes[$lookbackMonth]) && $closes[$lookbackMonth] > 0) {
                        $scores[$ticker] = $closes[$month] / $closes[$lookbackMonth] - 1;
                    }
                }
                arsort($scores);
                $newHoldings = array_slice(array_keys($scores), 0, $this->topN);
            } else {
                $newHoldings = [];
            }

            $entering = array_diff($newHoldings, $holdings);
            $leaving = array_diff($holdings, $newHoldings);
            if ($this->topN > 0 && (!empty($entering) || !empty($leaving))) {
                $costDrag = (count($entering) * $this->buyCostPercent + count($leaving) * $this->sellCostPercent)
                    / $this->topN / 100;
                $equity *= (1 - $costDrag);
            }

            $rebalanceLog[] = ['month' => $month, 'holdings' => $newHoldings, 'regime' => $riskOn ? 'risk-on' : 'risk-off'];
            $holdings = $newHoldings;
        }

        if (!empty($holdings)) {
            $equity *= (1 - (count($holdings) * $this->sellCostPercent) / $this->topN / 100);
            $peak = max($peak, $equity);
            $maxDrawdownPercent = max($maxDrawdownPercent, ($peak - $equity) / $peak * 100);
        }

        return new MomentumRotationResult(
            finalReturnPercent: round(($equity - 1) * 100, 2),
            maxDrawdownPercent: round($maxDrawdownPercent, 2),
            rebalanceCount: count($rebalanceLog),
            equityCurve: $equityCurve,
            rebalanceLog: $rebalanceLog
        );
    }

    /**
     * True if there is no regime filter configured, not enough regime history
     * yet to judge, or the regime index's close is at/above its own trailing
     * $regimeMAMonths-month average. False (risk-off) only when there is
     * enough history and the index is below that average.
     *
     * @param array<string, float>|null $regimeMonthlyCloses "YYYY-MM" => close
     */
    private function isRiskOn(?array $regimeMonthlyCloses, string $month): bool
    {
        if ($regimeMonthlyCloses === null || !isset($regimeMonthlyCloses[$month])) {
            return true;
        }

        $regimeMonths = array_keys($regimeMonthlyCloses);
        sort($regimeMonths);
        $idx = array_search($month, $regimeMonths, true);
        if ($idx === false || $idx + 1 < $this->regimeMAMonths) {
            return true;
        }

        $window = array_slice($regimeMonths, $idx - $this->regimeMAMonths + 1, $this->regimeMAMonths);
        $sma = array_sum(array_map(fn($m) => $regimeMonthlyCloses[$m], $window)) / count($window);

        return $regimeMonthlyCloses[$month] >= $sma;
    }

    /**
     * @param array<string, YahooFinanceData[]> $pricesByTicker
     * @return array<string, array<string, float>> ticker => ["YYYY-MM" => close]
     */
    private function buildMonthlyCloses(array $pricesByTicker): array
    {
        $result = [];
        foreach ($pricesByTicker as $ticker => $prices) {
            $monthly = [];
            foreach ($prices as $p) {
                // Prices are chronological, so the last write per month wins,
                // giving the last available close of that month.
                $monthly[date('Y-m', $p->timestamp)] = $p->close;
            }
            $result[$ticker] = $monthly;
        }
        return $result;
    }

    /**
     * @param array<string, array<string, float>> $monthlyCloses
     * @return string[]
     */
    private function sortedMonthKeys(array $monthlyCloses): array
    {
        $months = [];
        foreach ($monthlyCloses as $closes) {
            foreach (array_keys($closes) as $m) {
                $months[$m] = true;
            }
        }
        $keys = array_keys($months);
        sort($keys);
        return $keys;
    }
}
