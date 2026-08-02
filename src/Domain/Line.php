<?php

namespace App\Domain;

use DateTimeImmutable;

/**
 * A user-owned formula (or constant) evaluable against any ticker's price
 * history — see App\Line\FormulaEvaluator for the supported shapes.
 */
class Line
{
    /**
     * @param array<string, mixed> $formula
     */
    public function __construct(
        private int $id,
        private int $userId,
        private string $name,
        private array $formula,
        private DateTimeImmutable $createdAt,
    ) {}

    public function getId(): int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getName(): string { return $this->name; }

    /**
     * @return array<string, mixed>
     */
    public function getFormula(): array { return $this->formula; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
