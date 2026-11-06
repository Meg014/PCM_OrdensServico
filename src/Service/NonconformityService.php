<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\FactoryLocator;
use Cake\Http\Exception\ConflictException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\UnprocessableContentException;
use Cake\I18n\Date;
use Cake\ORM\Table;
use DateTimeImmutable;
use DateTimeZone;

/** All module writes share a database lock, authorization and an atomic audit trail. */
final class NonconformityService
{
    public const ORIGINS = ['Auditoria Interna', 'Auditoria Externa', 'Inspeção BPF', 'Meio Ambiente',
        'Inspeção vidros', 'Reclamação de cliente', 'Não conformidade interna', 'Análise crítica', 'MOC', 'Fiscalização'];
    public const STATUSES = ['not_started' => 'Não iniciada', 'in_progress' => 'Em andamento', 'completed' => 'Concluída'];
    public const UNITS = ['Servlasa/ Lasa', 'Usina/ Fábrica'];
    public const MEASURES = ['Correção', 'Ação Corretiva'];
    public const EVENTS = ['five_days' => 'Faltam 5 dias', 'one_day' => 'Falta 1 dia', 'today' => 'Vence hoje', 'overdue' => 'Ação atrasada'];

    public function table(string $name): Table { return FactoryLocator::get('Table')->get($name); }
    public function assignment(): \Cake\Datasource\EntityInterface { return $this->table('NonconformityManagerAssignments')->get(1); }
    public function today(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'); }
    private function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'); }
    public function actor(int $id): \Cake\Datasource\EntityInterface
    {
        $u = $this->table('Users')->get($id);
        if (!$u->ativo || !in_array($u->role, ['ADMIN', 'USUARIO'], true) || $u->must_change_password) throw new ForbiddenException('Sem acesso a Não Conformidades.');
        return $u;
    }
    public function canWrite(int $id): bool
    {
        $u = $this->actor($id);
        return $u->role === 'ADMIN' || (int)$this->assignment()->user_id === $id;
    }
    private function lock(): void
    {
        $c = $this->table('NonconformityManagerAssignments')->getConnection();
        if ($c->getDriver() instanceof \Cake\Database\Driver\Sqlite) {
            $c->execute('UPDATE nonconformity_manager_assignments SET revision = revision WHERE id = 1');
        } else {
            $c->execute('SELECT id FROM nonconformity_manager_assignments WHERE id = 1 FOR UPDATE')->fetchAll('assoc');
        }
    }
    private function atomic(callable $operation): mixed
    {
        $connection = $this->table('NonconformityManagerAssignments')->getConnection();
        return $connection->transactional(function () use ($operation) {
            $this->lock();
            return $operation();
        });
    }
    private function mailAtomic(callable $operation): mixed
    {
        $connection = $this->table('NonconformityManagerAssignments')->getConnection();
        return NonconformityOperationMutex::run($connection, fn () => $this->atomic($operation));
    }
    private function audit(int $actorId, string $type, int $id, string $operation, array $before, array $after): void
    {
        $actor = $this->table('Users')->get($actorId);
        $t = $this->table('NonconformityAuditEvents');
        $t->saveOrFail($t->newEntity(['actor_id' => $actorId, 'actor_name' => $actor->nome, 'object_type' => $type,
            'object_id' => $id, 'operation' => $operation, 'before_json' => json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created' => $this->now()], ['validate' => false]));
    }
    /** Confirmation must name the assignment revision displayed to the administrator. */
    public function assign(int $actorId, ?int $targetId, int $expectedRevision, bool $confirmed): void
    {
        $this->atomic(function () use ($actorId, $targetId, $expectedRevision, $confirmed) {
            if ($this->actor($actorId)->role !== 'ADMIN') throw new ForbiddenException('Somente ADMIN pode designar o gestor.');
            $a = $this->assignment();
            if ((int)$a->revision !== $expectedRevision) throw new ConflictException('O responsável foi alterado. Atualize a página e confirme novamente.');
            if ($targetId !== null) {
                $target = $this->table('Users')->get($targetId);
                if (!$target->ativo || !in_array($target->role, ['ADMIN', 'USUARIO'], true)) throw new ForbiddenException('Selecione uma conta ativa ADMIN ou USUARIO.');
            }
            if ((int)$a->user_id === (int)$targetId) return;
            if ($a->user_id !== null && !$confirmed) throw new ConflictException('Já existe um responsável ativo. Confirme a substituição.');
            $before = $a->toArray();
            $a->set('user_id', $targetId); $a->set('revision', (int)$a->revision + 1);
            $this->table('NonconformityManagerAssignments')->saveOrFail($a);
            $this->audit($actorId, 'assignment', 1, 'assign', $before, $a->toArray());
            $this->invalidate([]);
            $this->generateLocked($this->today());
        });
    }
    /** Called inside UsersTable's save transaction when an account loses eligibility. */
    public function revokeIneligible(int $userId, int $actorId): void
    {
        $this->atomic(function () use ($userId, $actorId) {
            $a = $this->assignment();
            if ((int)$a->user_id !== $userId) return;
            $before = $a->toArray();
            $a->set('user_id', null); $a->set('revision', (int)$a->revision + 1);
            $this->table('NonconformityManagerAssignments')->saveOrFail($a);
            $this->audit($actorId, 'assignment', 1, 'revoke_ineligible', $before, $a->toArray());
            $this->invalidate([]);
        });
    }
    private function text(array $input, string $key, int $max, bool $required = false, bool $preserve = false): ?string
    {
        $v = $input[$key] ?? '';
        if (!is_scalar($v)) throw new UnprocessableContentException('Campo inválido: ' . $key);
        $original = (string)$v; $v = trim($original);
        if (($required && $v === '') || mb_strlen($original) > $max || strlen($original) > 65535) throw new UnprocessableContentException('Preencha corretamente: ' . $key);
        return $v === '' ? null : ($preserve ? $original : $v);
    }
    private function date(array $input, string $key, bool $required = false): ?string
    {
        $v = $this->text($input, $key, 10, $required);
        if ($v !== null) {
            if (preg_match('~^\d{2}/\d{2}/\d{4}$~D', $v)) $v = implode('-', array_reverse(explode('/', $v)));
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if (!$d || $d->format('Y-m-d') !== $v) throw new UnprocessableContentException('Data inválida: ' . $key);
        }
        return $v;
    }
    private function assertRevision(array $input, int $revision): void
    {
        if (!isset($input['revision']) || !is_scalar($input['revision']) || (string)$input['revision'] !== (string)$revision) throw new ConflictException('Este registro foi atualizado. Recarregue antes de salvar.');
    }
    public static function normalizeName(string $name): string
    {
        $name = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        return trim(preg_replace('/\s+/u', ' ', $name));
    }
    public function saveResponsible(int $actorId, array $input, ?int $id = null): int
    {
        return $this->mailAtomic(function () use ($actorId, $input, $id) {
            if (!$this->canWrite($actorId)) throw new ForbiddenException('Acesso somente para consulta.');
            $t = $this->table('NonconformityResponsibles');
            $e = $id === null ? $t->newEmptyEntity() : $t->get($id);
            if ($id !== null) $this->assertRevision($input, (int)$e->revision);
            $before = $e->toArray();
            $name = $this->text($input, 'name', 150, true);
            $normalized = self::normalizeName($name);
            if ($normalized === '' || mb_strlen($normalized) > 150) throw new UnprocessableContentException('Nome inválido ou muito longo após normalização.');
            $duplicate = $t->find()->where(['normalized_name' => $normalized]);
            if ($id !== null) $duplicate->where(['id !=' => $id]);
            if ($duplicate->count()) throw new UnprocessableContentException('Responsável já cadastrado, inclusive se inativo.');
            $email = $this->text($input, 'email', 254);
            if ($email === null) throw new UnprocessableContentException('Informe o e-mail do responsável.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UnprocessableContentException('Informe um endereço de e-mail válido, como nome@empresa.com.br.');
            if (!is_scalar($input['active'] ?? null) || !in_array((string)$input['active'], ['0', '1'], true)) throw new UnprocessableContentException('Situação inválida.');
            $e->patch(['name' => $name, 'normalized_name' => $normalized, 'email' => $email,
                'sector' => $this->text($input, 'sector', 150), 'active' => (bool)$input['active'],
                'revision' => $id === null ? 0 : (int)$e->revision + 1, 'modified' => $this->now()]);
            if ($id === null) $e->set('created', $this->now());
            $t->saveOrFail($e);
            $this->audit($actorId, 'responsible', (int)$e->id, $id === null ? 'create' : 'update', $before, $e->toArray());
            // Pending messages must never use an obsolete address or an inactive recipient.
            if ($id !== null && (!$e->active || ($before['email'] ?? null) !== $email)) {
                $this->table('NonconformityEmailQueue')->updateAll(['status' => 'cancelled'], ['responsible_id' => $id, 'status' => 'pending']);
            }
            return (int)$e->id;
        });
    }
    private function resolveResponsible(int $actorId, array $input, \Cake\Datasource\EntityInterface $existing, ?int $id): array
    {
        if (array_key_exists('responsible_email', $input)) {
            $name = $this->text($input, 'responsible_name', 150, true);
            $email = $this->text($input, 'responsible_email', 254, true);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UnprocessableContentException('Informe um e-mail válido para o responsável.');
            $contacts = $this->table('NonconformityResponsibles');
            $contact = $contacts->find()->where(['normalized_name' => self::normalizeName($name)])->first();
            if ($contact !== null) {
                if (!$contact->active) throw new UnprocessableContentException('Responsável inativo. Revise o cadastro em Responsáveis.');
                if ((string)$contact->email !== $email) throw new UnprocessableContentException('O e-mail difere do cadastro existente. Corrija-o na administração de Responsáveis antes de salvar a ação.');
                if (!empty($input['responsible_id']) && (string)$input['responsible_id'] !== (string)$contact->id) throw new UnprocessableContentException('Nome e responsável selecionado não correspondem. Selecione novamente.');
                $input['responsible_id'] = $contact->id;
            } else {
                if (!empty($input['responsible_id'])) throw new UnprocessableContentException('Nome e responsável selecionado não correspondem.');
                $input['responsible_id'] = $this->saveResponsible($actorId, ['name' => $name, 'email' => $email, 'active' => '1']);
            }
        }
        $raw = $input['responsible_id'] ?? null;
        if ($raw !== null && $raw !== '') {
            $rid = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $r = $rid === false ? null : $this->table('NonconformityResponsibles')->find()->where(['id' => $rid])->first();
            if ($r === null || (!$r->active && ($id === null || (int)$existing->responsible_id !== (int)$r->id))) throw new UnprocessableContentException('Selecione um responsável ativo.');
            if (($id === null || (int)$existing->responsible_id !== (int)$r->id) && !filter_var($r->email, FILTER_VALIDATE_EMAIL)) throw new UnprocessableContentException('Preencha um e-mail válido no cadastro do responsável antes de atribuir novas ações.');
            // Preserve the imported text as provenance. Display the linked name separately.
            return ['responsible_id' => (int)$r->id, 'responsible_name' => $id === null ? $r->name : $existing->responsible_name];
        }
        if ($id !== null) {
            if ($existing->responsible_id !== null) throw new UnprocessableContentException('Selecione o responsável da ação.');
            return ['responsible_id' => null, 'responsible_name' => $existing->responsible_name];
        }
        // Compatibility for existing import/integration callers; browser forms require an ID.
        if (!empty($input['require_responsible_id'])) throw new UnprocessableContentException('Selecione um responsável cadastrado.');
        return ['responsible_id' => null, 'responsible_name' => $this->text($input, 'responsible_name', 150, true)];
    }
    public function saveNc(int $actorId, array $input, ?int $id = null): int
    {
        return $this->atomic(function () use ($actorId, $input, $id) {
            if (!$this->canWrite($actorId)) throw new ForbiddenException('Você possui acesso somente para consulta.');
            $t = $this->table('Nonconformities'); $e = $id === null ? $t->newEmptyEntity() : $t->get($id);
            if ($id !== null) $this->assertRevision($input, (int)$e->revision);
            $before = $e->toArray();
            $data = ['number' => $this->text($input, 'number', 80), 'unit' => $this->text($input, 'unit', 150, true, true),
                'origin' => $this->text($input, 'origin', 100, true), 'occurred_on' => $this->date($input, 'occurred_on', true),
                'description' => $this->text($input, 'description', 20000, false, true) ?? ''];
            if (!in_array($data['unit'], self::UNITS, true) && ($id === null || $data['unit'] !== $e->unit)) throw new UnprocessableContentException('Selecione uma Unidade da lista. Valores históricos podem apenas ser mantidos.');
            if (!in_array($data['origin'], self::ORIGINS, true)) throw new UnprocessableContentException('Origem inválida.');
            $duplicate = $t->find()->where(['unit' => $data['unit'], 'origin' => $data['origin'], 'number IS' => $data['number']]);
            if ($id !== null) $duplicate->where(['id !=' => $id]);
            if ($data['number'] !== null && $duplicate->count()) throw new UnprocessableContentException('Número já cadastrado nesta unidade e origem.');
            $data += ['revision' => $id === null ? 0 : (int)$e->revision + 1, 'modified' => $this->now()];
            if ($id === null) $data['created'] = $this->now();
            $e->patch($data); $t->saveOrFail($e);
            $this->audit($actorId, 'nc', (int)$e->id, $id === null ? 'create' : 'update', $before, $e->toArray());
            return (int)$e->id;
        });
    }
    public function saveAction(int $actorId, int $ncId, array $input, ?int $id = null): int
    {
        return $this->mailAtomic(function () use ($actorId, $ncId, $input, $id) {
            if (!$this->canWrite($actorId)) throw new ForbiddenException('Você possui acesso somente para consulta.');
            $this->table('Nonconformities')->get($ncId);
            $t = $this->table('NonconformityActions'); $e = $id === null ? $t->newEmptyEntity() : $t->get($id);
            if ($id !== null && (int)$e->nonconformity_id !== $ncId) throw new ForbiddenException('Ação não pertence a esta NC.');
            if ($id !== null) $this->assertRevision($input, (int)$e->revision);
            $before = $e->toArray();
            $data = ['nonconformity_id' => $ncId, 'item' => $this->text($input, 'item', 80),
                'measure' => $this->text($input, 'measure', 20000, true, true), 'description' => $this->text($input, 'description', 20000, true, true),
                'due_on' => $this->date($input, 'due_on'),
                'completed_on' => $this->date($input, 'completed_on'), 'status' => $this->text($input, 'status', 30, true),
                'effectiveness' => $this->text($input, 'effectiveness', 10000, false, true), 'notes' => $this->text($input, 'notes', 20000, false, true)];
            if (!in_array($data['measure'], self::MEASURES, true) && ($id === null || $data['measure'] !== $e->measure)) throw new UnprocessableContentException('Selecione uma Medida da lista. Valores históricos podem apenas ser mantidos.');
            $data += $this->resolveResponsible($actorId, $input, $e, $id);
            if (!isset(self::STATUSES[$data['status']])) throw new UnprocessableContentException('Realizado inválido.');
            if (($data['status'] === 'completed') !== ($data['completed_on'] !== null)) throw new UnprocessableContentException('Informe conclusão somente para ação concluída.');
            if ($data['completed_on'] !== null && $data['completed_on'] > $this->today()) throw new UnprocessableContentException('Conclusão não pode estar no futuro.');
            $dup = $t->find()->where(['nonconformity_id' => $ncId, 'item IS' => $data['item']]);
            if ($id !== null) $dup->where(['id !=' => $id]);
            if ($data['item'] !== null && $dup->count()) throw new UnprocessableContentException('Item já cadastrado nesta NC.');
            $cycleChanged = $id !== null && (self::iso($e->due_on) !== $data['due_on'] || ($e->status === 'completed' && $data['status'] !== 'completed'));
            $data += ['revision' => $id === null ? 0 : (int)$e->revision + 1, 'deadline_cycle' => ($id === null ? 0 : (int)$e->deadline_cycle) + (int)$cycleChanged, 'modified' => $this->now()];
            if ($id === null) $data['created'] = $this->now();
            $changedResponsible = $id === null || (int)$e->responsible_id !== (int)$data['responsible_id'];
            $assignmentPending = $id !== null && $this->table('NonconformityEmailQueue')->exists(['action_id' => $id, 'event' => 'assigned', 'status' => 'pending']);
            $data['assignment_cycle'] = ($id === null ? 0 : (int)$e->assignment_cycle) + (int)$changedResponsible;
            $e->patch($data); $t->saveOrFail($e);
            $mail = new NonconformityEmailService();
            $mail->cancelObsolete($e);
            if (($changedResponsible || ($cycleChanged && $assignmentPending)) && $e->responsible_id !== null && $e->status !== 'completed') $mail->enqueue($e, 'assigned');
            $this->audit($actorId, 'action', (int)$e->id, $id === null ? 'create' : 'update', $before, $e->toArray());
            if ($cycleChanged || $data['status'] === 'completed') $this->invalidate(['action_id' => $e->id]);
            $this->generateLocked($this->today());
            return (int)$e->id;
        });
    }
    public static function iso(mixed $date): ?string { return $date === null || $date === '' ? null : ($date instanceof \DateTimeInterface || $date instanceof Date ? $date->format('Y-m-d') : substr((string)$date, 0, 10)); }
    /** NC and all supplied items commit together, retaining existing items omitted by the form. */
    public function saveComplete(int $actorId, array $input, ?int $id = null): int
    {
        return $this->mailAtomic(function () use ($actorId, $input, $id) {
            if (!$this->canWrite($actorId)) throw new ForbiddenException('Acesso somente para consulta.');
            $items = $input['actions'] ?? [];
            if (!is_array($items) || count($items) > 100) throw new UnprocessableContentException('Envie até 100 itens por operação.');
            $saved = $this->saveNc($actorId, $input, $id); $seen = [];
            foreach ($items as $index => $item) {
                if (!is_array($item)) throw new UnprocessableContentException('Item inválido.');
                $actionId = null;
                if (isset($item['id']) && $item['id'] !== '') {
                    $actionId = filter_var($item['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($actionId === false || $id === null || isset($seen[$actionId])) throw new UnprocessableContentException('Identificação de item inválida.');
                    $seen[$actionId] = true;
                }
                try { $this->saveAction($actorId, $saved, $item, $actionId); }
                catch (UnprocessableContentException $e) { throw new UnprocessableContentException('Item ' . ((int)$index + 1) . ': ' . $e->getMessage()); }
            }
            return $saved;
        });
    }
    public function quickAction(int $actorId, int $ncId, int $id, array $input): int
    {
        return $this->mailAtomic(function () use ($actorId, $ncId, $id, $input) {
            if (!$this->canWrite($actorId)) throw new ForbiddenException('Acesso somente para consulta.');
            $a = $this->table('NonconformityActions')->get($id);
            if ((int)$a->nonconformity_id !== $ncId) throw new ForbiddenException('Ação não pertence a esta NC.');
            $data = $a->toArray(); $data['due_on'] = self::iso($a->due_on); $data['completed_on'] = self::iso($a->completed_on);
            $data = array_replace($data, array_intersect_key($input, array_flip(['status', 'responsible_id', 'due_on', 'completed_on', 'notes'])));
            $data['revision'] = $input['revision'] ?? null;
            return $this->saveAction($actorId, $ncId, $data, $id);
        });
    }
    public static function deadlineTone(mixed $due, string $status, ?string $today = null): string
    {
        if ($status === 'completed') return 'completed';
        $due = self::iso($due); if ($due === null) return 'none';
        $days = (int)(new DateTimeImmutable($today ?? (new self())->today()))->diff(new DateTimeImmutable($due))->format('%r%a');
        return $days < 0 ? 'overdue' : ($days === 0 ? 'today' : ($days === 1 ? 'urgent' : ($days <= 5 ? 'attention' : 'on_time')));
    }
    public static function deadline(mixed $due, string $status, ?string $today = null): string
    {
        if ($status === 'completed') return 'Concluída';
        $due = self::iso($due);
        if ($due === null) return 'Sem prazo';
        $today ??= (new self())->today();
        $days = (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($due))->format('%r%a');
        return $days < 0 ? 'Atrasada' : ($days === 0 ? 'Vence hoje' : ($days <= 5 ? 'Vence em ' . $days . ' dia(s)' : 'No prazo'));
    }
    private function invalidate(array $conditions): void
    {
        $this->table('NonconformityNotifications')->updateAll(['invalidated_at' => $this->now()], $conditions + ['invalidated_at IS' => null]);
    }
    public function generate(?string $today = null): int { return $this->atomic(fn () => $this->generateLocked($today ?? $this->today())); }
    private function generateLocked(string $today): int
    {
        $a = $this->assignment(); if ($a->user_id === null) return 0;
        $u = $this->table('Users')->get($a->user_id);
        if (!$u->ativo || !in_array($u->role, ['ADMIN', 'USUARIO'], true)) return 0;
        $t = $this->table('NonconformityNotifications'); $count = 0;
        foreach ($this->table('NonconformityActions')->find()->where(['status !=' => 'completed', 'due_on IS NOT' => null, 'due_on <=' => (new DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d')]) as $action) {
            $days = (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable(self::iso($action->due_on)))->format('%r%a');
            $event = $days < 0 ? 'overdue' : ([0 => 'today', 1 => 'one_day', 5 => 'five_days'][$days] ?? null);
            if ($event === null) continue;
            $key = ['user_id' => $u->id, 'action_id' => $action->id, 'event' => $event, 'due_on' => self::iso($action->due_on), 'deadline_cycle' => $action->deadline_cycle, 'assignment_revision' => $a->revision];
            if ($t->exists($key)) continue;
            $t->saveOrFail($t->newEntity($key + ['created' => $this->now()], ['validate' => false])); $count++;
        }
        return $count;
    }
    public function notifications(int $actorId): array
    {
        $this->actor($actorId); $a = $this->assignment();
        if ((int)$a->user_id !== $actorId) return ['count' => 0, 'items' => []];
        $q = $this->table('NonconformityNotifications')->find()->where(['user_id' => $actorId, 'assignment_revision' => $a->revision, 'invalidated_at IS' => null]);
        $count = (clone $q)->where(['read_at IS' => null])->count();
        $items = $q->orderBy('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('id')->limit(30)->all()->toArray();
        return compact('count', 'items');
    }
    public function openNotification(int $actorId, int $id): array
    {
        return $this->atomic(function () use ($actorId, $id) {
            $this->actor($actorId); $a = $this->assignment(); $t = $this->table('NonconformityNotifications'); $n = $t->get($id);
            if ((int)$a->user_id !== $actorId || (int)$n->user_id !== $actorId || (int)$n->assignment_revision !== (int)$a->revision || $n->invalidated_at !== null) throw new ForbiddenException('Notificação indisponível.');
            if ($n->read_at === null) { $n->set('read_at', $this->now()); $t->saveOrFail($n); }
            $action = $this->table('NonconformityActions')->get($n->action_id);
            return ['nc' => (int)$action->nonconformity_id, 'action' => (int)$action->id];
        });
    }
}
