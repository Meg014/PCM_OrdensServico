<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class UsersTable extends Table
{
    /** Configures user timestamps and optional sector association. */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setDisplayField('nome');
        $this->addBehavior('Timestamp');
        $this->belongsTo('MaintenanceAreas');
    }

    /** Excludes inactive accounts during authentication. */
    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where([$this->aliasField('ativo') => true]);
    }

    /** Validates profiles and plaintext passwords before hashing. */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->scalar('nome')->maxLength('nome', 150)->requirePresence('nome', 'create')->notEmptyString('nome')
            ->email('email')->maxLength('email', 254)->requirePresence('email', 'create')->notEmptyString('email')
            ->scalar('password')->minLength('password', 12, 'Use pelo menos 12 caracteres.')
            ->maxLength('password', 72, 'Use no máximo 72 caracteres.')
            ->add('password', 'bytes', ['rule' => static fn($value) => is_string($value) && strlen($value) <= 72,
                'message' => 'A senha deve ter no máximo 72 bytes.'])
            ->requirePresence('password', 'create')->notEmptyString('password')
            ->inList('role', ['ADMIN', 'USUARIO', 'TV'])->requirePresence('role', 'create')
            ->boolean('ativo')->requirePresence('ativo', 'create')
            ->integer('maintenance_area_id')->allowEmptyString('maintenance_area_id');
    }

    /** Enforces unique email and valid sector references. */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(static fn ($entity) => $entity->role !== 'USUARIO' || !empty($entity->maintenance_area_id),
            'userRequiresArea', ['errorField' => 'area_code', 'message' => 'Usuário comum precisa de setor.']);
        $rules->add($rules->isUnique(['email']), ['errorField' => 'email', 'message' => 'E-mail já cadastrado.']);
        $rules->add(
            $rules->existsIn(['maintenance_area_id'], 'MaintenanceAreas'),
            ['errorField' => 'maintenance_area_id'],
        );

        return $rules;
    }

    /** Profile changes invalidate all remembered devices, even after reactivation. */
    public function afterSave(\Cake\Event\EventInterface $event, \Cake\Datasource\EntityInterface $entity): void
    {
        if (!$entity->isNew() && ($entity->isDirty('ativo') || $entity->isDirty('role') || $entity->isDirty('password'))) {
            \Cake\Datasource\FactoryLocator::get('Table')->get('TvDevices')->deleteAll(['user_id' => $entity->id]);
        }
    }
}
