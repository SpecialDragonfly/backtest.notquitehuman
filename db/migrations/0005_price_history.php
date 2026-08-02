<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PriceHistory extends AbstractMigration
{
    // One row per (symbol, date) forever, keyed by the Yahoo symbol (e.g.
    // "VOD.L", "^FTSE") rather than the LSE-style ticker, so there's no
    // ambiguity at the storage layer. Weekly/monthly views are derived from
    // these daily rows on read (see App\Backtest\PriceAggregator), not
    // stored separately.
    public function change(): void
    {
        $table = $this->table('price_history');
        $table->addColumn('symbol', 'string', ['limit' => 16])
              ->addColumn('date', 'date')
              ->addColumn('open', 'decimal', ['precision' => 12, 'scale' => 4])
              ->addColumn('high', 'decimal', ['precision' => 12, 'scale' => 4])
              ->addColumn('low', 'decimal', ['precision' => 12, 'scale' => 4])
              ->addColumn('close', 'decimal', ['precision' => 12, 'scale' => 4])
              ->addColumn('adj_close', 'decimal', ['precision' => 12, 'scale' => 4])
              ->addColumn('volume', 'biginteger')
              ->addIndex(['symbol', 'date'], ['unique' => true, 'name' => 'idx_symbol_date'])
              ->create();
    }
}
