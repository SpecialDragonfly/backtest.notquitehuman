<?php

namespace App\Repository;

use App\Domain\Trigger;
use App\Domain\TriggerCondition;
use DateTimeImmutable;
use PDO;

class TriggerRepository
{
    public function __construct(private PDO $db) {}

    /**
     * @return Trigger[]
     */
    public function allForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM triggers WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return Trigger[] Every active trigger for a ticker, regardless of owner —
     *     used by the live cron path, which evaluates on behalf of all users.
     */
    public function allActiveForTicker(string $ticker): array
    {
        $stmt = $this->db->prepare('SELECT * FROM triggers WHERE ticker = ? AND is_active = 1 ORDER BY id');
        $stmt->execute([$ticker]);
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return string[] Distinct LSE ticker codes with at least one active
     *     trigger — the live cron path's starting point, so it only ever
     *     touches tickers someone actually has a trigger on.
     */
    public function distinctActiveTickers(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT ticker FROM triggers WHERE is_active = 1');
        return array_map(
            fn(array $row) => (string) $row['ticker'],
            $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [],
        );
    }

    public function find(int $id): ?Trigger
    {
        $stmt = $this->db->prepare('SELECT * FROM triggers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Inserts a trigger and its single v1 condition together.
     */
    public function create(int $userId, string $ticker, string $name, int $lineAId, string $operator, int $lineBId): void
    {
        $this->db->beginTransaction();

        $this->db->prepare(
            'INSERT INTO triggers (user_id, ticker, name, is_active, created_at) VALUES (?, ?, ?, 1, ?)'
        )->execute([$userId, $ticker, $name, date('Y-m-d H:i:s')]);
        $triggerId = (int) $this->db->lastInsertId();

        $this->db->prepare(
            'INSERT INTO trigger_conditions (trigger_id, line_a_id, line_b_id, operator, position) VALUES (?, ?, ?, ?, 0)'
        )->execute([$triggerId, $lineAId, $lineBId, $operator]);

        $this->db->commit();
    }

    public function setActive(int $id, bool $isActive): void
    {
        $this->db->prepare('UPDATE triggers SET is_active = ? WHERE id = ?')
            ->execute([$isActive ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM triggers WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): Trigger
    {
        $triggerId = (int) $row['id'];

        $stmt = $this->db->prepare('SELECT * FROM trigger_conditions WHERE trigger_id = ? ORDER BY position, id');
        $stmt->execute([$triggerId]);
        $conditions = array_map(
            fn(array $c) => new TriggerCondition(
                (int) $c['id'],
                (int) $c['line_a_id'],
                (int) $c['line_b_id'],
                (string) $c['operator'],
            ),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );

        return new Trigger(
            $triggerId,
            (int) $row['user_id'],
            (string) $row['ticker'],
            (string) $row['name'],
            (bool) $row['is_active'],
            new DateTimeImmutable((string) $row['created_at']),
            $conditions,
        );
    }
}
