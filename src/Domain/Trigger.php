<?php

namespace App\Domain;

use DateTimeImmutable;

class Trigger
{
    /**
     * @param TriggerCondition[] $conditions
     */
    public function __construct(
        private int $id,
        private int $userId,
        private string $ticker,
        private string $name,
        private bool $isActive,
        private DateTimeImmutable $createdAt,
        private array $conditions,
    ) {}

    public function getId(): int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getTicker(): string { return $this->ticker; }
    public function getName(): string { return $this->name; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    /**
     * @return TriggerCondition[]
     */
    public function getConditions(): array { return $this->conditions; }
}
