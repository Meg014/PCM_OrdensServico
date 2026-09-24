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

    public function beforeMarshal(\Cake\Event\EventInterface $event, \ArrayObject $data, \ArrayObject $options): void
    {
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = \App\Service\UserEmailAudit::normalize($data['email']);
        }
    }

    /** Existing accounts retain their migration default; newly provisioned passwords are temporary. */
    public function beforeSave(\Cake\Event\EventInterface $event, \Cake\Datasource\EntityInterface $entity): void
    {
        if ($entity->role === 'TV') {
            $entity->set('must_change_password', false);
        } elseif ($entity->isNew()) {
            $entity->set('must_change_password', true);
        }
    }

    /** Only the supplied, freshly authenticated entity can be changed; ignore all profile input. */
    public function changeOwnPassword(\App\Model\Entity\User $user, array $input): bool
    {
        if (!in_array($user->role, ['ADMIN', 'USUARIO'], true)) return false;
        $hasher = new \Authentication\PasswordHasher\DefaultPasswordHasher();
        if (!$user->must_change_password) {
            $current = $input['current_password'] ?? null;
            if (!is_string($current) || !$hasher->check($current, $user->password)) {
                $user->setError('current_password', 'Senha atual incorreta.');
            }
        }
        $new = $input['new_password'] ?? null;
        $confirmation = $input['password_confirm'] ?? null;
        if (!is_string($new) || !is_string($confirmation) || $new !== $confirmation) {
            $user->setError('password_confirm', 'A confirmação deve ser igual à nova senha.');
        }
        if ($user->hasErrors()) return false;
        if ($hasher->check($new, $user->password)) {
            $user->setError('new_password', 'Escolha uma senha diferente da atual.');
            return false;
        }
        $this->patchEntity($user, ['password' => $new], ['fields' => ['password']]);
        if ($user->hasErrors()) {
            $user->setError('new_password', $user->getError('password'));
            return false;
        }
        $user->set('must_change_password', false);
        return (bool)$this->save($user);
    }

    public function setTemporaryPassword(\App\Model\Entity\User $user, mixed $password): bool
    {
        $this->patchEntity($user, ['password' => $password], ['fields' => ['password']]);
        $user->set('must_change_password', $user->role !== 'TV');
        return (bool)$this->save($user);
    }

    /** Validates profiles and plaintext passwords before hashing. */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->scalar('nome')->maxLength('nome', 150)->requirePresence('nome', 'create')->notEmptyString('nome')
            ->email('email')->maxLength('email', 254)->requirePresence('email', 'create')->notEmptyString('email')
            ->scalar('password')->minLength('password', 8, 'Use pelo menos 8 caracteres.')
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
