<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;
use Cake\Http\Response;

class AuthController extends AppController
{
    /** Makes only the login action public. */
    public function beforeFilter(EventInterface $event): void
    {
        $this->Authentication->addUnauthenticatedActions(['login']);
        parent::beforeFilter($event);
    }

    /** Displays the login form and handles the authentication result. */
    public function login(): ?Response
    {
        $this->request->allowMethod(['get', 'post']);
        if ($this->Authentication->getResult()->isValid()) {
            $identity = $this->request->getAttribute('identity');
            if ($identity->get('role') === 'TV') {
                $service = new \App\Service\TvDeviceService();
                $token = $this->request->getCookie($service::COOKIE, '');
                if (!is_string($token) || $service->resolve($token)?->id !== $identity->getIdentifier()) {
                    $token = $service->issue((int)$identity->getIdentifier());
                }
                $this->response = $this->response->withCookie($service->cookie($this->request, $token));

                return $this->redirect('/pcm/apresentacao');
            }
            return $this->redirect('/pcm');
        }
        if ($this->request->is('post')) {
            $this->Flash->error('E-mail ou senha inválidos, ou usuário inativo.');
        }

        return null;
    }

    /** Ends the session through a CSRF-protected POST. */
    public function logout(): Response
    {
        $this->request->allowMethod(['post']);
        $service = new \App\Service\TvDeviceService();
        $token = $this->request->getCookie($service::COOKIE, '');
        $service->revoke(is_string($token) ? $token : '');
        $this->response = $this->response->withExpiredCookie($service->cookie($this->request, ''));
        $this->Authentication->logout();
        $this->request->getSession()->destroy();

        return $this->redirect('/login');
    }
}
