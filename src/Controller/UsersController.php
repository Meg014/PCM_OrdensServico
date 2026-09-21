<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Response;

class UsersController extends AppController
{
    /** Revokes every persistent TV device; existing TV sessions require a valid device too. */
    public function revokeTv(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $this->fetchTable('Users')->get($id);
        $this->fetchTable('TvDevices')->deleteAll(['user_id' => $id]);
        $this->Flash->success('Dispositivos da TV revogados. Faça login novamente na TV.');

        return $this->redirect('/usuarios');
    }

    /** Lists users for administrators. */
    public function index(): void
    {
        $users = $this->paginate($this->fetchTable('Users')->find()->contain(['MaintenanceAreas']), [
            'limit' => 25, 'order' => ['nome' => 'ASC'], 'sortableFields' => ['nome', 'email', 'role', 'ativo'],
        ]);
        $this->set(compact('users'));
    }

    /** Creates a user with a required initial password. */
    public function add(): ?Response
    {
        return $this->saveUser();
    }

    /** Updates user profile and activation status. */
    public function edit(int $id): ?Response
    {
        return $this->saveUser($id);
    }

    /** Saves only explicitly permitted profile fields. */
    private function saveUser(?int $id = null): ?Response
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);
        $table = $this->fetchTable('Users');
        $user = $id === null ? $table->newEmptyEntity() : $table->get($id);
        if ($this->request->is(['post', 'put', 'patch'])) {
            $fields = ['nome', 'email', 'role', 'maintenance_area_id', 'ativo'];
            if ($id === null) {
                $fields[] = 'password';
            }
            $data = array_intersect_key($this->request->getData(), array_flip($fields));
            if (isset($data['email']) && is_string($data['email'])) {
                $data['email'] = mb_strtolower(trim($data['email']));
            }
            $table->patchEntity($user, $data, ['fields' => $fields]);
            // Prevent administrators from accidentally locking themselves out.
            if (
                $id === (int)$this->request->getAttribute('identity')->getIdentifier()
                && (!$user->ativo || $user->role !== 'ADMIN')
            ) {
                $user->setError('role', 'Você não pode desativar ou remover seu próprio perfil ADMIN.');
            }
            if (!$user->hasErrors() && $table->save($user)) {
                $this->Flash->success('Usuário salvo.');

                return $this->redirect('/usuarios');
            }
            $this->Flash->error('Revise os campos informados.');
        }
        $areas = $this->fetchTable('MaintenanceAreas')->find()->all()->combine('id', 'display_name')->toArray();
        $this->set(compact('user', 'areas'));
        $this->viewBuilder()->setTemplate('form');

        return null;
    }

    /** Resets a password without accepting profile changes. */
    public function password(int $id): ?Response
    {
        $this->request->allowMethod(['get', 'post']);
        $table = $this->fetchTable('Users');
        $user = $table->get($id);
        if ($this->request->is('post')) {
            $table->patchEntity($user, ['password' => $this->request->getData('password')], ['fields' => ['password']]);
            if ($table->save($user)) {
                $this->Flash->success('Senha redefinida. As sessões anteriores serão encerradas.');

                return $this->redirect('/usuarios');
            }
            $this->Flash->error('Revise a nova senha.');
        }
        $this->set(compact('user'));

        return null;
    }
}
