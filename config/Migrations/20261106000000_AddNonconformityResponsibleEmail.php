<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class AddNonconformityResponsibleEmail extends BaseMigration
{
    public function change(): void
    {
        $this->table('nonconformity_responsibles')
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('normalized_name', 'string', ['limit' => 150])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('sector', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('revision', 'integer', ['default' => 0])
            ->addColumn('created', 'datetime')->addColumn('modified', 'datetime')
            ->addIndex('normalized_name', ['unique' => true])->create();
        // No automatic matching: the original imported name remains untouched.
        $this->table('nonconformity_actions')
            ->addColumn('responsible_id', 'integer', ['null' => true])
            ->addColumn('assignment_cycle', 'integer', ['default' => 0])
            ->addForeignKey('responsible_id', 'nonconformity_responsibles', 'id', ['delete' => 'RESTRICT'])->update();
        $this->table('nonconformity_email_queue')
            ->addColumn('event_key', 'string', ['limit' => 64])
            ->addColumn('recipient', 'string', ['limit' => 254])
            ->addColumn('event', 'string', ['limit' => 30])
            ->addColumn('nonconformity_id', 'integer')->addColumn('action_id', 'integer')
            ->addColumn('responsible_id', 'integer')->addColumn('assignment_cycle', 'integer')
            ->addColumn('deadline_cycle', 'integer')->addColumn('due_on', 'date', ['null' => true])
            ->addColumn('scheduled_at', 'datetime')->addColumn('available_at', 'datetime')
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addColumn('claimed_at', 'datetime', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('error', 'text', ['null' => true])
            ->addForeignKey('action_id', 'nonconformity_actions', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('nonconformity_id', 'nonconformities', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('responsible_id', 'nonconformity_responsibles', 'id', ['delete' => 'RESTRICT'])
            ->addIndex('event_key', ['unique' => true])
            ->addIndex(['status', 'available_at'])->create();
    }
}
