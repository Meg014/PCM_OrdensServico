<?php
declare(strict_types=1);
namespace App\Controller;

use Cake\Http\Response;

final class ProfileController extends AppController
{
    /** No target user ID is accepted from URL, query string or request body. */
    public function index(): ?Response
    {
        $this->request->allowMethod(['get', 'post']);
        $table = $this->fetchTable('Users');
        $user = $table->get($this->request->getAttribute('identity')->getIdentifier());
        $required = (bool)$user->must_change_password;
        if ($this->request->is('post')) {
            if ($table->changeOwnPassword($user, $this->request->getData())) {
                // Refresh the hash in this session; old sessions fail the existing per-request check.
                $this->Authentication->setIdentity($user);
                $this->Flash->success('Senha alterada com sucesso.');
                return $this->redirect('/pcm');
            }
            $this->Flash->error('Não foi possível alterar a senha. Revise os campos informados.');
        }
        $this->set(compact('user', 'required'));
        return null;
    }
}
