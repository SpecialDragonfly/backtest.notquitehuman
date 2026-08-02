<?php

namespace App\Domain;

use DateTimeImmutable;

/**
 * A persisted record that a Trigger's condition(s) transitioned from false
 * to true on a given day. Only ever created by the live daily-sync path
 * (App\Service\TriggerAlertService) — the historical "would have fired" view
 * is computed on demand and never written here.
 */
class Alert
{
    public function __construct(
        private int $id,
        private int $triggerId,
        private string $firedOn,
        private DateTimeImmutable $createdAt,
    ) {}

    public function getId(): int { return $this->id; }
    public function getTriggerId(): int { return $this->triggerId; }
    public function getFiredOn(): string { return $this->firedOn; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
