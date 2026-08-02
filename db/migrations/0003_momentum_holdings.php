<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MomentumHoldings extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('momentum_holdings');
        $table->addColumn('ticker', 'string', ['limit' => 16])
              ->addColumn('added_at', 'datetime')
              ->create();
    }
}
