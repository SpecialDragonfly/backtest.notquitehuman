<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AuthTokens extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('auth_tokens');
        $table->addColumn('user_id', 'integer', ['signed' => false])
              ->addColumn('token', 'string', ['limit' => 64])
              ->addColumn('expires_at', 'datetime')
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['token'], ['unique' => true, 'name' => 'idx_token'])
              ->addIndex(['expires_at'], ['name' => 'idx_expires'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
              ->create();
    }
}
