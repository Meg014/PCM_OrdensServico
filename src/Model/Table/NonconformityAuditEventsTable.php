<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/** Persistence for the NC module; business writes go through NonconformityService. */
final class NonconformityAuditEventsTable extends Table
{
    public function beforeSave(\Cake\Event\EventInterface $event, \Cake\Datasource\EntityInterface $entity): void
    {
        if (!$entity->isNew()) throw new \LogicException('Histórico de auditoria não pode ser alterado.');
    }
    public function beforeDelete(\Cake\Event\EventInterface $event): void
    {
        throw new \LogicException('Histórico de auditoria não pode ser excluído.');
    }
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('nonconformity_audit_events');
        $this->setPrimaryKey('id');
    }
}
