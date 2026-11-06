<?php
declare(strict_types=1);
namespace App\Test\TestCase\Controller;

use App\Service\NonconformityConsultationService as Consult;
use App\Service\NonconformityEmailService;
use App\Service\NonconformityService;
use App\Test\TestCase\Support\AuthenticatedUserTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Security;

final class NonconformityConsultationsControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AuthenticatedUserTrait { setUp as protected loginSetup; tearDown as protected loginTeardown; }
    private NonconformityService $nc;
    private int $ncId;
    private int $actionId;
    private int $responsibleId;
    private string $path;

    protected function setUp(): void
    {
        $this->loginSetup(); $this->nc = new NonconformityService();
        $this->ncId = $this->nc->saveNc($this->authenticatedUserId, ['number' => 'NC-VISIVEL', 'unit' => 'Usina/ Fábrica', 'origin' => 'Auditoria Interna', 'occurred_on' => '2026-10-30', 'description' => 'Descrição permitida <script>alert(1)</script>']);
        $this->responsibleId = $this->nc->saveResponsible($this->authenticatedUserId, ['name' => 'Maria Destinatária', 'email' => 'maria@example.test', 'active' => '1']);
        $this->actionId = $this->action($this->ncId, $this->responsibleId, 'ATIVIDADE-PERMITIDA');
        $other = $this->nc->saveResponsible($this->authenticatedUserId, ['name' => 'Outro Destinatário', 'email' => 'outro@example.test', 'active' => '1']);
        $this->action($this->ncId, $other, 'SEGREDO-OUTRO-RESPONSAVEL');
        $anotherNc = $this->nc->saveNc($this->authenticatedUserId, ['number' => 'NC-SECRETA', 'unit' => 'Servlasa/ Lasa', 'origin' => 'Auditoria Interna', 'occurred_on' => '2026-10-30']);
        $this->action($anotherNc, $this->responsibleId, 'SEGREDO-OUTRA-NC');
        $this->path = $this->link();
        $this->session(['Auth' => null]);
    }
    private function action(int $nc, int $responsible, string $description): int
    {
        return $this->nc->saveAction($this->authenticatedUserId, $nc, ['measure' => 'Ação Corretiva', 'description' => $description, 'responsible_id' => $responsible, 'status' => 'in_progress', 'due_on' => $this->nc->today()]);
    }
    private function link(): string
    {
        $url = (new Consult())->link($this->nc->table('NonconformityActions')->get($this->actionId), $this->nc->table('NonconformityResponsibles')->get($this->responsibleId));
        return substr($url, strlen(Consult::BASE_URL));
    }
    protected function tearDown(): void
    {
        foreach (['NonconformityEmailQueue', 'NonconformityNotifications', 'NonconformityAuditEvents', 'NonconformityActions', 'Nonconformities', 'NonconformityResponsibles'] as $table) $this->nc->table($table)->deleteAll([]);
        $this->loginTeardown();
    }
    public function testAnonymousReadsOnlyTheLinkedActionWithoutAdministrativeControls(): void
    {
        $this->get($this->path); $this->assertResponseOk();
        $this->assertResponseContains('/img/pcm-favicon.png');
        $this->assertResponseContains('pcm-nc-consultation-theme');
        $this->assertResponseContains('pcm-nc-public-results');
        $this->assertResponseContains('pcm-nc-table-scroll');
        if (getenv('NC_VISUAL_EXPORT') === '1') {
            $dir = TMP . 'nc-visual'; if (!is_dir($dir)) mkdir($dir, 0775, true);
            $html = preg_replace('~(href|src)="/(css|js|img)/~', '$1="http://localhost/PCM_OrdensServico/$2/', (string)$this->_response->getBody());
            file_put_contents($dir . DS . 'consulta-publica.html', $html);
        }
        foreach (['NC-VISIVEL', 'ATIVIDADE-PERMITIDA', 'Maria Destinatária', 'Ação Corretiva', 'Usina/ Fábrica', '&lt;script&gt;alert(1)&lt;/script&gt;'] as $text) $this->assertResponseContains($text);
        foreach (['SEGREDO-OUTRO-RESPONSAVEL', 'SEGREDO-OUTRA-NC', 'NC-SECRETA', 'outro@example.test', 'Salvar linha', 'Editar NC', 'Detalhes e histórico', 'data-nc-notifications', 'navbar-brand'] as $text) $this->assertResponseNotContains($text);
        $this->assertHeader('Referrer-Policy', 'no-referrer'); $this->assertHeader('Cache-Control', 'no-store, private');
        $this->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get($this->path . '?q=SEGREDO-OUTRO-RESPONSAVEL&responsible_id=2&nonconformity_id=2');
        $this->assertResponseOk(); $this->assertResponseContains('Nenhum registro encontrado.'); $this->assertResponseNotContains('ATIVIDADE-PERMITIDA');
    }
    public function testFiltersAreScopedAndDoNotBroadenAccess(): void
    {
        $this->get($this->path . '?status=completed'); $this->assertResponseOk(); $this->assertResponseNotContains('ATIVIDADE-PERMITIDA');
        $this->get($this->path . '?deadline=today'); $this->assertResponseOk(); $this->assertResponseContains('ATIVIDADE-PERMITIDA');
        $this->get($this->path . '?q[]=x'); $this->assertResponseCode(400);
    }
    public function testInvalidTamperedAndExpiredTokensAreRejected(): void
    {
        $token = basename($this->path);
        $replacement = substr($token, -1) === '0' ? '1' : '0';
        $this->get(Consult::PATH . substr($token, 0, -1) . $replacement); $this->assertResponseCode(404);
        [$body] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $claims['exp'] = time() - 1;
        $expired = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', 'nc-consultation-v1|' . $expired, Security::getSalt());
        $this->get(Consult::PATH . $expired . '.' . $signature); $this->assertResponseCode(404);
        $this->get(Consult::PATH . 'invalido'); $this->assertResponseCode(404);
    }
    public function testTokenCannotWriteOrAuthenticateToOtherModules(): void
    {
        $before = $this->nc->table('NonconformityActions')->get($this->actionId)->toArray();
        foreach (['post', 'patch', 'delete'] as $method) {
            $this->enableCsrfToken(); $this->$method($this->path, ['status' => 'completed', 'responsible_id' => 999]);
            self::assertContains($this->_response->getStatusCode(), [404, 405]);
        }
        self::assertEquals($before, $this->nc->table('NonconformityActions')->get($this->actionId)->toArray());
        $this->get('/pcm?token=' . rawurlencode(basename($this->path))); $this->assertRedirectContains('/login');
        $this->get('/pcm/nao-conformidades/' . $this->ncId . '/editar?token=' . rawurlencode(basename($this->path))); $this->assertRedirectContains('/login');
    }
    public function testChangingResponsibleOrEmailInvalidatesPreviouslyIssuedLinks(): void
    {
        $this->nc->saveResponsible($this->authenticatedUserId, ['revision' => 0, 'name' => 'Maria Destinatária', 'email' => 'nova@example.test', 'active' => '1'], $this->responsibleId);
        $this->get($this->path); $this->assertResponseCode(404);
        $newPath = $this->link(); $this->get($newPath); $this->assertResponseOk();
        $this->nc->table('NonconformityActions')->updateAll(['assignment_cycle' => 2], ['id' => $this->actionId]);
        $this->get($newPath); $this->assertResponseCode(404);
    }
    public function testAllFiveEmailLinksResolveToTheCorrectActionWithoutLocalhost(): void
    {
        $a = $this->nc->table('NonconformityActions')->get($this->actionId);
        $r = $this->nc->table('NonconformityResponsibles')->get($this->responsibleId);
        $n = $this->nc->table('Nonconformities')->get($this->ncId);
        foreach (NonconformityEmailService::MESSAGES as $event => $_) {
            $row = new \Cake\ORM\Entity(['recipient' => $r->email, 'event' => $event, 'event_key' => hash('sha256', $event)]);
            $mailer = (new NonconformityEmailService())->createMessage($row, $a, $r, $n); $mailer->render();
            $html = $mailer->getMessage()->getBodyHtml(); $text = $mailer->getMessage()->getBodyText();
            self::assertMatchesRegularExpression('~href="(https://pcm\.biolasa\.com\.br/consulta/nao-conformidades/[A-Za-z0-9_-]+\.[a-f0-9]{64})"~', $html);
            preg_match('~href="(https://pcm\.biolasa\.com\.br/consulta/nao-conformidades/[A-Za-z0-9_-]+\.[a-f0-9]{64})"~', $html, $match);
            self::assertStringContainsString('<v:roundrect', $html);
            self::assertStringContainsString('<w:anchorlock/>', $html);
            self::assertSame(2, substr_count($html, 'href="' . $match[1] . '"'));
            self::assertStringContainsString('border:16px solid #07808b', $html);
            if (getenv('NC_VISUAL_EXPORT') === '1') file_put_contents(TMP . 'nc-visual' . DS . 'email-final-' . $event . '.html', $html);
            self::assertStringContainsString($match[1], $text); self::assertStringNotContainsString('localhost', $html);
            self::assertSame($this->actionId, (int)(new Consult())->resolve(basename($match[1]))['action']->id);
            $this->get(substr($match[1], strlen(Consult::BASE_URL))); $this->assertResponseOk();
        }
    }
}
