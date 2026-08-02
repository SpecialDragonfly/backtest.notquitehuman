<?php

namespace App\Repository;

use PDO;

class MomentumHoldingsRepository
{
    public function __construct(private PDO $db) {}

    /**
     * @return string[]
     */
    public function getCurrentHoldings(): array
    {
        $stmt = $this->db->query('SELECT ticker FROM momentum_holdings ORDER BY ticker');
        return array_map(
            fn(array $row) => (string) $row['ticker'],
            $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [],
        );
    }

    /**
     * Replaces the entire holdings set — mirrors the CLI prototype's
     * portfolio.json, which always represents "what we hold right now",
     * not a historical log of every rebalance.
     *
     * @param string[] $tickers
     */
    public function replaceHoldings(array $tickers): void
    {
        $this->db->beginTransaction();
        $this->db->exec('DELETE FROM momentum_holdings');
        $insert = $this->db->prepare('INSERT INTO momentum_holdings (ticker, added_at) VALUES (?, ?)');
        $now = date('Y-m-d H:i:s');
        foreach ($tickers as $ticker) {
            $insert->execute([$ticker, $now]);
        }
        $this->db->commit();
    }
}
