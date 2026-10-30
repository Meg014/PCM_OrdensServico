<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\NonconformityService;
use Cake\Http\Response;

final class NonconformityNotificationsController extends AppController
{
    public function index(): Response
    {
        $this->request->allowMethod(['get']);
        $notifications = (new NonconformityService())->notifications((int)$this->request->getAttribute('identity')->getIdentifier());
        $this->set(compact('notifications')); $this->viewBuilder()->disableAutoLayout();
        return $this->render('/element/nonconformity_bell');
    }
    public function open(int $id): Response
    {
        $this->request->allowMethod(['post']);
        $target = (new NonconformityService())->openNotification((int)$this->request->getAttribute('identity')->getIdentifier(), $id);
        return $this->response->withStatus(303)->withHeader('Location', \Cake\Routing\Router::url('/pcm/nao-conformidades/' . $target['nc'] . '#acao-' . $target['action']));
    }
}
