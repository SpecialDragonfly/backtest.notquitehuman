<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Alerts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('alerts')
            ->addColumn('trigger_id', 'integer', ['signed' => false])
            ->addColumn('fired_on', 'date')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            // Guards against the daily cron double-inserting the same
            // trigger's alert if it's ever re-run for the same day.
            ->addIndex(['trigger_id', 'fired_on'], ['unique' => true, 'name' => 'idx_alerts_trigger_fired_on'])
            ->addForeignKey('trigger_id', 'triggers', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
