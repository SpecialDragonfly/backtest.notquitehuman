<?php

namespace App\Line;

use App\Backtest\RSICalculator;
use App\Backtest\RSIPoint;
use App\Backtest\SeriesMath;
use App\Backtest\YahooFinanceData;
use InvalidArgumentException;

/**
 * Evaluates a Line's JSON formula tree against a price series into an
 * index-aligned {t, v} series, matching the point-class convention used
 * throughout App\Backtest (one entry per bar, null while warming up).
 *
 * v1's no-code builder only ever composes flat, single-function formulas
 * (PRICE/SMA/EMA/RSI/CONSTANT), but ADD/SUB/MUL/DIV are supported here too
 * so a future "advanced" nested builder needs no change to this evaluator or
 * the stored formula format. All price-based functions use adjClose, matching
 * the rest of the app's dividend/split-adjusted convention.
 */
class FormulaEvaluator
{
    public const FUNCTIONS = ['PRICE', 'SMA', 'EMA', 'RSI', 'CONSTANT'];

    public const SCALE_PRICE = 'price';
    public const SCALE_OSCILLATOR = 'oscillator';

    // Which functions are bounded/oscillator-scaled (0-100) rather than
    // price-scaled — the dashboard chart plots these on their own secondary
    // panel instead of squashing them onto the price axis. CONSTANT/ADD/SUB/
    // MUL/DIV don't have a fixed scale of their own; they inherit whatever a
    // user built them to compare against, so they default to price (the
    // common case) rather than guessing.
    private const OSCILLATOR_FUNCTIONS = ['RSI'];

    /**
     * @param array<string, mixed> $formula
     */
    public static function scaleFor(array $formula): string
    {
        $fn = strtoupper((string) ($formula['fn'] ?? ''));
        return in_array($fn, self::OSCILLATOR_FUNCTIONS, true) ? self::SCALE_OSCILLATOR : self::SCALE_PRICE;
    }

    /**
     * @param array<string, mixed> $formula
     * @param YahooFinanceData[] $prices
     * @return list<array{t: int, v: ?float}>
     */
    public function evaluate(array $formula, array $prices): array
    {
        $values = $this->evaluateValues($formula, $prices);

        $result = [];
        foreach ($prices as $i => $p) {
            $result[] = ['t' => $p->timestamp, 'v' => $values[$i] ?? null];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $formula
     * @param YahooFinanceData[] $prices
     * @return array<int, ?float>
     */
    private function evaluateValues(array $formula, array $prices): array
    {
        $fn = strtoupper((string) ($formula['fn'] ?? ''));

        return match ($fn) {
            'PRICE' => array_map(fn(YahooFinanceData $p) => $p->adjClose, $prices),
            'CONSTANT' => array_fill(0, count($prices), $this->requireFloat($formula, 'value')),
            'SMA' => SeriesMath::sma(
                array_map(fn(YahooFinanceData $p) => $p->adjClose, $prices),
                $this->requireInt($formula, 'period'),
            ),
            'EMA' => SeriesMath::ema(
                array_map(fn(YahooFinanceData $p) => $p->adjClose, $prices),
                $this->requireInt($formula, 'period'),
            ),
            'RSI' => array_map(
                fn(RSIPoint $point) => $point->rsi,
                (new RSICalculator($this->requireInt($formula, 'period')))->calculate($prices),
            ),
            'ADD', 'SUB', 'MUL', 'DIV' => $this->combine($fn, $formula, $prices),
            default => throw new InvalidArgumentException("Unknown formula function: {$fn}"),
        };
    }

    /**
     * @param array<string, mixed> $formula
     * @param YahooFinanceData[] $prices
     * @return array<int, ?float>
     */
    private function combine(string $fn, array $formula, array $prices): array
    {
        $args = $formula['args'] ?? null;
        if (!is_array($args) || count($args) !== 2 || !is_array($args[0]) || !is_array($args[1])) {
            throw new InvalidArgumentException("{$fn} expects exactly 2 sub-formula args");
        }

        $a = $this->evaluateValues($args[0], $prices);
        $b = $this->evaluateValues($args[1], $prices);

        $result = [];
        foreach ($a as $i => $av) {
            $bv = $b[$i] ?? null;
            $result[] = ($av === null || $bv === null) ? null : match ($fn) {
                'ADD' => $av + $bv,
                'SUB' => $av - $bv,
                'MUL' => $av * $bv,
                'DIV' => $bv != 0.0 ? $av / $bv : null,
            };
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $formula
     */
    private function requireFloat(array $formula, string $key): float
    {
        $value = $formula[$key] ?? null;
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Formula missing numeric '{$key}'");
        }
        return (float) $value;
    }

    /**
     * @param array<string, mixed> $formula
     */
    private function requireInt(array $formula, string $key): int
    {
        $value = $formula[$key] ?? null;
        if (!is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException("Formula missing positive integer '{$key}'");
        }
        return (int) $value;
    }
}
