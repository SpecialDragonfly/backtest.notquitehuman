<?php

namespace App\Domain;

use DateTimeImmutable;

class User
{
    public function __construct(
        private int $id,
        private string $username,
        private string $passwordHash,
        private ?DateTimeImmutable $createdAt,
    ) {}

    public function getId(): int { return $this->id; }
    public function getUsername(): string { return $this->username; }
    public function getPasswordHash(): string { return $this->passwordHash; }
    public function getCreatedAt(): ?DateTimeImmutable { return $this->createdAt; }
}
