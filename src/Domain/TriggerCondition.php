<?php

namespace App\Domain;

/**
 * One line-vs-line comparison within a Trigger. A Trigger's overall state on
 * a given day is the AND across all its conditions (see
 * App\Line\TriggerEvaluationService) — v1 only ever creates one condition per
 * trigger, but this lives in its own table/domain class so ANDing several
 * together later needs no schema change.
 */
class TriggerCondition
{
    public const OPERATOR_ABOVE = 'ABOVE';
    public const OPERATOR_BELOW = 'BELOW';

    public function __construct(
        private int $id,
        private int $lineAId,
        private int $lineBId,
        private string $operator,
    ) {}

    public function getId(): int { return $this->id; }
    public function getLineAId(): int { return $this->lineAId; }
    public function getLineBId(): int { return $this->lineBId; }
    public function getOperator(): string { return $this->operator; }
}
