<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class CreateNonconformityManagement extends BaseMigration
{
    public function change(): void
    {
        $this->table('nonconformity_manager_assignments', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer')->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('revision', 'integer', ['default' => 0])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])->create();
        $this->table('nonconformity_manager_assignments')->insert(['id' => 1, 'user_id' => null, 'revision' => 0])->saveData();
        $this->table('nonconformities')
            ->addColumn('number', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('unit', 'string', ['limit' => 150])
            ->addColumn('origin', 'string', ['limit' => 100])
            ->addColumn('occurred_on', 'date')->addColumn('description', 'text')
            ->addColumn('revision', 'integer', ['default' => 0])
            ->addColumn('created', 'datetime')->addColumn('modified', 'datetime')
            ->addIndex(['unit', 'origin', 'number'], ['unique' => true])->create();
        $this->table('nonconformity_actions')
            ->addColumn('nonconformity_id', 'integer')
            ->addColumn('item', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('measure', 'string', ['limit' => 40])->addColumn('description', 'text')
            ->addColumn('responsible_name', 'string', ['limit' => 150])
            ->addColumn('due_on', 'date', ['null' => true])->addColumn('completed_on', 'date', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 30, 'default' => 'not_started'])
            ->addColumn('effectiveness', 'text', ['null' => true])->addColumn('notes', 'text', ['null' => true])
            ->addColumn('revision', 'integer', ['default' => 0])->addColumn('deadline_cycle', 'integer', ['default' => 0])
            ->addColumn('created', 'datetime')->addColumn('modified', 'datetime')
            ->addForeignKey('nonconformity_id', 'nonconformities', 'id', ['delete' => 'RESTRICT'])
            ->addIndex(['nonconformity_id', 'item'], ['unique' => true])->addIndex(['status', 'due_on'])->create();
        $this->table('nonconformity_audit_events')
            ->addColumn('actor_id', 'integer', ['null' => true])->addColumn('actor_name', 'string', ['limit' => 150])
            ->addColumn('object_type', 'string', ['limit' => 40])->addColumn('object_id', 'integer')
            ->addColumn('operation', 'string', ['limit' => 40])
            ->addColumn('before_json', 'text')->addColumn('after_json', 'text')->addColumn('created', 'datetime')
            ->addForeignKey('actor_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['object_type', 'object_id', 'id'])->create();
        $this->table('nonconformity_notifications')
            ->addColumn('user_id', 'integer')->addColumn('action_id', 'integer')
            ->addColumn('event', 'string', ['limit' => 20])->addColumn('due_on', 'date')
            ->addColumn('deadline_cycle', 'integer')->addColumn('assignment_revision', 'integer')
            ->addColumn('created', 'datetime')->addColumn('read_at', 'datetime', ['null' => true])
            ->addColumn('invalidated_at', 'datetime', ['null' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('action_id', 'nonconformity_actions', 'id', ['delete' => 'RESTRICT'])
            ->addIndex(['user_id', 'action_id', 'event', 'due_on', 'deadline_cycle', 'assignment_revision'], ['unique' => true, 'name' => 'nc_notification_event_unique'])
            ->addIndex(['user_id', 'invalidated_at', 'read_at'])->create();
    }
}
