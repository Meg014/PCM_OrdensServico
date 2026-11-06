<?php
declare(strict_types=1);
namespace App\Test\TestCase\Command;

use App\Service\NonconformityService;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\TestCase;

final class NonconformityAlertsCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use EmailTrait;
    private NonconformityService $nc;
    private int $actor;
    protected function setUp(): void
    {
        parent::setUp(); $this->nc = new NonconformityService();
        $users = $this->nc->table('Users');
        $u = $users->newEntity(['nome' => 'Teste cron', 'email' => bin2hex(random_bytes(8)) . '@example.test', 'password' => 'test-password-2026', 'role' => 'ADMIN', 'ativo' => true]);
        $users->saveOrFail($u); $users->updateAll(['must_change_password' => false], ['id' => $u->id]); $this->actor = (int)$u->id;
        $r = $this->nc->saveResponsible($this->actor, ['name' => 'Responsável cron', 'email' => 'cron@example.test', 'active' => '1']);
        $nc = $this->nc->saveNc($this->actor, ['unit' => 'Servlasa/ Lasa', 'origin' => 'Auditoria Interna', 'occurred_on' => $this->nc->today(), 'description' => 'CLI sem acesso web']);
        $this->nc->saveAction($this->actor, $nc, ['measure' => 'Correção', 'description' => 'Validar cron', 'responsible_id' => $r, 'status' => 'in_progress',
            'due_on' => (new \DateTimeImmutable($this->nc->today()))->modify('+5 days')->format('Y-m-d')]);
        $past = (new \DateTimeImmutable($this->nc->today()))->modify('-1 day')->format('Y-m-d') . ' 09:00:00';
        $this->nc->table('NonconformityEmailQueue')->updateAll(['status' => 'sent', 'scheduled_at' => $past, 'sent_at' => $past], ['event' => 'assigned']);
        Configure::write('NonconformityMail.enabled', true);
    }
    protected function tearDown(): void
    {
        foreach (['NonconformityEmailQueue', 'NonconformityNotifications', 'NonconformityAuditEvents', 'NonconformityActions', 'Nonconformities', 'NonconformityResponsibles'] as $t) $this->nc->table($t)->deleteAll([]);
        $this->nc->table('Users')->deleteAll(['id' => $this->actor]);
        Configure::write('NonconformityMail.enabled', false); parent::tearDown();
    }
    public function testDryRunIsReadOnlyEvenWhenSendingIsEnabled(): void
    {
        $before = $this->nc->table('NonconformityEmailQueue')->find()->count();
        $this->exec('nonconformity_alerts --dry-run'); $this->assertExitSuccess();
        $this->assertOutputContains('America/Sao_Paulo'); $this->assertNoMailSent();
        self::assertSame($before, $this->nc->table('NonconformityEmailQueue')->find()->count());
    }
    public function testCronGeneratesAndSendsReminderWithoutAnHttpRequestAndDoesNotRepeat(): void
    {
        $this->exec('nonconformity_alerts --limit 100'); $this->assertExitSuccess(); $this->assertMailCount(1);
        $this->assertMailSentTo('cron@example.test'); $this->assertMailContainsHtml('Faltam 5 dias');
        $this->exec('nonconformity_alerts --limit 100'); $this->assertExitSuccess(); $this->assertMailCount(1);
        self::assertSame(1, $this->nc->table('NonconformityEmailQueue')->find()->where(['event' => 'five_days'])->count());
    }
    public function testQueueOnlyNeverSendsEvenWhenEnabled(): void
    {
        $this->exec('nonconformity_alerts --queue-only'); $this->assertExitSuccess(); $this->assertNoMailSent();
        self::assertSame(1, $this->nc->table('NonconformityEmailQueue')->find()->where(['event' => 'five_days', 'status' => 'pending'])->count());
    }
    public function testInvalidLimitIsRejectedWithoutSending(): void
    {
        $this->exec('nonconformity_alerts --limit 0'); $this->assertExitError(); $this->assertNoMailSent();
    }
}
