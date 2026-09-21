<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class CreateTvDevices extends BaseMigration
{
    public function change(): void
    {
        $this->table('tv_devices')
            ->addColumn('user_id', 'integer')
            ->addColumn('token_hash', 'string', ['limit' => 64])
            ->addColumn('expires_at', 'datetime')
            ->addIndex(['token_hash'], ['unique' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
