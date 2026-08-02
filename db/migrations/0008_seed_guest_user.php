<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedGuestUser extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')->insert([
            [
                'username' => 'guest',
                // Random and never checked — AuthController::guestLogin() issues a
                // token for this account without a password, so this hash only
                // exists to keep the normal /login form from ever authenticating
                // as it.
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'created' => date('Y-m-d H:i:s'),
                'role' => 'guest',
            ],
        ])->saveData();
    }
}
