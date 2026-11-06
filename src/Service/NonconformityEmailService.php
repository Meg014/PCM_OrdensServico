<?php
declare(strict_types=1);
namespace App\Service;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use App\Mailer\Exception\NonconformityDeliveryException;
use App\Mailer\Transport\NonconformitySmtpTransport;
use DateTimeImmutable;
use DateTimeZone;

/** Transactional outbox. Producers use the module lock; workers claim committed rows with CAS. */
final class NonconformityEmailService
{
    public const MESSAGES = [
        'assigned' => ['heading' => 'Nova ação atribuída', 'intro' => 'Uma nova ação foi atribuída a você no sistema de Gestão de Não Conformidades.', 'notice' => 'Atenção ao prazo', 'color' => '#a65d00', 'background' => '#fff5e6'],
        'five_days' => ['heading' => 'Faltam 5 dias para o vencimento', 'intro' => 'O prazo da sua ação está se aproximando. Organize as atividades para concluir dentro do prazo.', 'notice' => 'Faltam 5 dias', 'color' => '#a65d00', 'background' => '#fff5e6'],
        'one_day' => ['heading' => 'Falta 1 dia para o vencimento', 'intro' => 'Sua ação vence amanhã. Verifique as pendências e registre a conclusão no PCM.', 'notice' => 'Prazo urgente: amanhã', 'color' => '#a65d00', 'background' => '#fff5e6'],
        'today' => ['heading' => 'Vence hoje', 'intro' => 'O prazo desta ação termina hoje. Conclua a atividade e atualize a situação no PCM.', 'notice' => 'Último dia do prazo', 'color' => '#b42318', 'background' => '#fff0ed'],
        'overdue' => ['heading' => 'Ação atrasada', 'intro' => 'Esta ação está com o prazo vencido e continua pendente. Regularize a atividade e atualize o PCM.', 'notice' => 'Prazo vencido', 'color' => '#b42318', 'background' => '#fff0ed'],
    ];
    private NonconformityService $nc;
    public function __construct() { $this->nc = new NonconformityService(); }
    private function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'); }
    public function cancelObsolete(EntityInterface $a): void
    {
        $this->nc->table('NonconformityEmailQueue')->updateAll(['status' => 'cancelled'], [
            'action_id' => $a->id, 'status' => 'pending', 'OR' => [
                ['assignment_cycle !=' => $a->assignment_cycle], ['deadline_cycle !=' => $a->deadline_cycle],
                ...($a->status === 'completed' ? [['action_id' => $a->id]] : []),
            ],
        ]);
    }
    private function previouslyAccepted(EntityInterface $action, string $recipient, string $event, string $today, ?int $exclude = null): bool
    {
        $conditions = ['action_id' => $action->id, 'assignment_cycle' => $action->assignment_cycle, 'event' => $event];
        if ($exclude !== null) $conditions['id !='] = $exclude;
        if ($event === 'overdue') {
            $conditions['OR'] = [['status IN' => ['sending', 'uncertain']], ['status' => 'sent',
                'sent_at >' => (new DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d') . ' 23:59:59']];
        } else {
            $conditions += ['recipient' => $recipient, 'status IN' => ['sent', 'sending', 'uncertain']];
            if ($event !== 'assigned') $conditions['due_on IS'] = NonconformityService::iso($action->due_on);
        }
        return $this->nc->table('NonconformityEmailQueue')->exists($conditions);
    }
    /** Must run in the same transaction as the action or under the producer lock. */
    public function enqueue(EntityInterface $a, string $event, ?string $today = null): bool
    {
        if ($a->status === 'completed' || $a->responsible_id === null) return false;
        $r = $this->nc->table('NonconformityResponsibles')->get($a->responsible_id);
        if (!$r->active || !filter_var($r->email, FILTER_VALIDATE_EMAIL)) return false;
        $today ??= $this->nc->today();
        $key = hash('sha256', implode('|', [$a->id, $a->assignment_cycle, $a->deadline_cycle, $event, $event === 'overdue' ? $today : '']));
        $q = $this->nc->table('NonconformityEmailQueue');
        // Reopening or restoring an earlier deadline must not repeat a delivered event.
        if ($this->previouslyAccepted($a, $r->email, $event, $today)) return false;
        if ($q->exists(['event_key' => $key])) return false;
        $q->saveOrFail($q->newEntity(['event_key' => $key, 'recipient' => $r->email, 'event' => $event,
            'nonconformity_id' => $a->nonconformity_id, 'action_id' => $a->id, 'responsible_id' => $r->id,
            'assignment_cycle' => $a->assignment_cycle, 'deadline_cycle' => $a->deadline_cycle,
            'due_on' => NonconformityService::iso($a->due_on), 'scheduled_at' => $today . ' ' . substr($this->now(), 11),
            'available_at' => $this->now(), 'status' => 'pending', 'attempts' => 0], ['validate' => false]));
        return true;
    }
    public function generate(?string $today = null): int
    {
        $today ??= $this->nc->today();
        $c = $this->nc->table('NonconformityManagerAssignments')->getConnection();
        return $c->transactional(function () use ($today, $c) {
            if ($c->getDriver() instanceof \Cake\Database\Driver\Sqlite) $c->execute('UPDATE nonconformity_manager_assignments SET revision = revision WHERE id = 1');
            else $c->execute('SELECT id FROM nonconformity_manager_assignments WHERE id = 1 FOR UPDATE')->fetchAll('assoc');
            $count = 0; $q = $this->nc->table('NonconformityEmailQueue');
            foreach ($this->nc->table('NonconformityActions')->find()->where(['status !=' => 'completed', 'responsible_id IS NOT' => null, 'due_on IS NOT' => null,
                'due_on <=' => (new DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d')]) as $a) {
                $days = (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable(NonconformityService::iso($a->due_on)))->format('%r%a');
                $event = $days < 0 ? 'overdue' : ([5 => 'five_days', 1 => 'one_day', 0 => 'today'][$days] ?? null);
                if ($event === null) continue;
                $cycle = ['action_id' => $a->id, 'assignment_cycle' => $a->assignment_cycle, 'deadline_cycle' => $a->deadline_cycle, 'status !=' => 'cancelled'];
                // A new assignment already explains the deadline; do not send a second message that day.
                if ($q->exists(['action_id' => $a->id, 'assignment_cycle' => $a->assignment_cycle, 'status !=' => 'cancelled', 'event' => 'assigned', 'scheduled_at >=' => $today . ' 00:00:00', 'scheduled_at <=' => $today . ' 23:59:59'])) continue;
                if ($event === 'overdue') {
                    if ($q->exists(['action_id' => $a->id, 'assignment_cycle' => $a->assignment_cycle, 'event' => 'overdue', 'status IN' => ['sending', 'uncertain']])) continue;
                    if ($q->exists($cycle + ['event' => 'overdue', 'status IN' => ['pending', 'sending', 'uncertain']])) continue;
                    $last = $q->find()->where($cycle + ['event' => 'overdue'])->orderByDesc('scheduled_at')->first();
                    if ($last !== null && substr((string)$last->scheduled_at->format('Y-m-d H:i:s'), 0, 10) > (new DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d')) continue;
                    if ($q->exists(['action_id' => $a->id, 'assignment_cycle' => $a->assignment_cycle, 'event' => 'overdue', 'sent_at >' => (new DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d') . ' 23:59:59'])) continue;
                }
                $count += (int)$this->enqueue($a, $event, $today);
            }
            return $count;
        });
    }
    /** Injectable sender keeps tests entirely offline. No database transaction surrounds SMTP. */
    public function process(int $limit = 100, ?callable $sender = null): array
    {
        $q = $this->nc->table('NonconformityEmailQueue');
        if ($q->getConnection()->inTransaction()) throw new \LogicException('Não enviar e-mail dentro de transação.');
        if ($sender === null && !Configure::read('NonconformityMail.enabled', false)) throw new \RuntimeException('Envio desativado: valide a integração e habilite NC_MAIL_ENABLED.');
        $result = ['sent' => 0, 'failed' => 0, 'cancelled' => 0];
        $rows = $q->find()->where(['status' => 'pending', 'available_at <=' => $this->now(), 'attempts <' => 5])->orderByAsc('id')->limit($limit)->all();
        foreach ($rows as $row) {
            $outcome = NonconformityOperationMutex::run($q->getConnection(), function () use ($row, $q, $sender): string {
                $claimed = $q->updateQuery()->set(['status' => 'sending', 'claimed_at' => $this->now(), 'attempts' => (int)$row->attempts + 1])
                    ->where(['id' => $row->id, 'status' => 'pending', 'attempts' => $row->attempts])->execute()->rowCount();
                if (!$claimed) return 'skipped';
                try {
                    $a = $this->nc->table('NonconformityActions')->get($row->action_id);
                    $r = $this->nc->table('NonconformityResponsibles')->get($row->responsible_id);
                    $days = $a->due_on === null ? null : (int)(new DateTimeImmutable($this->nc->today()))->diff(new DateTimeImmutable(NonconformityService::iso($a->due_on)))->format('%r%a');
                    $validEvent = $row->event === 'assigned' || ($row->event === 'overdue' && $days !== null && $days < 0)
                        || (['five_days' => 5, 'one_day' => 1, 'today' => 0][$row->event] ?? null) === $days && $days !== null;
                    if ($a->status === 'completed' || !$r->active || $r->email !== $row->recipient || (int)$a->responsible_id !== (int)$r->id
                        || !filter_var($r->email, FILTER_VALIDATE_EMAIL) || NonconformityService::iso($a->due_on) !== NonconformityService::iso($row->due_on)
                        || !$validEvent || (int)$a->assignment_cycle !== (int)$row->assignment_cycle || (int)$a->deadline_cycle !== (int)$row->deadline_cycle
                        || $this->previouslyAccepted($a, $r->email, $row->event, $this->nc->today(), (int)$row->id)) {
                        $q->updateAll(['status' => 'cancelled'], ['id' => $row->id]); return 'cancelled';
                    }
                    $nc = $this->nc->table('Nonconformities')->get($a->nonconformity_id);
                    if ($sender !== null) $sender($row, $a, $r, $nc);
                    else $this->deliver($row, $a, $r, $nc);
                } catch (\Throwable $e) {
                    // Transport failures may occur after remote acceptance. Never blindly retry ambiguous delivery.
                    $definite = $e instanceof \Cake\Network\Exception\SocketException && preg_match('/connection refused|could not connect|failed to connect|unable to connect to SMTP server/i', $e->getMessage());
                    $known = $e instanceof NonconformityDeliveryException;
                    $retry = ($known ? $e->retryable : $definite) && (int)$row->attempts + 1 < 5;
                    $status = $retry ? 'pending' : (($known ? !$e->uncertain : $definite) ? 'failed' : 'uncertain');
                    $error = $known ? $e->getMessage() : get_class($e) . ': falha no envio; consultar o provedor pelo identificador da fila.';
                    $delay = max(300 * (2 ** (int)$row->attempts), $known ? $e->retryAfter : 0);
                    $q->updateAll(['status' => $status, 'error' => $error,
                        'available_at' => (new DateTimeImmutable($this->now()))->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s')], ['id' => $row->id]);
                    Log::error('NC e-mail fila #' . $row->id . ' status=' . $status . ' error=' . $error);
                    return $known && $e->haltBatch ? 'halt' : 'failed';
                }
                // A crash here leaves 'sending'. SMTP has no exactly-once guarantee; reconcile before retrying.
                $q->updateAll(['status' => 'sent', 'sent_at' => $this->now(), 'error' => null], ['id' => $row->id]);
                Log::info('NC e-mail aceito pelo provedor fila #' . $row->id);
                return 'sent';
            });
            if ($outcome === 'skipped') continue;
            if ($outcome === 'halt') { $result['failed']++; break; }
            $result[$outcome]++;
        }
        return $result;
    }
    public function report(): array
    {
        $queue = $this->nc->table('NonconformityEmailQueue'); $counts = [];
        foreach (['pending', 'sending', 'sent', 'failed', 'uncertain', 'cancelled'] as $status) $counts[$status] = $queue->find()->where(['status' => $status])->count();
        return ['today' => $this->nc->today(), 'timezone' => 'America/Sao_Paulo', 'enabled' => (bool)Configure::read('NonconformityMail.enabled', false),
            'provider' => Configure::read('NonconformityMail.provider', 'smtp'), 'queue' => $counts];
    }
    private function deliver(EntityInterface $row, EntityInterface $a, EntityInterface $r, EntityInterface $nc): void
    {
        $this->createMessage($row, $a, $r, $nc)->deliver();
    }
    /** Shared by the outbox, offline previews and the explicitly controlled test command. */
    public function createMessage(EntityInterface $row, EntityInterface $a, EntityInterface $r, EntityInterface $nc, bool $test = false): Mailer
    {
        $base = rtrim((string)Configure::read('App.fullBaseUrl'), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) throw new NonconformityDeliveryException('Configure APP_FULL_BASE_URL.');
        if (Configure::read('NonconformityMail.from', NonconformitySmtpTransport::MAILBOX) !== NonconformitySmtpTransport::MAILBOX) throw new NonconformityDeliveryException('O remetente de NC deve ser qualidade@biolasa.com.br.');
        $transport = match (Configure::read('NonconformityMail.provider', 'smtp')) {
            'smtp' => 'nc_smtp', 'debug' => 'nc_debug',
            default => throw new NonconformityDeliveryException('Provedor de e-mail não configurado. Use smtp ou debug; outro método de autenticação precisa ser definido com a TI.'),
        };
        $meta = self::MESSAGES[$row->event] ?? throw new NonconformityDeliveryException('Tipo de mensagem inválido.');
        $mailer = new Mailer((string)Configure::read('NonconformityMail.profile', 'nonconformity'));
        $mailer->setTransport($transport)->setFrom([NonconformitySmtpTransport::MAILBOX => 'Biolasa | PCM Qualidade'])
            ->setSender(NonconformitySmtpTransport::MAILBOX)->setReturnPath(NonconformitySmtpTransport::MAILBOX)
            ->setTo($row->recipient)->setCc([])->setBcc([])->setSubject(($test ? '[TESTE CONTROLADO] ' : '') . '[PCM] ' . $meta['heading'] . ' — NC ' . ($nc->number ?? 'sem número') . ' / Item ' . ($a->item ?? 'sem número'))
            ->setEmailFormat('both')->setMessageId('<' . $row->event_key . '@' . parse_url($base, PHP_URL_HOST) . '>')
            ->setViewVars(['nc' => $nc, 'action' => $a, 'responsible' => $r, 'heading' => $meta['heading'], 'meta' => $meta,
                'isTest' => $test, 'event' => $row->event,
                'link' => (new NonconformityConsultationService())->link($a, $r)]);
        $mailer->viewBuilder()->setTemplate('nonconformity')->setLayout('nonconformity');
        return $mailer;
    }
    public function testMessage(string $recipient, string $event = 'assigned'): Mailer
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new NonconformityDeliveryException('Informe um destinatário válido em --to.');
        if (!isset(self::MESSAGES[$event])) throw new NonconformityDeliveryException('Modelo inválido. Use assigned, five_days, one_day, today ou overdue.');
        $days = ['assigned' => 10, 'five_days' => 5, 'one_day' => 1, 'today' => 0, 'overdue' => -1][$event];
        $due = (new DateTimeImmutable($this->nc->today()))->modify(sprintf('%+d days', $days))->format('Y-m-d');
        return $this->createMessage(
            new \Cake\ORM\Entity(['recipient' => $recipient, 'event' => $event, 'event_key' => 'nc-test-' . bin2hex(random_bytes(16))]),
            new \Cake\ORM\Entity(['item' => '03', 'description' => 'Realizar a adequação das proteções de segurança do equipamento.', 'measure' => 'Ação corretiva', 'due_on' => new \Cake\I18n\Date($due), 'status' => 'not_started']),
            new \Cake\ORM\Entity(['name' => 'Destinatário de teste']),
            new \Cake\ORM\Entity(['number' => 'TESTE', 'description' => 'Exemplo ilustrativo de não conformidade para validar o envio de e-mails.', 'origin' => 'Teste controlado']), true,
        );
    }
}
