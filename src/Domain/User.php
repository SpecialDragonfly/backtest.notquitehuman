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
        private string $role = 'user',
    ) {}

    public function getId(): int { return $this->id; }
    public function getUsername(): string { return $this->username; }
    public function getPasswordHash(): string { return $this->passwordHash; }
    public function getCreatedAt(): ?DateTimeImmutable { return $this->createdAt; }
    public function getRole(): string { return $this->role; }
    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isGuest(): bool { return $this->role === 'guest'; }
}
