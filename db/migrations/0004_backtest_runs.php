<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BacktestRuns extends AbstractMigration
{
    // Single cached row for the expensive strategy-comparison grid (~66 live
    // price fetches), recomputed only on demand via the "Run comparison" button
    // rather than on every /lab page view.
    public function change(): void
    {
        $table = $this->table('backtest_runs');
        $table->addColumn('result_json', 'text')
              ->addColumn('computed_at', 'datetime')
              ->create();
    }
}
