<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service;

use App\Service\NonconformityService;
use Cake\TestSuite\TestCase;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\ConflictException;

final class NonconformityServiceTest extends TestCase
{
    private NonconformityService $s;
    private array $ids = [];
    protected function setUp(): void
    {
        parent::setUp(); $this->s = new NonconformityService();
        $assignments = $this->s->table('NonconformityManagerAssignments');
        if (!$assignments->exists(['id' => 1])) $assignments->saveOrFail($assignments->newEntity(['id' => 1, 'revision' => 0], ['accessibleFields' => ['id' => true]]));
        foreach (['NonconformityNotifications', 'NonconformityAuditEvents', 'NonconformityActions', 'Nonconformities'] as $t) $this->s->table($t)->deleteAll([]);
        $this->s->table('NonconformityManagerAssignments')->updateAll(['user_id' => null, 'revision' => 0], ['id' => 1]);
        foreach (['ADMIN', 'USUARIO', 'USUARIO', 'TV'] as $role) {
            $t = $this->s->table('Users'); $u = $t->newEntity(['nome' => 'NC Test', 'email' => bin2hex(random_bytes(8)) . '@example.com', 'password' => 'test-password-2026', 'role' => $role, 'ativo' => true]);
            $t->saveOrFail($u); $t->updateAll(['must_change_password' => false], ['id' => $u->id]); $this->ids[] = (int)$u->id;
        }
    }
    protected function tearDown(): void
    {
        foreach (['NonconformityNotifications', 'NonconformityAuditEvents', 'NonconformityActions', 'Nonconformities'] as $t) $this->s->table($t)->deleteAll([]);
        $this->s->table('NonconformityManagerAssignments')->updateAll(['user_id' => null, 'revision' => 0], ['id' => 1]);
        $this->s->table('Users')->deleteAll(['id IN' => $this->ids]); parent::tearDown();
    }
    private function nc(): int { return $this->s->saveNc($this->ids[0], ['unit' => 'Servlasa', 'origin' => 'Auditoria Interna', 'occurred_on' => '2026-10-30', 'description' => 'Teste']); }
    private function action(array $extra = []): int
    {
        return $this->s->saveAction($this->ids[0], $this->nc(), $extra + ['measure' => 'Correção', 'description' => 'Corrigir', 'responsible_name' => 'Pessoa sem conta', 'status' => 'in_progress', 'due_on' => $this->s->today()]);
    }
    public function testOrdinaryUserCannotWrite(): void
    {
        self::assertFalse($this->s->canWrite($this->ids[1]));
        $this->expectException(ForbiddenException::class); $this->s->saveNc($this->ids[1], []);
    }
    public function testTransferRequiresConfirmationAndRejectsStaleRevision(): void
    {
        $this->s->assign($this->ids[0], $this->ids[1], 0, false);
        try { $this->s->assign($this->ids[0], $this->ids[2], 1, false); self::fail(); } catch (ConflictException) {}
        self::assertSame($this->ids[1], (int)$this->s->assignment()->user_id);
        $this->s->assign($this->ids[0], $this->ids[2], 1, true);
        self::assertFalse($this->s->canWrite($this->ids[1])); self::assertTrue($this->s->canWrite($this->ids[2]));
        self::assertSame(1, $this->s->table('NonconformityManagerAssignments')->find()->count());
        $this->expectException(ConflictException::class); $this->s->assign($this->ids[0], $this->ids[1], 1, true);
    }
    public function testTvCannotReadOrBeManager(): void
    {
        $this->expectException(ForbiddenException::class); $this->s->assign($this->ids[0], $this->ids[3], 0, false);
    }
    public function testNotificationsDeduplicateAndStopAfterCompletion(): void
    {
        $this->s->assign($this->ids[0], $this->ids[1], 0, false); $id = $this->action();
        self::assertSame(1, $this->s->notifications($this->ids[1])['count']);
        self::assertSame(0, $this->s->notifications($this->ids[0])['count']);
        self::assertSame(0, $this->s->generate());
        $a = $this->s->table('NonconformityActions')->get($id);
        $this->s->saveAction($this->ids[1], (int)$a->nonconformity_id, ['revision' => $a->revision, 'measure' => 'Correção', 'description' => 'Feito', 'responsible_name' => 'Pessoa', 'status' => 'completed', 'due_on' => $this->s->today(), 'completed_on' => $this->s->today()], $id);
        self::assertSame(0, $this->s->notifications($this->ids[1])['count']); self::assertSame(0, $this->s->generate());
        self::assertSame(3, $this->s->table('NonconformityAuditEvents')->find()->where(['object_type IN' => ['nc', 'action']])->count());
    }
    public function testDeadlineAndStatusAreIndependent(): void
    {
        self::assertSame('Atrasada', NonconformityService::deadline('2026-10-29', 'in_progress', '2026-10-30'));
        self::assertSame('Concluída', NonconformityService::deadline('2026-10-29', 'completed', '2026-10-30'));
        $id = $this->action(); $a = $this->s->table('NonconformityActions')->get($id);
        $this->expectException(ConflictException::class); $this->s->saveAction($this->ids[0], (int)$a->nonconformity_id, ['revision' => 5], $id);
    }
    public function testAllDeadlineEventsReadingAndTransfer(): void
    {
        $this->s->assign($this->ids[0], $this->ids[1], 0, false);
        $id = $this->action(['due_on' => '2030-02-10']);
        foreach (['2030-02-07' => 'three_days', '2030-02-09' => 'one_day', '2030-02-10' => 'today', '2030-02-11' => 'overdue'] as $today => $event) {
            self::assertSame(1, $this->s->generate($today)); self::assertSame(0, $this->s->generate($today));
            self::assertTrue($this->s->table('NonconformityNotifications')->exists(['action_id' => $id, 'event' => $event]));
        }
        self::assertSame(0, $this->s->generate('2030-02-12'));
        $n = $this->s->notifications($this->ids[1])['items'][0];
        $this->s->openNotification($this->ids[1], (int)$n->id);
        self::assertSame(3, $this->s->notifications($this->ids[1])['count']);
        self::assertSame('Atrasada', NonconformityService::deadline('2030-02-10', 'in_progress', '2030-02-12'));
        $this->s->assign($this->ids[0], $this->ids[2], 1, true);
        self::assertSame(0, $this->s->notifications($this->ids[1])['count']);
        $this->expectException(ForbiddenException::class); $this->s->openNotification($this->ids[1], (int)$n->id);
    }
    public function testAuditFailureRollsBackBusinessWrite(): void
    {
        $t = $this->s->table('NonconformityAuditEvents');
        $listener = static function (): void { throw new \RuntimeException('Audit unavailable'); };
        $t->getEventManager()->on('Model.beforeSave', $listener);
        try {
            try { $this->nc(); self::fail('Expected audit failure'); } catch (\RuntimeException $e) { self::assertSame('Audit unavailable', $e->getMessage()); }
            self::assertSame(0, $this->s->table('Nonconformities')->find()->count());
        } finally { $t->getEventManager()->off('Model.beforeSave', $listener); }
    }
    public function testInactivationRevokesPermissionAndPreservesAudit(): void
    {
        $this->s->assign($this->ids[0], $this->ids[1], 0, false); $this->action();
        $u = $this->s->table('Users')->get($this->ids[1]); $u->set('ativo', false);
        $this->s->table('Users')->saveOrFail($u, ['nc_actor_id' => $this->ids[0]]);
        self::assertNull($this->s->assignment()->user_id);
        self::assertTrue($this->s->table('NonconformityAuditEvents')->exists(['operation' => 'revoke_ineligible', 'actor_id' => $this->ids[0]]));
        self::assertSame(1, $this->s->table('NonconformityActions')->find()->count());
    }
    public function testDeadlineChangesAndReopeningCreateNewCycles(): void
    {
        $this->s->assign($this->ids[0], $this->ids[1], 0, false); $id = $this->action();
        $a = $this->s->table('NonconformityActions')->get($id); $base = ['measure' => 'Correção', 'description' => 'Ação', 'responsible_name' => 'Pessoa', 'due_on' => $this->s->today()];
        $this->s->saveAction($this->ids[1], (int)$a->nonconformity_id, $base + ['revision' => 0, 'status' => 'completed', 'completed_on' => $this->s->today()], $id);
        $this->s->saveAction($this->ids[1], (int)$a->nonconformity_id, $base + ['revision' => 1, 'status' => 'not_started'], $id);
        self::assertSame(1, (int)$this->s->table('NonconformityActions')->get($id)->deadline_cycle);
        self::assertSame(1, $this->s->notifications($this->ids[1])['count']);
        self::assertSame(2, $this->s->table('NonconformityNotifications')->find()->count());
    }
    public function testCompleteFormSavesAndEditsAllSpreadsheetFieldsWithoutTruncation(): void
    {
        $long = "  Medida livre\n" . str_repeat('Texto integral com acentuação. ', 80) . "\nFim  ";
        $input = ['number' => '001-A', 'unit' => 'Setor', 'origin' => 'Auditoria Interna', 'occurred_on' => '30/10/2026', 'description' => "NC\nTexto longo",
            'actions' => [['item' => '001', 'measure' => $long, 'description' => $long, 'responsible_name' => 'Pessoa sem login', 'due_on' => '31/10/2026',
                'status' => 'completed', 'completed_on' => '01/09/2025', 'effectiveness' => "Atende\nVerificado", 'notes' => $long],
                ['item' => '002', 'measure' => 'Medida não catalogada', 'description' => 'Segundo item', 'responsible_name' => 'Outra pessoa', 'status' => 'not_started']]];
        $id = $this->s->saveComplete($this->ids[0], $input);
        $actions = $this->s->table('NonconformityActions')->find()->where(['nonconformity_id' => $id])->orderByAsc('id')->all()->toList();
        self::assertCount(2, $actions); self::assertSame('001', $actions[0]->item);
        foreach (['measure','description','notes'] as $field) self::assertSame($long, $actions[0]->get($field));
        self::assertSame('2025-09-01', $this->s::iso($actions[0]->completed_on));
        $input['revision'] = 0; $input['origin'] = 'Fiscalização'; $input['description'] = '';
        foreach ($actions as $i => $a) { $input['actions'][$i]['id'] = $a->id; $input['actions'][$i]['revision'] = 0; }
        $input['actions'][0]['notes'] = "Nova observação\ncom linhas";
        $this->s->saveComplete($this->ids[0], $input, $id);
        self::assertSame('Fiscalização', $this->s->table('Nonconformities')->get($id)->origin);
        self::assertSame('', $this->s->table('Nonconformities')->get($id)->description);
        self::assertSame("Nova observação\ncom linhas", $this->s->table('NonconformityActions')->get($actions[0]->id)->notes);
        self::assertSame('2025-09-01', $this->s::iso($this->s->table('NonconformityActions')->get($actions[0]->id)->completed_on));
        self::assertSame(6, $this->s->table('NonconformityAuditEvents')->find()->count());
    }
    public function testCompleteFormRollsBackNcActionsAuditAndAlertsOnInvalidSecondItem(): void
    {
        try {
            $this->s->saveComplete($this->ids[0], ['unit'=>'Setor','origin'=>'Auditoria Interna','occurred_on'=>'30/10/2026','actions'=>[
                ['measure'=>'Correção','description'=>'Válida','responsible_name'=>'Pessoa','status'=>'in_progress'],
                ['measure'=>'Medida','description'=>'Inválida','responsible_name'=>'Pessoa','status'=>'unknown'],
            ]]); self::fail('Expected validation failure');
        } catch (\Cake\Http\Exception\UnprocessableContentException) {}
        foreach (['Nonconformities','NonconformityActions','NonconformityAuditEvents','NonconformityNotifications'] as $table) self::assertSame(0, $this->s->table($table)->find()->count());
    }
    public function testQuickEditPreservesOtherFieldsAndHistoricalDateAndIsAudited(): void
    {
        $id = $this->action(['status'=>'completed','completed_on'=>'2025-08-15']); $a = $this->s->table('NonconformityActions')->get($id);
        $this->s->quickAction($this->ids[0], (int)$a->nonconformity_id, $id, ['revision'=>0,'notes'=>"Observação\nnova",'measure'=>'IGNORAR','effectiveness'=>'IGNORAR']);
        $updated = $this->s->table('NonconformityActions')->get($id);
        self::assertSame('Correção', $updated->measure); self::assertNull($updated->effectiveness);
        self::assertSame('2025-08-15', $this->s::iso($updated->completed_on)); self::assertSame("Observação\nnova", $updated->notes);
        self::assertSame(3, $this->s->table('NonconformityAuditEvents')->find()->count());
    }
    public function testDeadlineToneHasSeparateUrgentAndAttentionForTwoDays(): void
    {
        foreach (['2026-10-29'=>'overdue','2026-10-30'=>'today','2026-10-31'=>'urgent','2026-11-01'=>'attention','2026-11-02'=>'attention','2026-11-03'=>'on_time'] as $date=>$tone) self::assertSame($tone, $this->s::deadlineTone($date,'in_progress','2026-10-30'));
        self::assertSame('completed', $this->s::deadlineTone('2026-10-20','completed','2026-10-30'));
    }
}
