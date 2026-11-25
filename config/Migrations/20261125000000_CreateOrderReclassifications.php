<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class CreateOrderReclassifications extends BaseMigration
{
    public function change(): void
    {
        $this->table('order_reclassifications')
            ->addColumn('branch', 'string', ['limit' => 20])
            ->addColumn('number', 'string', ['limit' => 50])
            ->addColumn('original_area', 'string', ['limit' => 20])
            ->addColumn('destination', 'string', ['limit' => 20])
            ->addIndex(['branch', 'number'], ['unique' => true])
            ->create();

        $this->table('order_reclassification_events')
            ->addColumn('branch', 'string', ['limit' => 20])
            ->addColumn('number', 'string', ['limit' => 50])
            ->addColumn('operation', 'string', ['limit' => 20])
            ->addColumn('previous_area', 'string', ['limit' => 20])
            ->addColumn('destination', 'string', ['limit' => 20])
            ->addColumn('reason', 'text')
            ->addColumn('user_id', 'integer')
            ->addColumn('created', 'datetime')
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
            ->addIndex(['branch', 'number', 'id'])->create();
    }
}
