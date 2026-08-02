<?php

namespace App\Repository;

use App\Backtest\YahooFinanceData;
use PDO;

/**
 * Permanent daily OHLCV storage, one row per (symbol, date). Callers ask for
 * whatever date range they need and get daily bars back; weekly/monthly
 * views are derived from these via App\Backtest\PriceAggregator, not stored
 * separately.
 */
class PriceHistoryRepository
{
    public function __construct(private PDO $db) {}

    /**
     * @return YahooFinanceData[] Chronologically sorted daily prices
     */
    public function getDailyCloses(string $symbol, string $start, string $end): array
    {
        $stmt = $this->db->prepare(
            'SELECT date, open, high, low, close, adj_close, volume
             FROM price_history
             WHERE symbol = ? AND date BETWEEN ? AND ?
             ORDER BY date'
        );
        $stmt->execute([$symbol, $start, $end]);

        return array_map(
            fn(array $row) => new YahooFinanceData(
                timestamp: (int) strtotime((string) $row['date']),
                open: (float) $row['open'],
                high: (float) $row['high'],
                low: (float) $row['low'],
                close: (float) $row['close'],
                adjClose: (float) $row['adj_close'],
                volume: (float) $row['volume'],
            ),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * How much history is stored per symbol — first/last date and row count.
     * One grouped query for every symbol at once, keyed by symbol, for the
     * admin ticker list rather than one query per ticker.
     *
     * @return array<string, array{firstDate: string, lastDate: string, days: int}>
     */
    public function getCoverageSummary(): array
    {
        $stmt = $this->db->query(
            'SELECT symbol, MIN(date) AS first_date, MAX(date) AS last_date, COUNT(*) AS days
             FROM price_history
             GROUP BY symbol'
        );

        $summary = [];
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $summary[(string) $row['symbol']] = [
                'firstDate' => (string) $row['first_date'],
                'lastDate' => (string) $row['last_date'],
                'days' => (int) $row['days'],
            ];
        }
        return $summary;
    }

    /**
     * The most recent date already stored for a symbol, so a sync only needs
     * to fetch from here onward. Null if nothing has been synced yet.
     */
    public function latestDateFor(string $symbol): ?string
    {
        $stmt = $this->db->prepare('SELECT MAX(date) AS latest FROM price_history WHERE symbol = ?');
        $stmt->execute([$symbol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row['latest'] !== null ? (string) $row['latest'] : null;
    }

    /**
     * Deletes any existing rows for the given (symbol, date) pairs and
     * re-inserts them — a portable delete-then-insert upsert that avoids
     * SQLite's INSERT OR REPLACE / MySQL's ON DUPLICATE KEY UPDATE, since
     * both engines are live targets for this table.
     *
     * @param YahooFinanceData[] $prices
     */
    public function upsertDaily(string $symbol, array $prices): void
    {
        if (empty($prices)) {
            return;
        }

        $dates = array_map(fn(YahooFinanceData $p) => $p->getDate(), $prices);

        $this->db->beginTransaction();

        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $delete = $this->db->prepare(
            "DELETE FROM price_history WHERE symbol = ? AND date IN ({$placeholders})"
        );
        $delete->execute([$symbol, ...$dates]);

        $insert = $this->db->prepare(
            'INSERT INTO price_history (symbol, date, open, high, low, close, adj_close, volume)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($prices as $p) {
            $insert->execute([$symbol, $p->getDate(), $p->open, $p->high, $p->low, $p->close, $p->adjClose, $p->volume]);
        }

        $this->db->commit();
    }
}
