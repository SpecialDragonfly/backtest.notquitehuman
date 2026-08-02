<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRoleToUsers extends AbstractMigration
{
    // 'main' is the one pre-existing production login and becomes the site's
    // admin; every other/future user defaults to the plain 'user' role.
    public function change(): void
    {
        $this->table('users')
            ->addColumn('role', 'string', ['limit' => 16, 'default' => 'user'])
            ->update();

        $this->execute("UPDATE users SET role = 'admin' WHERE username = 'main'");
    }
}
