<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\NonconformityService;
use Cake\Event\EventInterface;
use Cake\Http\Response;

final class NonconformitiesController extends AppController
{
    private NonconformityService $nc;
    private int $actorId;
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        if ($event->isStopped() || $event->getResult() instanceof Response) return;
        $identity = $this->request->getAttribute('identity');
        if ($identity === null) return;
        $this->nc = new NonconformityService(); $this->actorId = (int)$identity->getIdentifier();
        $this->nc->actor($this->actorId);
        $this->set('canManage', $this->nc->canWrite($this->actorId));
    }
    public function index(): void
    {
        $this->request->allowMethod(['get']);
        $filters = [];
        foreach (['q', 'status', 'responsible', 'origin', 'deadline'] as $key) {
            $v = $this->request->getQuery($key, '');
            if (!is_string($v) || mb_strlen($v) > 150) throw new \Cake\Http\Exception\BadRequestException('Filtro inválido.');
            $filters[$key] = trim($v);
        }
        if ($filters['status'] !== '' && !isset(NonconformityService::STATUSES[$filters['status']])) throw new \Cake\Http\Exception\BadRequestException('Status inválido.');
        if ($filters['origin'] !== '' && !in_array($filters['origin'], NonconformityService::ORIGINS, true)) throw new \Cake\Http\Exception\BadRequestException('Origem inválida.');
        if (!in_array($filters['deadline'], ['', 'overdue', 'today', 'soon', 'none'], true)) throw new \Cake\Http\Exception\BadRequestException('Prazo inválido.');
        $query = $this->nc->table('Nonconformities')->find()->leftJoin(['A' => 'nonconformity_actions'], ['A.nonconformity_id = Nonconformities.id'])
            ->select(['Nonconformities.id', 'Nonconformities.number', 'Nonconformities.unit', 'Nonconformities.origin', 'Nonconformities.occurred_on', 'Nonconformities.description',
                'action_id' => 'A.id', 'item' => 'A.item', 'measure' => 'A.measure', 'action_description' => 'A.description',
                'responsible_name' => 'A.responsible_name', 'due_on' => 'A.due_on', 'completed_on' => 'A.completed_on',
                'status' => 'A.status', 'effectiveness' => 'A.effectiveness', 'notes' => 'A.notes', 'action_revision' => 'A.revision']);
        if ($filters['origin'] !== '') $query->where(['Nonconformities.origin' => $filters['origin']]);
        if ($filters['q'] !== '') {
            $term = '%' . strtr($filters['q'], ['%' => '\\%', '_' => '\\_']) . '%';
            $query->where(['OR' => ['Nonconformities.description LIKE' => $term, 'Nonconformities.number LIKE' => $term, 'Nonconformities.unit LIKE' => $term, 'A.description LIKE' => $term, 'A.notes LIKE' => $term, 'A.item LIKE' => $term]]);
        }
        if ($filters['status'] !== '') $query->where(['A.status' => $filters['status']]);
        if ($filters['responsible'] !== '') $query->where(['A.responsible_name' => $filters['responsible']]);
        $today = $this->nc->today();
        if ($filters['deadline'] !== '') {
            $query->where(['A.status !=' => 'completed']);
            match ($filters['deadline']) {
                'overdue' => $query->where(['A.due_on <' => $today]),
                'today' => $query->where(['A.due_on' => $today]),
                'soon' => $query->where(['A.due_on >=' => $today, 'A.due_on <=' => (new \DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d')]),
                'none' => $query->where(['A.due_on IS' => null]),
            };
        }
        $responsibles = $this->nc->table('NonconformityActions')->find()->select(['responsible_name'])->distinct()->orderByAsc('responsible_name')->all()->extract('responsible_name')->toArray();
        $records = $this->paginate($query->orderBy(['Nonconformities.id' => 'DESC', 'A.id' => 'ASC']), ['limit' => 25, 'maxLimit' => 100, 'sortableFields' => []]);
        $this->set(compact('records', 'filters', 'responsibles'));
    }
    public function view(int $id): void
    {
        $this->request->allowMethod(['get']);
        $record = $this->nc->table('Nonconformities')->get($id);
        $actions = $this->nc->table('NonconformityActions')->find()->where(['nonconformity_id' => $id])->orderByAsc('id')->all();
        $actionIds = $actions->extract('id')->toArray();
        $conditions = [['object_type' => 'nc', 'object_id' => $id]];
        if ($actionIds) $conditions[] = ['object_type' => 'action', 'object_id IN' => $actionIds];
        $history = $this->paginate($this->nc->table('NonconformityAuditEvents')->find()->where(['OR' => $conditions])->orderByDesc('id'), ['limit' => 25, 'sortableFields' => []]);
        $this->set(compact('record', 'actions', 'history'));
    }
    public function add(): ?Response { return $this->saveRecord(); }
    public function edit(int $id): ?Response { return $this->saveRecord($id); }
    private function saveRecord(?int $id = null): ?Response
    {
        $this->request->allowMethod(['get', 'post', 'patch']);
        if (!$this->nc->canWrite($this->actorId)) throw new \Cake\Http\Exception\ForbiddenException('Acesso somente para consulta.');
        $record = $id === null ? $this->nc->table('Nonconformities')->newEmptyEntity() : $this->nc->table('Nonconformities')->get($id);
        $items = $id === null ? [['status' => 'not_started']] : $this->nc->table('NonconformityActions')->find()->where(['nonconformity_id' => $id])->orderByAsc('id')->all()->map(static fn ($a) => $a->toArray())->toArray();
        $formValues = $record->toArray();
        if ($this->request->is(['post', 'patch'])) {
            if ($this->request->getData('add_item') === '1') {
                $formValues = $this->request->getData();
                $items = is_array($formValues['actions'] ?? null) ? array_values($formValues['actions']) : [];
                if (count($items) >= 100) throw new \Cake\Http\Exception\UnprocessableContentException('Envie até 100 itens por operação.');
                $items[] = ['status' => 'not_started'];
                $this->set(compact('record', 'items', 'formValues')); $this->viewBuilder()->setTemplate('form'); return null;
            }
            try {
                if ($this->request->getData('actions') !== null && $this->request->getData('form_complete') !== '1') {
                    throw new \Cake\Http\Exception\UnprocessableContentException('Formulário incompleto. Nenhum registro foi salvo. Reduza os itens por operação.');
                }
                $saved = $this->nc->saveComplete($this->actorId, $this->request->getData(), $id);
                $this->Flash->success('NC e itens salvos.'); return $this->destination('/pcm/nao-conformidades/' . $saved);
            } catch (\Cake\Http\Exception\UnprocessableContentException|\Cake\Http\Exception\ConflictException $e) {
                $this->response = $this->response->withStatus($e->getCode()); $this->Flash->error($e->getMessage());
                $formValues = $this->request->getData();
                $items = is_array($formValues['actions'] ?? null) ? array_values($formValues['actions']) : [];
            }
        }
        $this->set(compact('record', 'items', 'formValues')); $this->viewBuilder()->setTemplate('form'); return null;
    }
    public function addAction(int $id): ?Response { return $this->saveAction($id); }
    public function quickAction(int $id, int $actionId): Response
    {
        $this->request->allowMethod(['post', 'patch']);
        try {
            $this->nc->quickAction($this->actorId, $id, $actionId, $this->request->getData());
            $a = $this->nc->table('NonconformityActions')->get($actionId);
            if (!str_contains($this->request->getHeaderLine('Accept'), 'application/json')) {
                $this->Flash->success('Item atualizado.'); return $this->destination('/pcm/nao-conformidades');
            }
            return $this->response->withType('application/json')->withStringBody(json_encode(['revision' => $a->revision,
                'deadline' => NonconformityService::deadline($a->due_on, $a->status), 'tone' => NonconformityService::deadlineTone($a->due_on, $a->status)], JSON_THROW_ON_ERROR));
        } catch (\Cake\Http\Exception\UnprocessableContentException|\Cake\Http\Exception\ConflictException $e) {
            if (!str_contains($this->request->getHeaderLine('Accept'), 'application/json')) throw $e;
            return $this->response->withStatus($e->getCode())->withType('application/json')->withStringBody(json_encode(['message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }
    }
    public function editAction(int $id, int $actionId): ?Response { return $this->saveAction($id, $actionId); }
    private function saveAction(int $id, ?int $actionId = null): ?Response
    {
        $this->request->allowMethod(['get', 'post', 'patch']);
        if (!$this->nc->canWrite($this->actorId)) throw new \Cake\Http\Exception\ForbiddenException('Acesso somente para consulta.');
        $record = $this->nc->table('Nonconformities')->get($id);
        $action = $actionId === null ? $this->nc->table('NonconformityActions')->newEntity(['status' => 'not_started']) : $this->nc->table('NonconformityActions')->get($actionId);
        if ($actionId !== null && (int)$action->nonconformity_id !== $id) throw new \Cake\Http\Exception\NotFoundException('Ação não encontrada nesta NC.');
        if ($this->request->is(['post', 'patch'])) {
            try {
                $saved = $this->nc->saveAction($this->actorId, $id, $this->request->getData(), $actionId);
                $this->Flash->success('Ação atualizada.'); return $this->destination('/pcm/nao-conformidades/' . $id . '#acao-' . $saved);
            } catch (\Cake\Http\Exception\UnprocessableContentException $e) {
                $this->response = $this->response->withStatus(422); $this->Flash->error($e->getMessage());
                $action->patch(array_intersect_key($this->request->getData(), array_flip(['item', 'measure', 'description', 'responsible_name', 'due_on', 'completed_on', 'status', 'effectiveness', 'notes'])));
            }
        }
        $this->set(compact('record', 'action')); $this->viewBuilder()->setTemplate('action'); return null;
    }
    private function destination(string $path): Response
    {
        // Keep NC navigation on the current installation, including local/custom ports.
        return $this->response->withStatus(302)->withHeader('Location', \Cake\Routing\Router::url($path));
    }
}
