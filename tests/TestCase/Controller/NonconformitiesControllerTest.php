<?php
declare(strict_types=1);
namespace App\Test\TestCase\Controller;

use App\Service\NonconformityService;
use App\Test\TestCase\Support\AuthenticatedUserTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class NonconformitiesControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AuthenticatedUserTrait { setUp as protected loginSetup; tearDown as protected loginTeardown; }
    private NonconformityService $s;
    private array $extraUsers = [];
    protected function setUp(): void { $this->loginSetup(); $this->s = new NonconformityService(); }
    protected function tearDown(): void
    {
        foreach (['NonconformityNotifications', 'NonconformityAuditEvents', 'NonconformityActions', 'Nonconformities'] as $t) $this->s->table($t)->deleteAll([]);
        $this->s->table('NonconformityManagerAssignments')->updateAll(['user_id' => null, 'revision' => 0], ['id' => 1]);
        if ($this->extraUsers) $this->s->table('Users')->deleteAll(['id IN' => $this->extraUsers]);
        $this->loginTeardown();
    }
    private function ordinary(string $role = 'USUARIO'): \Cake\Datasource\EntityInterface
    {
        $t = $this->s->table('Users'); $u = $t->newEntity(['nome' => 'Leitor NC', 'email' => bin2hex(random_bytes(8)) . '@example.com', 'password' => 'test-password-2026', 'role' => $role, 'ativo' => true]);
        $t->saveOrFail($u); $t->updateAll(['must_change_password' => false], ['id' => $u->id]); $this->extraUsers[] = (int)$u->id; return $t->get($u->id);
    }
    private function createNc(): int { return $this->s->saveNc($this->authenticatedUserId, ['unit' => 'Unidade NC', 'origin' => 'Auditoria Interna', 'occurred_on' => '2026-10-30', 'description' => '<script>teste</script>']); }
    public function testAdminCreatesNcAndActionAndCanReadAudit(): void
    {
        $this->get('/pcm/nao-conformidades'); $this->assertResponseOk(); $this->assertResponseContains('Nova Não Conformidade');
        $this->enableCsrfToken();
        $this->post('/pcm/nao-conformidades/nova', ['unit' => 'Servlasa', 'origin' => 'Auditoria Interna', 'occurred_on' => '2026-10-30', 'description' => 'Teste NC']);
        $this->assertResponseCode(302);
        $nc = $this->s->table('Nonconformities')->find()->orderByDesc('id')->firstOrFail();
        $this->post('/pcm/nao-conformidades/' . $nc->id . '/acoes/nova', ['description' => 'Ação teste', 'measure' => 'Correção', 'responsible_name' => 'Responsável', 'status' => 'in_progress', 'due_on' => $this->s->today()]);
        $this->assertResponseCode(302);
        $a = $this->s->table('NonconformityActions')->find()->firstOrFail();
        $this->get('/pcm/nao-conformidades/' . $nc->id); $this->assertResponseOk(); $this->assertResponseContains('Ação teste'); $this->assertResponseContains('Histórico de alterações');
        $this->get('/pcm/nao-conformidades/' . $nc->id . '/acoes/' . $a->id . '/editar'); $this->assertResponseOk();
        $this->post('/pcm/nao-conformidades/' . $nc->id . '/acoes/' . $a->id . '/editar', ['revision' => 0, 'description' => 'Ação concluída', 'measure' => 'Ação corretiva', 'responsible_name' => 'Responsável', 'status' => 'completed', 'completed_on' => $this->s->today(), 'due_on' => $this->s->today(), 'effectiveness' => 'Atende']);
        $this->assertResponseCode(302); self::assertSame('completed', $this->s->table('NonconformityActions')->get($a->id)->status);
    }
    public function testOrdinaryReadsButDirectWritesAndAssignmentAreForbidden(): void
    {
        $id = $this->createNc(); $u = $this->ordinary(); $this->session(['Auth' => $u]);
        $this->get('/pcm/nao-conformidades/' . $id); $this->assertResponseOk(); $this->assertResponseNotContains('Adicionar ação'); $this->assertResponseContains('&lt;script&gt;teste&lt;/script&gt;');
        foreach (['/pcm/nao-conformidades/nova', '/pcm/nao-conformidades/' . $id . '/editar', '/pcm/nao-conformidades/' . $id . '/acoes/nova', '/usuarios/' . $u->id . '/nao-conformidades'] as $url) {
            $this->get($url); $this->assertResponseCode(403);
            $this->enableCsrfToken(); $this->post($url, ['role' => 'ADMIN', 'revision' => 0]); $this->assertResponseCode(403);
        }
        self::assertSame(1, $this->s->table('Nonconformities')->find()->count());
        $this->get('/pcm/nao-conformidades?status=in_progress&deadline=overdue'); $this->assertResponseOk();
    }
    public function testPermissionConfirmationAndNotificationOwnership(): void
    {
        $first = $this->ordinary(); $second = $this->ordinary();
        $this->enableCsrfToken(); $this->post('/usuarios/' . $first->id . '/nao-conformidades', ['mode' => 'grant', 'revision' => 0]); $this->assertRedirect('/usuarios');
        $this->post('/usuarios/' . $second->id . '/nao-conformidades', ['mode' => 'grant', 'revision' => 1]); $this->assertResponseCode(409);
        $this->post('/usuarios/' . $second->id . '/nao-conformidades', ['mode' => 'grant', 'revision' => 1, 'confirmed' => '1']); $this->assertRedirect('/usuarios');
        $id = $this->createNc(); $a = $this->s->saveAction($this->authenticatedUserId, $id, ['description' => 'Prazo hoje', 'measure' => 'Correção', 'responsible_name' => 'Outro', 'status' => 'not_started', 'due_on' => $this->s->today()]);
        $n = $this->s->notifications((int)$second->id)['items'][0];
        $this->post('/pcm/nao-conformidades/notificacoes/' . $n->id . '/abrir'); $this->assertResponseCode(403);
        $this->session(['Auth' => $second]);
        $this->get('/pcm/nao-conformidades/notificacoes'); $this->assertResponseOk(); $this->assertResponseContains('Vence hoje');
        $this->get('/pcm/nao-conformidades/nova'); $this->assertResponseOk();
        $this->post('/pcm/nao-conformidades/notificacoes/' . $n->id . '/abrir'); $this->assertRedirect('/pcm/nao-conformidades/' . $id . '#acao-' . $a);
        self::assertSame(0, $this->s->notifications((int)$second->id)['count']);
    }
    public function testTvAndAnonymousCannotAccessModule(): void
    {
        $this->session(['Auth' => $this->ordinary('TV')]);
        $this->get('/pcm/nao-conformidades'); self::assertContains($this->_response->getStatusCode(), [302, 403]);
        $this->get('/pcm/nao-conformidades/notificacoes'); self::assertContains($this->_response->getStatusCode(), [302, 403]);
        $this->session(['Auth' => null]); $this->get('/pcm/nao-conformidades'); $this->assertRedirectContains('/login');
    }
    public function testCsrfRejectsUnprotectedMutation(): void
    {
        $this->post('/pcm/nao-conformidades/nova', ['description' => 'Sem CSRF']); $this->assertResponseCode(403);
    }
    public function testBatchFormValidationPreservesInputAndTableHasOneRowPerAction(): void
    {
        $this->enableCsrfToken();
        $input = ['form_complete'=>'1','unit'=>'Setor da planilha','origin'=>'Auditoria Interna','occurred_on'=>'30/10/2026','description'=>"Descrição\ncom linhas",'actions'=>[
            ['item'=>'01','measure'=>'Medida livre e detalhada','description'=>'Primeira ação','responsible_name'=>'Pessoa sem login','status'=>'not_started','notes'=>"Observação\nsem cortes"],
            ['item'=>'02','measure'=>'Outra medida','description'=>'Segunda ação','responsible_name'=>'Outra pessoa','status'=>'unknown'],
        ]];
        $incomplete=$input;unset($incomplete['form_complete']);$incomplete['actions'][1]['status']='in_progress';
        $this->post('/pcm/nao-conformidades/nova',$incomplete);$this->assertResponseCode(422);
        self::assertSame(0,$this->s->table('Nonconformities')->find()->count());
        $this->post('/pcm/nao-conformidades/nova',$input); $this->assertResponseCode(422); $this->assertResponseContains('Primeira ação'); $this->assertResponseContains('Segunda ação'); $this->assertResponseContains('Pessoa sem login');
        self::assertSame(0,$this->s->table('Nonconformities')->find()->count());
        $input['actions'][1]['status']='in_progress';
        $this->post('/pcm/nao-conformidades/nova',$input); $this->assertResponseCode(302);
        $this->get('/pcm/nao-conformidades'); $this->assertResponseOk();
        $html=(string)$this->_response->getBody();
        self::assertSame(2,substr_count($html,'data-nc-row')); self::assertStringNotContainsString('pcm-kpi-card',$html);
        foreach(['NC nº','Item NC','Origem','Data NC','Não conformidade','Medida','O Que?','Quem?','Quando?','Data conclusão','Realizado','Verificação da Eficácia','Observação','Unidade / Setor'] as $label) self::assertStringContainsString($label,$html);
        $this->get('/pcm/nao-conformidades?q=Segunda');$this->assertResponseOk(); self::assertSame(1,substr_count((string)$this->_response->getBody(),'data-nc-row'));
    }
    public function testQuickJsonEditIsProtectedAndKeepsLongFields(): void
    {
        $id=$this->createNc();$action=$this->s->saveAction($this->authenticatedUserId,$id,['measure'=>'Texto livre','description'=>'Texto integral','responsible_name'=>'Pessoa','status'=>'in_progress']);
        $url='/pcm/nao-conformidades/'.$id.'/acoes/'.$action.'/rapida';
        $this->enableCsrfToken();$this->configRequest(['headers'=>['Accept'=>'application/json']]);
        $this->post($url,['revision'=>0,'status'=>'completed','completed_on'=>'15/08/2025','notes'=>"Atualizado\ncom linhas"]);$this->assertResponseOk();
        $json=json_decode((string)$this->_response->getBody(),true); self::assertSame('completed',$json['tone']);self::assertSame(1,$json['revision']);
        self::assertSame('2025-08-15',$this->s::iso($this->s->table('NonconformityActions')->get($action)->completed_on));
        $this->configRequest(['headers'=>['Accept'=>'application/json']]);$this->post($url,['revision'=>0,'notes'=>'Antigo']);$this->assertResponseCode(409);
        $this->session(['Auth'=>$this->ordinary()]);$this->post($url,['revision'=>1,'notes'=>'Sem permissão']);$this->assertResponseCode(403);
        $this->get('/pcm/nao-conformidades');$this->assertResponseOk();$this->assertResponseNotContains('Salvar linha');$this->assertResponseNotContains('data-nc-quick-form');
    }
    public function testActionRowsPaginateWithoutOmissionsOrDuplicates(): void
    {
        $items=[];for($i=1;$i<=31;$i++)$items[]=['item'=>sprintf('%03d',$i),'measure'=>'Livre','description'=>'Ação '.$i,'responsible_name'=>'Pessoa','status'=>'not_started'];
        $this->s->saveComplete($this->authenticatedUserId,['unit'=>'Setor','origin'=>'Auditoria Interna','occurred_on'=>'30/10/2026','actions'=>$items]);
        $ids=[];
        foreach([1=>25,2=>6] as $page=>$expected){$this->get('/pcm/nao-conformidades?page='.$page);$this->assertResponseOk();$html=(string)$this->_response->getBody();preg_match_all('/<tr id="acao-(\d+)"/',$html,$matches);self::assertCount($expected,$matches[1]);$ids=array_merge($ids,$matches[1]);}
        self::assertCount(31,array_unique($ids));
    }
}
