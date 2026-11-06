<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service;

use App\Service\NonconformityEmailService;
use App\Service\NonconformityService;
use Cake\Core\Configure;
use Cake\Http\Exception\UnprocessableContentException;
use Cake\TestSuite\TestCase;

final class NonconformityEmailServiceTest extends TestCase
{
    use \Cake\TestSuite\EmailTrait;
    private NonconformityService $nc;
    private NonconformityEmailService $mail;
    private int $actor;
    private int $responsible;
    private int $ncId;
    protected function setUp(): void
    {
        parent::setUp(); $this->nc = new NonconformityService(); $this->mail = new NonconformityEmailService();
        $this->clean();
        $users = $this->nc->table('Users');
        $u = $users->newEntity(['nome' => 'Teste e-mail NC', 'email' => bin2hex(random_bytes(8)) . '@example.test',
            'password' => 'test-password-2026', 'role' => 'ADMIN', 'ativo' => true]);
        $users->saveOrFail($u); $users->updateAll(['must_change_password' => false], ['id' => $u->id]); $this->actor = (int)$u->id;
        $this->responsible = $this->nc->saveResponsible($this->actor, ['name' => 'José da Silva', 'email' => 'jose@example.test', 'active' => '1']);
        $this->ncId = $this->nc->saveNc($this->actor, ['number' => '001', 'unit' => 'Servlasa/ Lasa', 'origin' => 'Auditoria Interna', 'occurred_on' => '2026-10-30', 'description' => '<script>NC</script>']);
    }
    private function clean(): void
    {
        foreach (['NonconformityEmailQueue', 'NonconformityNotifications', 'NonconformityAuditEvents', 'NonconformityActions', 'Nonconformities', 'NonconformityResponsibles'] as $t) $this->nc->table($t)->deleteAll([]);
        $this->nc->table('NonconformityManagerAssignments')->updateAll(['user_id' => null, 'revision' => 0], ['id' => 1]);
    }
    protected function tearDown(): void
    {
        $this->clean(); $this->nc->table('Users')->deleteAll(['id' => $this->actor]);
        Configure::write('NonconformityMail.enabled', false); parent::tearDown();
    }
    private function action(array $extra = []): int
    {
        return $this->nc->saveAction($this->actor, $this->ncId, $extra + ['item' => bin2hex(random_bytes(3)), 'measure' => 'Correção', 'description' => 'Corrigir <falha>', 'responsible_id' => $this->responsible, 'status' => 'in_progress', 'due_on' => '2030-11-20']);
    }
    private function update(int $id, array $data): void
    {
        $a = $this->nc->table('NonconformityActions')->get($id)->toArray();
        $a['due_on'] = NonconformityService::iso($a['due_on']);
        $a['completed_on'] = NonconformityService::iso($a['completed_on']);
        $this->nc->saveAction($this->actor, $this->ncId, array_replace($a, $data), $id);
    }
    public function testAssignmentIsQueuedOnlyAndDuplicateNameIsRejected(): void
    {
        $this->action();
        $q = $this->nc->table('NonconformityEmailQueue')->find()->firstOrFail();
        self::assertSame('assigned', $q->event); self::assertSame('pending', $q->status); self::assertNull($q->sent_at);
        $this->expectException(UnprocessableContentException::class);
        $this->nc->saveResponsible($this->actor, ['name' => '  JOSE   DA SILVA ', 'email' => 'jose@example.test', 'active' => '1']);
    }
    public function testWithoutEmailAndLegacyNamePreservation(): void
    {
        $r = $this->nc->saveResponsible($this->actor, ['name' => 'Sem e-mail', 'email' => 'legado@example.test', 'active' => '1']);
        $old = $this->action(['responsible_id' => $r]);
        $this->nc->table('NonconformityEmailQueue')->deleteAll([]);
        // Simulate a preserved record created before e-mail became mandatory.
        $this->nc->table('NonconformityResponsibles')->updateAll(['email' => null], ['id' => $r]);
        try { $this->action(['responsible_id' => $r]); self::fail('Legacy contact assigned without e-mail'); } catch (UnprocessableContentException) {}
        $this->update($old, ['notes' => 'Histórico preservado']);
        $id = $this->action(['responsible_id' => null, 'responsible_name' => 'Nome importado']);
        self::assertSame(0, $this->nc->table('NonconformityEmailQueue')->find()->count());
        $this->update($id, ['responsible_id' => $this->responsible]);
        self::assertSame('Nome importado', $this->nc->table('NonconformityActions')->get($id)->responsible_name);
        self::assertSame(1, $this->nc->table('NonconformityEmailQueue')->find()->count());
    }
    public function testEmailIsRequiredForCreateAndEditAndLegacyActivation(): void
    {
        foreach (['', 'sem-arroba', 'nome@'] as $email) {
            try { $this->nc->saveResponsible($this->actor, ['name' => 'Teste obrigatório', 'email' => $email, 'active' => '1']); self::fail('Invalid e-mail accepted'); }
            catch (UnprocessableContentException $e) { self::assertStringContainsString('e-mail', $e->getMessage()); }
        }
        $t = $this->nc->table('NonconformityResponsibles');
        $t->updateAll(['email' => null, 'active' => false], ['id' => $this->responsible]);
        $r = $t->get($this->responsible)->toArray();
        try { $this->nc->saveResponsible($this->actor, array_replace($r, ['active' => '1']), $this->responsible); self::fail('Legacy activated without e-mail'); } catch (UnprocessableContentException) {}
        self::assertFalse($t->get($this->responsible)->active);
        $this->nc->saveResponsible($this->actor, array_replace($r, ['active' => '1', 'email' => 'corrigido@example.test']), $this->responsible);
        self::assertSame('corrigido@example.test', $t->get($this->responsible)->email);
        self::assertSame('José da Silva', $t->get($this->responsible)->name);
    }
    public function testDeadlineEventsAreIdempotentAndOverdueIsWeekly(): void
    {
        $this->action();
        foreach (['2030-11-15' => 'five_days', '2030-11-19' => 'one_day', '2030-11-20' => 'today', '2030-11-21' => 'overdue'] as $date => $event) {
            self::assertSame(1, $this->mail->generate($date)); self::assertSame(0, $this->mail->generate($date));
            self::assertTrue($this->nc->table('NonconformityEmailQueue')->exists(['event' => $event]));
        }
        self::assertSame(0, $this->mail->generate('2030-11-27'));
        // An unsent overdue message must not accumulate more reminders behind it.
        self::assertSame(0, $this->mail->generate('2030-11-28'));
        $this->nc->table('NonconformityEmailQueue')->updateAll(['status' => 'sent', 'sent_at' => '2030-11-21 09:00:00'], ['event' => 'overdue']);
        self::assertSame(1, $this->mail->generate('2030-11-28'));
    }
    public function testNewAssignmentSuppressesSameDayReminderAndCompletionCancels(): void
    {
        $id = $this->action(['due_on' => $this->nc->today()]);
        self::assertSame(0, $this->mail->generate());
        $this->update($id, ['status' => 'completed', 'completed_on' => $this->nc->today()]);
        self::assertSame(0, $this->mail->generate());
        self::assertSame(0, $this->mail->process(100, static function () { self::fail('Completed action sent'); })['sent']);
        self::assertTrue($this->nc->table('NonconformityEmailQueue')->exists(['status' => 'cancelled']));
    }
    public function testReassignmentAndDateChangeCancelObsoleteMessages(): void
    {
        $id = $this->action();
        $new = $this->nc->saveResponsible($this->actor, ['name' => 'Outra pessoa', 'email' => 'outra@example.test', 'active' => '1']);
        $this->update($id, ['responsible_id' => $new]);
        $calls = 0;
        $result = $this->mail->process(100, static function ($row) use (&$calls) { $calls++; self::assertSame('outra@example.test', $row->recipient); });
        self::assertSame(1, $result['sent']); self::assertSame(1, $calls);
        self::assertSame(0, $this->mail->process(100, static function () { self::fail('Duplicate'); })['sent']);
        self::assertSame(1, $this->mail->generate('2030-11-15'));
        $this->update($id, ['due_on' => '2030-12-20']);
        self::assertSame(0, $this->nc->table('NonconformityEmailQueue')->find()->where(['status' => 'pending'])->count());
        self::assertSame(1, $this->mail->generate('2030-12-15'));
    }
    public function testConnectionFailureRetriesAndAmbiguousFailureDoesNot(): void
    {
        $this->action(); $q = $this->nc->table('NonconformityEmailQueue');
        $result = $this->mail->process(100, static function () { throw new \Cake\Network\Exception\SocketException('Unable to connect to SMTP server.'); });
        self::assertSame(1, $result['failed']);
        $row = $q->find()->firstOrFail(); self::assertSame('pending', $row->status); self::assertSame(1, $row->attempts);
        self::assertSame(0, $this->mail->process(100, static function () { self::fail('Backoff ignored'); })['sent']);
        $q->updateAll(['available_at' => '2020-01-01 00:00:00'], ['id' => $row->id]);
        self::assertSame(1, $this->mail->process(100, static function () {})['sent']);
        $this->action();
        self::assertSame(1, $this->mail->process(100, static function () { throw new \RuntimeException('Timeout after DATA'); })['failed']);
        self::assertTrue($q->exists(['status' => 'uncertain']));
        self::assertSame(0, $this->mail->process(100, static function () { self::fail('Ambiguous retry'); })['sent']);
    }
    public function testClaimPreventsSecondWorkerAndSendIsOutsideTransaction(): void
    {
        $this->action(); $nested = null;
        $this->mail->process(100, function () use (&$nested) {
            self::assertFalse($this->nc->table('NonconformityEmailQueue')->getConnection()->inTransaction());
            $nested = $this->mail->process(100, static function () { self::fail('Second worker sent claimed row'); });
        });
        self::assertSame(0, $nested['sent']);
    }
    public function testOutboxRollsBackWhenAuditFails(): void
    {
        $t = $this->nc->table('NonconformityAuditEvents');
        $listener = static function () { throw new \RuntimeException('Audit unavailable'); };
        $t->getEventManager()->on('Model.beforeSave', $listener);
        try { $this->action(); self::fail('Expected rollback'); } catch (\RuntimeException) {}
        finally { $t->getEventManager()->off('Model.beforeSave', $listener); }
        self::assertSame(0, $this->nc->table('NonconformityEmailQueue')->find()->count());
        self::assertSame(0, $this->nc->table('NonconformityActions')->find()->count());
    }
    public function testMailerRendersBothFormatsUsingDebugTransport(): void
    {
        $this->action(); Configure::write('NonconformityMail.enabled', true);
        Configure::write('NonconformityMail.profile', 'nonconformity');
        Configure::write('App.fullBaseUrl', 'https://pcm.example.test');
        self::assertSame(1, $this->mail->process()['sent']);
        $this->assertMailContainsHtml('&lt;script&gt;NC&lt;/script&gt;');
        $this->assertMailContainsText('Corrigir <falha>');
        $this->assertMailContains('https://pcm.biolasa.com.br/consulta/nao-conformidades/');
        $this->assertMailSentFrom('qualidade@biolasa.com.br');
        $this->assertMailContainsHtml('BIOLASA');
    }
    public function testInactiveResponsibleAndInvalidEmailAreRejected(): void
    {
        $r = $this->nc->table('NonconformityResponsibles')->get($this->responsible)->toArray();
        $this->nc->saveResponsible($this->actor, array_replace($r, ['active' => '0']), $this->responsible);
        try { $this->action(); self::fail('Inactive assignment'); } catch (UnprocessableContentException) {}
        $this->expectException(UnprocessableContentException::class);
        $this->nc->saveResponsible($this->actor, ['name' => 'Inválido', 'email' => 'bad', 'active' => '1']);
    }
    public function testAllReminderEmailsAreDeliveredWithCurrentDates(): void
    {
        foreach ([5, 1, 0, -1] as $days) {
            $this->action(['due_on' => (new \DateTimeImmutable($this->nc->today()))->modify(sprintf('%+d days', $days))->format('Y-m-d')]);
        }
        $yesterday = (new \DateTimeImmutable($this->nc->today()))->modify('-1 day')->format('Y-m-d') . ' 09:00:00';
        $this->nc->table('NonconformityEmailQueue')->updateAll(['status' => 'sent', 'scheduled_at' => $yesterday, 'sent_at' => $yesterday], ['event' => 'assigned']);
        self::assertSame(4, $this->mail->generate());
        Configure::write('NonconformityMail.enabled', true);
        self::assertSame(4, $this->mail->process()['sent']);
        $this->assertMailCount(4);
        foreach (['Faltam 5 dias', 'Falta 1 dia', 'Vence hoje', 'Ação atrasada'] as $heading) $this->assertMailContainsHtml($heading);
    }
    public function testSmtpAuthenticationFailureAndTemporaryRejectionAreSafe(): void
    {
        $this->action(); $q = $this->nc->table('NonconformityEmailQueue');
        $this->mail->process(100, static function () { throw new \App\Mailer\Exception\NonconformityDeliveryException('Falha de autenticação SMTP. Verifique SMTP AUTH com a TI.'); });
        $row = $q->find()->firstOrFail(); self::assertSame('failed', $row->status); self::assertSame(1, $row->attempts);
        self::assertStringContainsString('SMTP AUTH', $row->error);
        $this->action();
        $this->mail->process(100, static function () { throw new \App\Mailer\Exception\NonconformityDeliveryException('Rejeição SMTP temporária.', retryable: true, retryAfter: 1200); });
        $row = $q->find()->where(['status' => 'pending'])->firstOrFail();
        self::assertGreaterThan(time() + 1100, strtotime($row->available_at->format('Y-m-d H:i:s') . ' America/Sao_Paulo'));
    }
    public function testFiveTemplatesRenderOfflineWithoutCredentials(): void
    {
        foreach (NonconformityEmailService::MESSAGES as $event => $meta) {
            $mailer = $this->mail->testMessage('teste@example.test', $event); $mailer->render();
            $html = $mailer->getMessage()->getBodyHtml(); $text = $mailer->getMessage()->getBodyText();
            foreach (['BIOLASA', $meta['heading'], 'TESTE CONTROLADO', 'Destinatário de teste', 'Ação corretiva', 'Consultar ação no PCM'] as $part) self::assertStringContainsString($part, $html);
            self::assertStringContainsString($meta['heading'], $text);
            self::assertStringContainsString('Prazo final', $text);
            self::assertStringContainsString('href="https://pcm.biolasa.com.br/consulta/nao-conformidades/', $html);
            self::assertStringContainsString('https://pcm.biolasa.com.br/consulta/nao-conformidades/', $text);
            self::assertSame(['qualidade@biolasa.com.br'], array_keys($mailer->getMessage()->getFrom()));
            self::assertSame(0, $this->nc->table('NonconformityEmailQueue')->find()->count());
        }
    }
    public function testAuthenticationFailureStopsBatchWithoutTryingAllRecipients(): void
    {
        $this->action(); $this->action(); $calls = 0;
        $result = $this->mail->process(100, static function () use (&$calls) {
            $calls++;
            throw new \App\Mailer\Exception\NonconformityDeliveryException('Falha de autenticação SMTP.', haltBatch: true);
        });
        self::assertSame(1, $calls); self::assertSame(1, $result['failed']);
        self::assertSame(1, $this->nc->table('NonconformityEmailQueue')->find()->where(['status' => 'pending', 'attempts' => 0])->count());
    }
    public function testNotificationDoesNotInheritAdditionalRecipientsFromProfile(): void
    {
        $original = \Cake\Mailer\Mailer::getConfig('nonconformity');
        \Cake\Mailer\Mailer::drop('nonconformity');
        \Cake\Mailer\Mailer::setConfig('nonconformity', array_replace($original, ['cc' => 'extra@example.test', 'bcc' => 'oculto@example.test']));
        try {
            $message = $this->mail->testMessage('unico@example.test')->getMessage();
            self::assertSame(['unico@example.test'], array_keys($message->getTo()));
            self::assertSame([], $message->getCc()); self::assertSame([], $message->getBcc());
        } finally {
            \Cake\Mailer\Mailer::drop('nonconformity'); \Cake\Mailer\Mailer::setConfig('nonconformity', $original);
        }
    }
    private function assignedYesterday(): void
    {
        $yesterday = (new \DateTimeImmutable($this->nc->today()))->modify('-1 day')->format('Y-m-d') . ' 09:00:00';
        $this->nc->table('NonconformityEmailQueue')->updateAll(['status' => 'sent', 'scheduled_at' => $yesterday, 'sent_at' => $yesterday], ['event' => 'assigned']);
    }
    public function testRequiredDeadlineScenariosAndRepeatedCronAreIndependentPerAction(): void
    {
        $today = $this->nc->today(); $ids = [];
        foreach ([5 => 'five_days', 1 => 'one_day', 0 => 'today', -1 => 'overdue', -8 => 'overdue'] as $days => $event) {
            $id = $this->action(['due_on' => (new \DateTimeImmutable($today))->modify(sprintf('%+d days', $days))->format('Y-m-d')]);
            $ids[$id] = $event;
            if ($days === -8) {
                $previous = (new \DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
                $this->mail->enqueue($this->nc->table('NonconformityActions')->get($id), 'overdue', $previous);
                $this->nc->table('NonconformityEmailQueue')->updateAll(['status' => 'sent', 'sent_at' => $previous . ' 09:00:00'], ['action_id' => $id, 'event' => 'overdue']);
            }
        }
        $this->assignedYesterday();
        self::assertSame(5, $this->mail->generate()); self::assertSame(0, $this->mail->generate());
        $sent = [];
        $result = $this->mail->process(100, static function ($row) use (&$sent, $ids) { $sent[(int)$row->action_id] = $row->event; self::assertSame($ids[(int)$row->action_id], $row->event); });
        self::assertSame(5, $result['sent']); self::assertCount(5, $sent);
        self::assertSame(0, $this->mail->generate());
        self::assertSame(0, $this->mail->process(100, static function () { self::fail('Cron duplicated sent event'); })['sent']);
    }
    public function testReopeningDoesNotRepeatSentWarningForSameDeadline(): void
    {
        $due = (new \DateTimeImmutable($this->nc->today()))->modify('+5 days')->format('Y-m-d');
        $id = $this->action(['due_on' => $due]); $this->assignedYesterday();
        self::assertSame(1, $this->mail->generate());
        self::assertSame(1, $this->mail->process(100, static function () {})['sent']);
        $this->update($id, ['status' => 'completed', 'completed_on' => $this->nc->today()]);
        $this->update($id, ['status' => 'in_progress', 'completed_on' => null]);
        self::assertSame(0, $this->mail->generate());
        $new = (new \DateTimeImmutable($this->nc->today()))->modify('+1 day')->format('Y-m-d');
        $this->update($id, ['due_on' => $new]); self::assertSame(1, $this->mail->generate());
        $this->update($id, ['due_on' => $due]); self::assertSame(0, $this->mail->generate());
    }
    public function testCompletionAfterClaimIsRecheckedBeforeTransport(): void
    {
        $id = $this->action(); $actions = $this->nc->table('NonconformityActions'); $once = false;
        $listener = function () use ($actions, $id, &$once) {
            if ($once) return; $once = true;
            // The worker already claimed its message. Simulate a committed change before the final read.
            $actions->updateAll(['status' => 'completed', 'completed_on' => $this->nc->today()], ['id' => $id]);
        };
        $actions->getEventManager()->on('Model.beforeFind', $listener);
        try {
            $result = $this->mail->process(100, static function () { self::fail('Completed action sent after claim'); });
            self::assertSame(1, $result['cancelled']); self::assertSame(0, $result['sent']); self::assertTrue($once);
        } finally { $actions->getEventManager()->off('Model.beforeFind', $listener); }
    }
    public function testTwoActionsOfSameNcSendExclusivelyToTheirSelectedResponsible(): void
    {
        $other = $this->nc->saveResponsible($this->actor, ['name' => 'Responsável B', 'email' => 'b@example.test', 'active' => '1']);
        $a = $this->action(['due_on' => (new \DateTimeImmutable($this->nc->today()))->modify('+5 days')->format('Y-m-d')]);
        $b = $this->action(['responsible_id' => $other, 'due_on' => (new \DateTimeImmutable($this->nc->today()))->modify('+1 day')->format('Y-m-d')]);
        $expected = [$a => 'jose@example.test', $b => 'b@example.test'];
        $check = function ($row, $action, $responsible, $nc) use ($expected) {
            self::assertSame($this->ncId, (int)$nc->id); self::assertSame($expected[(int)$action->id], $row->recipient);
            $message = $this->mail->createMessage($row, $action, $responsible, $nc)->getMessage();
            self::assertSame([$expected[(int)$action->id]], array_keys($message->getTo()));
            self::assertSame([], $message->getCc()); self::assertSame([], $message->getBcc());
        };
        self::assertSame(2, $this->mail->process(100, $check)['sent']);
        $this->assignedYesterday(); self::assertSame(2, $this->mail->generate());
        self::assertSame(2, $this->mail->process(100, $check)['sent']);
    }
    public function testTimezoneIsBrasiliaEvenWhenProcessUsesAnotherTimezone(): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Kiritimati');
            self::assertSame((new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'), $this->nc->today());
            self::assertSame('America/Sao_Paulo', $this->mail->report()['timezone']);
        } finally { date_default_timezone_set($original); }
    }
    public function testCompletedBeforeDeadlineNeverQueuesAnyEmail(): void
    {
        $this->action(['due_on' => (new \DateTimeImmutable($this->nc->today()))->modify('+5 days')->format('Y-m-d'), 'status' => 'completed', 'completed_on' => $this->nc->today()]);
        self::assertSame(0, $this->mail->generate());
        self::assertSame(0, $this->nc->table('NonconformityEmailQueue')->find()->count());
        self::assertSame(0, $this->mail->process(100, static function () { self::fail('Completed before deadline sent'); })['sent']);
    }
    public function testResponsibleChangedAfterClaimCannotReceiveOldMessageAndIsAudited(): void
    {
        $id = $this->action();
        $other = $this->nc->saveResponsible($this->actor, ['name' => 'Novo destinatário', 'email' => 'novo@example.test', 'active' => '1']);
        $actions = $this->nc->table('NonconformityActions'); $once = false;
        $listener = function () use ($id, $other, &$once) {
            if ($once) return; $once = true;
            $this->update($id, ['responsible_id' => $other]);
        };
        $actions->getEventManager()->on('Model.beforeFind', $listener);
        try {
            $result = $this->mail->process(100, static function () { self::fail('Previous responsible received claimed email'); });
            self::assertSame(1, $result['cancelled']);
        } finally { $actions->getEventManager()->off('Model.beforeFind', $listener); }
        self::assertSame(1, $this->mail->process(100, static function ($row) { self::assertSame('novo@example.test', $row->recipient); })['sent']);
        $audit = $this->nc->table('NonconformityAuditEvents')->find()->where(['object_type' => 'action', 'object_id' => $id, 'operation' => 'update'])->firstOrFail();
        self::assertSame($this->responsible, (int)json_decode($audit->before_json, true)['responsible_id']);
        self::assertSame($other, (int)json_decode($audit->after_json, true)['responsible_id']);
    }
    public function testPreviouslyQueuedDuplicateIsCancelledAtDispatch(): void
    {
        $this->action(['due_on' => (new \DateTimeImmutable($this->nc->today()))->modify('+5 days')->format('Y-m-d')]);
        $this->assignedYesterday(); self::assertSame(1, $this->mail->generate());
        $q = $this->nc->table('NonconformityEmailQueue');
        $original = $q->find()->where(['event' => 'five_days'])->firstOrFail()->toArray();
        $q->updateAll(['status' => 'sent', 'sent_at' => $this->nc->today() . ' 09:00:00'], ['id' => $original['id']]);
        unset($original['id']); $original['event_key'] = hash('sha256', 'legacy-duplicate');
        $q->saveOrFail($q->newEntity($original, ['validate' => false]));
        $result = $this->mail->process(100, static function () { self::fail('Legacy duplicate sent'); });
        self::assertSame(0, $result['sent']); self::assertSame(1, $result['cancelled']);
    }
    public function testAccumulatedOverdueMessagesRespectSevenDaysAtDispatch(): void
    {
        $id = $this->action(['due_on' => (new \DateTimeImmutable($this->nc->today()))->modify('-8 days')->format('Y-m-d')]);
        $this->assignedYesterday();
        $action = $this->nc->table('NonconformityActions')->get($id);
        $this->mail->enqueue($action, 'overdue', (new \DateTimeImmutable($this->nc->today()))->modify('-7 days')->format('Y-m-d'));
        // Simulate two pending reminders retained from an earlier worker version/outage.
        $this->mail->enqueue($action, 'overdue');
        $result = $this->mail->process(100, static function () {});
        self::assertSame(1, $result['sent']); self::assertSame(1, $result['cancelled']);
    }
}
