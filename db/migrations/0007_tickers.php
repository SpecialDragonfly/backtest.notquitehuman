<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Tickers extends AbstractMigration
{
    // Replaces the hardcoded list in App\Backtest\TickerUniverse with a
    // table admins can add to. Seeded here with a literal snapshot of that
    // list (not a call into app code) so this migration stays replayable
    // exactly as written even if TickerUniverse changes shape later.
    public function change(): void
    {
        $table = $this->table('tickers');
        $table->addColumn('ticker', 'string', ['limit' => 16])
              ->addColumn('added_at', 'datetime')
              ->addIndex(['ticker'], ['unique' => true])
              ->create();

        $seedTickers = [
            'VOD', 'BP.', 'HSBA', 'GSK', 'ULVR', 'AZN', 'RIO', 'BARC', 'LLOY', 'SHEL',
            'STAN', 'NWG', 'PRU', 'LGEN', 'TSCO', 'NXT', 'DGE', 'BATS', 'RKT', 'RR.',
            'BA.', 'NG.', 'SSE', 'UU.', 'HLN', 'BT.A', 'WPP', 'LAND', 'GLEN',
            'VUSA', 'VWRL', 'VUKE', 'VMID',
        ];

        $now = date('Y-m-d H:i:s');
        $rows = array_map(fn(string $ticker) => ['ticker' => $ticker, 'added_at' => $now], $seedTickers);
        $table->insert($rows)->saveData();
    }
}
