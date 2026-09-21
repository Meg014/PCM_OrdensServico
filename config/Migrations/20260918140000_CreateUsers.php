<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class CreateUsers extends BaseMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('nome', 'string', ['limit' => 150])
            ->addColumn('email', 'string', ['limit' => 254])
            ->addColumn('password', 'string', ['limit' => 255])
            ->addColumn('role', 'string', ['limit' => 20, 'default' => 'USUARIO'])
            ->addColumn('maintenance_area_id', 'integer', ['null' => true])
            ->addColumn('ativo', 'boolean', ['default' => true])
            ->addColumn('created', 'datetime')
            ->addColumn('modified', 'datetime')
            ->addIndex(['email'], ['unique' => true])
            ->addForeignKey('maintenance_area_id', 'maintenance_areas', 'id', ['delete' => 'SET_NULL'])
            ->create();
    }
}
