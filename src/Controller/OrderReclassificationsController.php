<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\OrderReclassificationService;
use Cake\Http\Response;

final class OrderReclassificationsController extends AppController
{
    public function index(): ?Response
    {
        $this->request->allowMethod(['get', 'post']);
        $service = new OrderReclassificationService();
        $session = $this->request->getSession();
        $actor = (int)$this->request->getAttribute('identity')->getIdentifier();
        $key = 'OrderReclassification.preview.' . $actor;
        $input = $this->request->getData();
        $preview = null;
        $active = $history = [];
        $activePage = max(1, min(1000000, (int)$this->request->getQuery('active_page', 1)));
        $historyPage = max(1, min(1000000, (int)$this->request->getQuery('history_page', 1)));
        try {
            if ($this->request->is('post')) {
                switch ($input['operation'] ?? '') {
                    case 'preview':
                        $branch = trim((string)($input['branch'] ?? ''));
                        $destination = (string)($input['destination'] ?? '');
                        $reason = trim((string)($input['reason'] ?? ''));
                        $rows = $service->preview($branch, (string)($input['numbers'] ?? ''), $destination, $reason);
                        $token = bin2hex(random_bytes(24));
                        $preview = compact('branch', 'destination', 'reason', 'rows', 'token');
                        $session->write($key, $preview);
                        break;
                    case 'confirm':
                        $saved = $session->read($key);
                        if (!$saved || !hash_equals($saved['token'], (string)($input['token'] ?? ''))) {
                            throw new \DomainException('Prévia expirada. Valide o lote novamente.');
                        }
                        $service->confirm($saved, (array)($input['selected'] ?? []), $actor);
                        $session->delete($key);
                        $this->Flash->success('Reclassificação concluída.');
                        return $this->redirect(['action' => 'index'], 303);
                    case 'reverse':
                        $service->reverse((array)($input['ids'] ?? []), (string)($input['reason'] ?? ''), $actor);
                        $this->Flash->success('Reversão concluída.');
                        return $this->redirect(['action' => 'index'], 303);
                    default:
                        throw new \DomainException('Operação inválida.');
                }
            }
        } catch (\DomainException $e) {
            $this->Flash->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->log($e->getMessage(), 'error');
            $this->Flash->error('Lote não concluído. Atualize a prévia e tente novamente.');
        }
        $enabled = $service->enabled();
        $situations = [];
        if ($enabled) {
            $active = $service->page(false, $activePage);
            $history = $service->page(true, $historyPage);
            try {
                $situations = $service->currentSituations(array_merge($active, $history));
            } catch (\Throwable $e) {
                $this->log($e->getMessage(), 'error');
                $this->Flash->warning('Situação atual das O.S. indisponível no TOTVS.');
            }
        }
        $areas = $service::areas();
        $this->set(compact('areas', 'active', 'history', 'activePage', 'historyPage', 'preview', 'enabled', 'situations'));
        return null;
    }
}
