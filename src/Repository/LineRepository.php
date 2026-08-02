<?php

namespace App\Repository;

use App\Domain\Line;
use DateTimeImmutable;
use PDO;

class LineRepository
{
    public function __construct(private PDO $db) {}

    /**
     * @return Line[]
     */
    public function allForUser(int $userId): array
    {
        // Backtick-quoted: `lines` is a reserved word in MySQL 8+ (used in
        // LOAD DATA's "LINES TERMINATED BY" clause) — unquoted, every query
        // here is a syntax error on MySQL even though SQLite accepts it
        // fine. Backticks are portable: SQLite also accepts them, as a
        // deliberate MySQL-compatibility quirk.
        $stmt = $this->db->prepare('SELECT * FROM `lines` WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id): ?Line
    {
        $stmt = $this->db->prepare('SELECT * FROM `lines` WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $formula
     */
    public function create(int $userId, string $name, array $formula): void
    {
        $this->db->prepare('INSERT INTO `lines` (user_id, name, formula, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $name, json_encode($formula, JSON_THROW_ON_ERROR), date('Y-m-d H:i:s')]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM `lines` WHERE id = ?')->execute([$id]);
    }

    /**
     * Guards deletion — a Line still referenced by a trigger_conditions row
     * would otherwise silently break that Trigger.
     */
    public function isReferencedByTrigger(int $lineId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM trigger_conditions WHERE line_a_id = ? OR line_b_id = ? LIMIT 1'
        );
        $stmt->execute([$lineId, $lineId]);
        return $stmt->fetch() !== false;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): Line
    {
        $formula = json_decode((string) $row['formula'], true, 512, JSON_THROW_ON_ERROR);

        return new Line(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['name'],
            is_array($formula) ? $formula : [],
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
