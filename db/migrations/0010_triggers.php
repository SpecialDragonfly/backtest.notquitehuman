<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Triggers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('triggers')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('ticker', 'string', ['limit' => 16])
            ->addColumn('name', 'string', ['limit' => 64])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['user_id'], ['name' => 'idx_triggers_user_id'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        // One row per condition a trigger ANDs together. v1 only ever
        // creates exactly one per trigger (a single line-crosses-line
        // condition), but keeping conditions in their own table means
        // "AND multiple conditions together" needs no schema change later.
        $this->table('trigger_conditions')
            ->addColumn('trigger_id', 'integer', ['signed' => false])
            ->addColumn('line_a_id', 'integer', ['signed' => false])
            ->addColumn('line_b_id', 'integer', ['signed' => false])
            ->addColumn('operator', 'string', ['limit' => 8]) // 'ABOVE' | 'BELOW' — line_a <op> line_b
            ->addColumn('position', 'integer', ['default' => 0])
            ->addIndex(['trigger_id'], ['name' => 'idx_trigger_conditions_trigger_id'])
            ->addForeignKey('trigger_id', 'triggers', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('line_a_id', 'lines', 'id', ['delete' => 'NO_ACTION', 'update' => 'NO_ACTION'])
            ->addForeignKey('line_b_id', 'lines', 'id', ['delete' => 'NO_ACTION', 'update' => 'NO_ACTION'])
            ->create();
    }
}
