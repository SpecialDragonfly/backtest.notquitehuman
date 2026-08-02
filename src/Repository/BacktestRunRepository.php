<?php

namespace App\Repository;

use PDO;

class BacktestRunRepository
{
    public function __construct(private PDO $db) {}

    /**
     * @return array{result: array<array-key, mixed>, computedAt: string}|null
     */
    public function getLatestComparison(): ?array
    {
        $stmt = $this->db->query('SELECT result_json, computed_at FROM backtest_runs ORDER BY id DESC LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($row)) {
            return null;
        }

        $decoded = json_decode((string) $row['result_json'], true);
        return [
            'result' => is_array($decoded) ? $decoded : [],
            'computedAt' => (string) $row['computed_at'],
        ];
    }

    /**
     * Keeps only the latest run — this is a cache, not an audit log.
     *
     * @param array<array-key, mixed> $result
     */
    public function saveComparison(array $result): void
    {
        $this->db->beginTransaction();
        $this->db->exec('DELETE FROM backtest_runs');
        $this->db->prepare('INSERT INTO backtest_runs (result_json, computed_at) VALUES (?, ?)')
            ->execute([json_encode($result), date('Y-m-d H:i:s')]);
        $this->db->commit();
    }
}
