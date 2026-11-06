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
    private int $responsibleId;
    protected function setUp(): void
    {
        $this->loginSetup(); $this->s = new NonconformityService();
        $this->responsibleId = $this->s->saveResponsible($this->authenticatedUserId, ['name' => 'Pessoa sem login', 'email' => 'pessoa@example.test', 'active' => '1']);
    }
    protected function tearDown(): void
    {
        foreach (['NonconformityEmailQueue', 'NonconformityNotifications', 'NonconformityAuditEvents', 'NonconformityActions', 'Nonconformities', 'NonconformityResponsibles'] as $t) $this->s->table($t)->deleteAll([]);
        $this->s->table('NonconformityManagerAssignments')->updateAll(['user_id' => null, 'revision' => 0], ['id' => 1]);
        if ($this->extraUsers) $this->s->table('Users')->deleteAll(['id IN' => $this->extraUsers]);
        $this->loginTeardown();
    }
    private function ordinary(string $role = 'USUARIO'): \Cake\Datasource\EntityInterface
    {
        $t = $this->s->table('Users'); $u = $t->newEntity(['nome' => 'Leitor NC', 'email' => bin2hex(random_bytes(8)) . '@example.com', 'password' => 'test-password-2026', 'role' => $role, 'ativo' => true]);
        $t->saveOrFail($u); $t->updateAll(['must_change_password' => false], ['id' => $u->id]); $this->extraUsers[] = (int)$u->id; return $t->get($u->id);
    }
    private function createNc(): int { return $this->s->saveNc($this->authenticatedUserId, ['unit' => 'Servlasa/ Lasa', 'origin' => 'Auditoria Interna', 'occurred_on' => '2026-10-30', 'description' => '<script>teste</script>']); }
    public function testBrazilianDatesAndIsoSubmissionStoreTheSameDatesAndRejectInvalidCalendarDates(): void
    {
        $this->enableCsrfToken();
        $input = ['unit' => 'Usina/ Fábrica', 'origin' => 'Auditoria Interna', 'occurred_on' => '29/02/2024'];
        $this->post('/pcm/nao-conformidades/nova', $input); $this->assertResponseCode(302);
        $nc = $this->s->table('Nonconformities')->find()->firstOrFail();
        self::assertSame('2024-02-29', $this->s::iso($nc->occurred_on));
        $this->get('/pcm/nao-conformidades/' . $nc->id . '/editar'); $this->assertResponseOk();
        $this->assertResponseContains('value="29/02/2024"'); $this->assertResponseContains('data-nc-date'); $this->assertResponseContains('pcm-nonconformity-dates');
        $this->post('/pcm/nao-conformidades/nova', array_replace($input, ['occurred_on' => '31/02/2026'])); $this->assertResponseCode(422);
        $action = ['measure' => 'Correção', 'description' => 'Datas padronizadas', 'responsible_id' => $this->responsibleId, 'status' => 'in_progress', 'due_on' => '2026-11-20'];
        $url = '/pcm/nao-conformidades/' . $nc->id . '/acoes/nova';
        $this->post($url, array_replace($action, ['due_on' => '31/02/2026'])); $this->assertResponseCode(422);
        $this->post($url, $action); $this->assertResponseCode(302);
        $a = $this->s->table('NonconformityActions')->find()->firstOrFail();
        self::assertSame('2026-11-20', $this->s::iso($a->due_on));
        $edit = '/pcm/nao-conformidades/' . $nc->id . '/acoes/' . $a->id . '/editar';
        $this->get($edit); $this->assertResponseContains('value="20/11/2026"');
        $this->post($edit, array_replace($action, ['revision' => 0, 'due_on' => '20/11/2026', 'status' => 'completed', 'completed_on' => '29/02/2024'])); $this->assertResponseCode(302);
        $a = $this->s->table('NonconformityActions')->get($a->id);
        self::assertSame('2026-11-20', $this->s::iso($a->due_on)); self::assertSame('2024-02-29', $this->s::iso($a->completed_on));
    }
    public function testInlineCreationAndNormalizedSelectionReuseTheSavedResponsible(): void
    {
        $id = $this->createNc(); $url = '/pcm/nao-conformidades/' . $id . '/acoes/nova';
        $this->get($url); $this->assertResponseOk();
        $this->assertResponseContains('data-nc-contact-name'); $this->assertResponseContains('data-nc-contact-email');
        $input = ['measure' => 'Ação Corretiva', 'description' => 'Ação inline', 'responsible_name' => 'João Ávila', 'responsible_email' => 'joao@example.test', 'status' => 'in_progress'];
        $this->enableCsrfToken(); $this->post($url, $input); $this->assertResponseCode(302);
        $contact = $this->s->table('NonconformityResponsibles')->find()->where(['normalized_name' => 'joao avila'])->firstOrFail();
        self::assertSame('joao@example.test', $contact->email);
        $saved = $this->s->table('NonconformityActions')->find()->firstOrFail();
        self::assertSame((int)$contact->id, (int)$saved->responsible_id);
        $queue = $this->s->table('NonconformityEmailQueue')->find()->firstOrFail();
        self::assertSame('joao@example.test', $queue->recipient); self::assertSame('pending', $queue->status); self::assertNull($queue->sent_at);
        $this->post($url, array_replace($input, ['responsible_name' => '  JOAO   AVILA  '])); $this->assertResponseCode(302);
        self::assertSame(1, $this->s->table('NonconformityResponsibles')->find()->where(['normalized_name' => 'joao avila'])->count());
        $this->get($url); $this->assertResponseContains('data-email="joao@example.test"');
        $editUrl = '/pcm/nao-conformidades/' . $id . '/acoes/' . $saved->id . '/editar';
        $this->post($editUrl, array_replace($input, ['responsible_id' => $contact->id, 'revision' => 0, 'description' => 'Editada'])); $this->assertResponseCode(302);
        self::assertSame('Editada', $this->s->table('NonconformityActions')->get($saved->id)->description);
        self::assertSame(2, $this->s->table('NonconformityEmailQueue')->find()->count());
    }
    public function testInlineEmailValidationCannotCreateContactsOrSilentlyChangeExistingEmails(): void
    {
        $id = $this->createNc(); $url = '/pcm/nao-conformidades/' . $id . '/acoes/nova';
        $input = ['measure' => 'Correção', 'description' => 'Inválida', 'responsible_name' => 'Nova Pessoa', 'status' => 'not_started'];
        $this->enableCsrfToken();
        foreach (['', 'email-invalido'] as $email) {
            $this->post($url, $input + ['responsible_email' => $email]); $this->assertResponseCode(422);
        }
        $this->post($url, $input + ['responsible_email' => ['forjado']]); $this->assertResponseCode(422);
        $this->post($url, array_replace($input, ['responsible_name' => '', 'responsible_email' => 'novo@example.test'])); $this->assertResponseCode(422);
        $this->post($url, array_replace($input, ['responsible_name' => 'Pessoa sem login', 'responsible_email' => 'alterado@example.test', 'responsible_id' => $this->responsibleId])); $this->assertResponseCode(422);
        self::assertSame('pessoa@example.test', $this->s->table('NonconformityResponsibles')->get($this->responsibleId)->email);
        self::assertSame(1, $this->s->table('NonconformityResponsibles')->find()->count());
        self::assertSame(0, $this->s->table('NonconformityActions')->find()->count());
        // Email correction requires the existing, authorized, revision-checked administration flow.
        $this->post('/pcm/nao-conformidades/responsaveis/' . $this->responsibleId, ['revision' => 0, 'name' => 'Pessoa sem login', 'email' => 'corrigido@example.test', 'active' => '1']); $this->assertResponseCode(302);
        $this->post($url, array_replace($input, ['responsible_name' => 'Pessoa sem login', 'responsible_email' => 'corrigido@example.test', 'responsible_id' => $this->responsibleId])); $this->assertResponseCode(302);
        self::assertSame('corrigido@example.test', $this->s->table('NonconformityEmailQueue')->find()->firstOrFail()->recipient);
    }
    public function testInlineContactAndOutboxRollBackWhenAnotherItemFails(): void
    {
        $this->enableCsrfToken();
        $this->post('/pcm/nao-conformidades/nova', ['form_complete' => '1', 'unit' => 'Usina/ Fábrica', 'origin' => 'Auditoria Interna', 'occurred_on' => '30/10/2026', 'actions' => [
            ['item' => '1', 'measure' => 'Correção', 'description' => 'Primeira', 'responsible_name' => 'Responsável transacional', 'responsible_email' => 'transacao@example.test', 'status' => 'in_progress'],
            ['item' => '1', 'measure' => 'Correção', 'description' => 'Item repetido', 'responsible_name' => 'Responsável transacional', 'responsible_email' => 'transacao@example.test', 'status' => 'in_progress'],
        ]]);
        $this->assertResponseCode(422);
        foreach (['Nonconformities', 'NonconformityActions', 'NonconformityEmailQueue'] as $table) self::assertSame(0, $this->s->table($table)->find()->count());
        self::assertFalse($this->s->table('NonconformityResponsibles')->exists(['normalized_name' => 'responsavel transacional']));
    }
    public function testFixedListsRejectNewValuesAndPreserveHistoricalValuesOnEdit(): void
    {
        $this->get('/pcm/nao-conformidades/nova'); $this->assertResponseOk();
        foreach (['Servlasa/ Lasa', 'Usina/ Fábrica', 'Correção', 'Ação Corretiva'] as $label) $this->assertResponseContains($label);
        $this->enableCsrfToken();
        $ncInput = ['unit' => 'Unidade arbitrária', 'origin' => 'Auditoria Interna', 'occurred_on' => '30/10/2026'];
        $this->post('/pcm/nao-conformidades/nova', $ncInput); $this->assertResponseCode(422);
        $id = $this->createNc();
        $this->s->table('Nonconformities')->updateAll(['unit' => 'Unidade histórica'], ['id' => $id]);
        $this->get('/pcm/nao-conformidades/' . $id . '/editar'); $this->assertResponseContains('Unidade histórica (histórico — manter)');
        $this->post('/pcm/nao-conformidades/' . $id . '/editar', array_replace($ncInput, ['unit' => 'Unidade histórica', 'revision' => 0])); $this->assertResponseCode(302);
        self::assertSame('Unidade histórica', $this->s->table('Nonconformities')->get($id)->unit);
        $actionInput = ['measure' => 'Livre nova', 'description' => 'Compatibilidade', 'responsible_id' => $this->responsibleId, 'status' => 'in_progress'];
        $this->post('/pcm/nao-conformidades/' . $id . '/acoes/nova', $actionInput); $this->assertResponseCode(422);
        $actionId = $this->s->saveAction($this->authenticatedUserId, $id, array_replace($actionInput, ['measure' => 'Correção']));
        $historical = "Medida histórica\n" . str_repeat('Texto integral. ', 80);
        $this->s->table('NonconformityActions')->updateAll(['measure' => $historical], ['id' => $actionId]);
        $this->get('/pcm/nao-conformidades/' . $id . '/acoes/' . $actionId . '/editar'); $this->assertResponseContains('(histórico — manter)');
        $this->post('/pcm/nao-conformidades/' . $id . '/acoes/' . $actionId . '/editar', array_replace($actionInput, ['measure' => $historical, 'revision' => 0])); $this->assertResponseCode(302);
        self::assertSame($historical, $this->s->table('NonconformityActions')->get($actionId)->measure);
        $this->get('/pcm/nao-conformidades'); $this->assertResponseContains('Medida histórica');
    }
    public function testAdminCreatesNcAndActionAndCanReadAudit(): void
    {
        $this->get('/pcm/nao-conformidades'); $this->assertResponseOk(); $this->assertResponseContains('Nova Não Conformidade');
        $this->enableCsrfToken();
        $this->post('/pcm/nao-conformidades/nova', ['unit' => 'Servlasa/ Lasa', 'origin' => 'Auditoria Interna', 'occurred_on' => '2026-10-30', 'description' => 'Teste NC']);
        $this->assertResponseCode(302);
        $nc = $this->s->table('Nonconformities')->find()->orderByDesc('id')->firstOrFail();
        $this->post('/pcm/nao-conformidades/' . $nc->id . '/acoes/nova', ['description' => 'Ação teste', 'measure' => 'Correção', 'responsible_id' => $this->responsibleId, 'status' => 'in_progress', 'due_on' => $this->s->today()]);
        $this->assertResponseCode(302);
        $a = $this->s->table('NonconformityActions')->find()->firstOrFail();
        $this->get('/pcm/nao-conformidades/' . $nc->id); $this->assertResponseOk(); $this->assertResponseContains('Ação teste'); $this->assertResponseContains('Histórico de alterações');
        $this->get('/pcm/nao-conformidades/' . $nc->id . '/acoes/' . $a->id . '/editar'); $this->assertResponseOk();
        $this->post('/pcm/nao-conformidades/' . $nc->id . '/acoes/' . $a->id . '/editar', ['revision' => 0, 'description' => 'Ação concluída', 'measure' => 'Ação Corretiva', 'responsible_id' => $this->responsibleId, 'status' => 'completed', 'completed_on' => $this->s->today(), 'due_on' => $this->s->today(), 'effectiveness' => 'Atende']);
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
        $input = ['form_complete'=>'1','unit'=>'Servlasa/ Lasa','origin'=>'Auditoria Interna','occurred_on'=>'30/10/2026','description'=>"Descrição\ncom linhas",'actions'=>[
            ['item'=>'01','measure'=>'Ação Corretiva','description'=>'Primeira ação','responsible_id'=>$this->responsibleId,'status'=>'not_started','notes'=>"Observação\nsem cortes"],
            ['item'=>'02','measure'=>'Correção','description'=>'Segunda ação','responsible_id'=>$this->responsibleId,'status'=>'unknown'],
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
        $id=$this->createNc();$action=$this->s->saveAction($this->authenticatedUserId,$id,['measure'=>'Correção','description'=>'Texto integral','responsible_name'=>'Pessoa','status'=>'in_progress']);
        $this->s->table('NonconformityActions')->updateAll(['measure' => 'Texto livre'], ['id' => $action]);
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
        $items=[];for($i=1;$i<=31;$i++)$items[]=['item'=>sprintf('%03d',$i),'measure'=>'Correção','description'=>'Ação '.$i,'responsible_name'=>'Pessoa','status'=>'not_started'];
        $this->s->saveComplete($this->authenticatedUserId,['unit'=>'Servlasa/ Lasa','origin'=>'Auditoria Interna','occurred_on'=>'30/10/2026','actions'=>$items]);
        $ids=[];
        foreach([1=>25,2=>6] as $page=>$expected){$this->get('/pcm/nao-conformidades?page='.$page);$this->assertResponseOk();$html=(string)$this->_response->getBody();preg_match_all('/<tr id="acao-(\d+)"/',$html,$matches);self::assertCount($expected,$matches[1]);$ids=array_merge($ids,$matches[1]);}
        self::assertCount(31,array_unique($ids));
    }
    public function testResponsibleAdministrationAndLegacySelectionAreProtected(): void
    {
        $id = $this->createNc();
        $a = $this->s->saveAction($this->authenticatedUserId, $id, ['measure' => 'Correção', 'description' => str_repeat("Texto longo da atividade.\n", 30), 'responsible_name' => 'Nome importado original', 'status' => 'in_progress']);
        $this->get('/pcm/nao-conformidades/responsaveis'); $this->assertResponseOk();
        $this->assertResponseContains('Associação assistida'); $this->assertResponseContains('Nome importado original');
        $this->exportVisual('responsaveis');
        $this->get('/pcm/nao-conformidades/' . $id . '/editar'); $this->assertResponseOk();
        $this->assertResponseContains('name="actions[0][responsible_id]"');
        $this->assertResponseContains('value="Nome importado original"');
        $this->assertResponseContains('name="actions[0][responsible_email]"');
        $this->exportVisual('formulario');
        $this->enableCsrfToken();
        $this->post('/pcm/nao-conformidades/' . $id . '/acoes/nova', ['measure' => 'Correção', 'description' => 'Sem vínculo', 'responsible_name' => 'Digitação livre', 'status' => 'in_progress']); $this->assertResponseCode(422);
        $this->post('/pcm/nao-conformidades/responsaveis', ['name' => 'Maria Ávila', 'email' => 'maria@example.test', 'active' => '1']); $this->assertResponseCode(302);
        $r = $this->s->table('NonconformityResponsibles')->find()->where(['name' => 'Maria Ávila'])->firstOrFail();
        $this->post('/pcm/nao-conformidades/' . $id . '/acoes/' . $a . '/rapida', ['revision' => 0, 'responsible_id' => $r->id]); $this->assertResponseCode(302);
        $this->get('/pcm/nao-conformidades'); $this->assertResponseOk(); $this->assertResponseContains('pcm-nc-container');
        $this->exportVisual('planilha');
        self::assertSame('Nome importado original', $this->s->table('NonconformityActions')->get($a)->responsible_name);
        $this->session(['Auth' => $this->ordinary()]);
        $this->get('/pcm/nao-conformidades'); $this->assertResponseOk(); $this->assertResponseContains('Maria Ávila');
        $this->get('/pcm/nao-conformidades/responsaveis'); $this->assertResponseCode(403);
        $this->post('/pcm/nao-conformidades/responsaveis/' . $r->id, ['name' => 'Ataque', 'revision' => 0, 'active' => '1']); $this->assertResponseCode(403);
    }
    private function exportVisual(string $name): void
    {
        if (getenv('NC_VISUAL_EXPORT') !== '1') return;
        $dir = TMP . 'nc-visual'; if (!is_dir($dir)) mkdir($dir, 0775, true);
        $html = (string)$this->_response->getBody();
        $root = 'file:///' . str_replace('\\', '/', WWW_ROOT);
        $html = preg_replace('~(href|src)="/(css|js|img)/~', '$1="' . $root . '$2/', $html);
        file_put_contents($dir . DS . $name . '.html', $html);
    }
    public function testResponsibleEmailRequiredOnCreateAndEdit(): void
    {
        $this->get('/pcm/nao-conformidades/responsaveis'); $this->assertResponseOk();
        $this->assertResponseContains('E-mail *'); $this->assertResponseNotContains('E-mail (opcional)');
        $this->enableCsrfToken();
        foreach (['', 'incorreto'] as $email) {
            $this->post('/pcm/nao-conformidades/responsaveis', ['name' => 'Obrigatório', 'email' => $email, 'active' => '1']);
            $this->assertResponseCode(422); $this->assertResponseContains('e-mail');
        }
        $this->post('/pcm/nao-conformidades/responsaveis/' . $this->responsibleId, ['revision' => 0, 'name' => 'Pessoa sem login', 'email' => '', 'active' => '1']);
        $this->assertResponseCode(422);
        self::assertSame('pessoa@example.test', $this->s->table('NonconformityResponsibles')->get($this->responsibleId)->email);
    }
}
