<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Lines extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('lines');
        $table->addColumn('user_id', 'integer', ['signed' => false])
              ->addColumn('name', 'string', ['limit' => 64])
              // JSON formula tree, e.g. {"fn":"SMA","period":50} — see
              // src/Line/FormulaEvaluator.php for the supported shapes.
              ->addColumn('formula', 'text')
              ->addColumn('created_at', 'datetime')
              ->addIndex(['user_id'], ['name' => 'idx_lines_user_id'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
              ->create();
    }
}
