<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;

/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/5/en/controllers.html#the-app-controller
 */
class AppController extends Controller
{
    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like loading components.
     *
     * e.g. `$this->loadComponent('FormProtection');`
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');

        /*
         * Enable the following component for recommended CakePHP form protection settings.
         * see https://book.cakephp.org/5/en/controllers/components/form-protection.html
         */
        //$this->loadComponent('FormProtection');
    }

    /** Revalidates sessions and enforces administrative permissions on the server. */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $controller = $this->request->getParam('controller');
        $action = $this->request->getParam('action');
        if ($controller === 'ReportImports' || ($controller === 'Pcm' && !in_array($action, [
            'index', 'orders', 'presentation', 'presentationData', 'dashboardData',
            'sector', 'sectorData', 'sectorOptions', 'protheusOrder', 'protheusOrderData',
        ], true))) {
            throw new \Cake\Http\Exception\NotFoundException('Recurso indisponível.');
        }
        $this->response = $this->response->withHeader('Cache-Control', 'no-store');
        $identity = $this->request->getAttribute('identity');
        $currentUser = null;
        if ($identity !== null) {
            $currentUser = $this->fetchTable('Users')->find('active')
                ->contain(['MaintenanceAreas'])->where(['Users.id' => $identity->getIdentifier()])->first();
            // Recheck status, role and password on every request, including existing sessions.
            if ($currentUser === null || !hash_equals($currentUser->password, (string)$identity->get('password'))) {
                $this->Authentication->logout();
                $event->setResult($this->redirect('/login'));

                return;
            }
        }
        $this->set(compact('currentUser'));
        if ($currentUser !== null && $currentUser->role === 'USUARIO' && $controller === 'Pcm') {
            $area = $currentUser->maintenance_area?->source_code;
            if (!is_string($area) || !preg_match('/^[A-Z0-9_-]{1,30}$/D', $area)) {
                throw new ForbiddenException('Solicite ao administrador a configuração do seu setor.');
            }
            if (in_array($action, ['presentation', 'presentationData', 'dashboardData'], true)) {
                throw new ForbiddenException('Acesso restrito ao seu setor.');
            }
            if (in_array($action, ['sector', 'sectorData'], true)
                && strtoupper((string)($this->request->getParam('pass')[0] ?? '')) !== $area) {
                throw new ForbiddenException('Acesso restrito ao seu setor.');
            }
            $this->request = $this->request->withAttribute('pcmAreaScope', $area);
        }
        if ($currentUser !== null && !in_array($currentUser->role, ['ADMIN', 'USUARIO', 'TV'], true)) {
            throw new ForbiddenException('Perfil sem acesso.');
        }
        if ($currentUser !== null && $currentUser->role === 'TV') {
            $path = $this->request->getUri()->getPath();
            if ($identity->get('role') !== 'TV') {
                $this->Authentication->logout();
                $event->setResult($this->redirect('/login'));

                return;
            }
            $allowed = ($this->request->is('get') && in_array($path, [
                '/pcm/apresentacao', '/pcm/apresentacao/data', '/login',
            ], true)) || ($path === '/logout' && $this->request->is('post'))
                || ($path === '/login' && $this->request->is('post'));
            if (!$allowed) {
                throw new ForbiddenException('Perfil TV: acesso exclusivo à apresentação.');
            }
        }
        if (
            $currentUser !== null && (
            $this->request->getParam('controller') === 'Users'
            || ($this->request->getParam('controller') === 'ReportImports' && $this->request->getParam('action') === 'manual')
            ) && $currentUser->role !== 'ADMIN'
        ) {
            throw new ForbiddenException('Acesso exclusivo para administradores.');
        }
    }
}
