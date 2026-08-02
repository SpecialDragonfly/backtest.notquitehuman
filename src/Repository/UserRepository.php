<?php

namespace App\Repository;

use App\Domain\User;
use DateTimeImmutable;
use PDO;
use UnexpectedValueException;

class UserRepository
{
    public function __construct(private PDO $db) {}

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(string $username, string $hashedPassword): void
    {
        $this->db->prepare('INSERT INTO users (username, password, created) VALUES (?, ?, ?)')
            ->execute([$username, $hashedPassword, date('Y-m-d H:i:s')]);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): User
    {
        $created = $row['created'];

        return new User(
            $this->toInt($row['id']),
            $this->toStringValue($row['username']),
            $this->toStringValue($row['password']),
            $created !== null ? new DateTimeImmutable($this->toStringValue($created)) : null,
        );
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Expected an int-like value from the users table.');
    }

    private function toStringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new UnexpectedValueException('Expected a string-like value from the users table.');
    }
}
