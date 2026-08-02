<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Users extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('users');
        $table->addColumn('username', 'string')
              ->addColumn('password', 'string')
              ->addColumn('created', 'datetime')
              ->create();
    }
}
