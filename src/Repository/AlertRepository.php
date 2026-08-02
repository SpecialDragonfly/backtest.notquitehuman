<?php

namespace App\Repository;

use App\Domain\Alert;
use DateTimeImmutable;
use PDO;

class AlertRepository
{
    public function __construct(private PDO $db) {}

    /**
     * @return Alert[] Newest first
     */
    public function allForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.* FROM alerts a
             INNER JOIN triggers t ON t.id = a.trigger_id
             WHERE t.user_id = ?
             ORDER BY a.fired_on DESC, a.id DESC'
        );
        $stmt->execute([$userId]);
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function existsFor(int $triggerId, string $firedOn): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM alerts WHERE trigger_id = ? AND fired_on = ? LIMIT 1');
        $stmt->execute([$triggerId, $firedOn]);
        return $stmt->fetch() !== false;
    }

    public function create(int $triggerId, string $firedOn): void
    {
        $this->db->prepare('INSERT INTO alerts (trigger_id, fired_on, created_at) VALUES (?, ?, ?)')
            ->execute([$triggerId, $firedOn, date('Y-m-d H:i:s')]);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): Alert
    {
        return new Alert(
            (int) $row['id'],
            (int) $row['trigger_id'],
            (string) $row['fired_on'],
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
