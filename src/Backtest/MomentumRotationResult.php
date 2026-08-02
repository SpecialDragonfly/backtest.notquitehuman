<?php
namespace App\Backtest;

class MomentumRotationResult
{
    public function __construct(
        public readonly float $finalReturnPercent,
        public readonly float $maxDrawdownPercent,
        public readonly int $rebalanceCount,
        /** @var array{month: string, equity: float}[] */
        public readonly array $equityCurve,
        /** @var array{month: string, holdings: string[], regime: string}[] */
        public readonly array $rebalanceLog
    ) {}
}
