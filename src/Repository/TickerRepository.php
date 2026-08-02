<?php

namespace App\Repository;

use PDO;

/**
 * The tradable ticker universe (blue chips + index trackers), admin-editable
 * via /admin/tickers. Replaces the old hardcoded list in
 * App\Backtest\TickerUniverse — everything that used to read that static
 * array (price syncing, the strategy comparison grid, momentum rotation, the
 * dashboard's ticker picker) now reads this table instead.
 */
class TickerRepository
{
    public function __construct(private PDO $db) {}

    /**
     * @return string[] LSE-style ticker codes, oldest-added first
     */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT ticker FROM tickers ORDER BY id');
        return array_map(
            fn(array $row) => (string) $row['ticker'],
            $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [],
        );
    }

    public function exists(string $ticker): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM tickers WHERE ticker = ? LIMIT 1');
        $stmt->execute([$ticker]);
        return $stmt->fetch() !== false;
    }

    public function add(string $ticker): void
    {
        $this->db->prepare('INSERT INTO tickers (ticker, added_at) VALUES (?, ?)')
            ->execute([$ticker, date('Y-m-d H:i:s')]);
    }
}
