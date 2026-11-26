<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\PcmController;
use Authentication\Authenticator\UnauthenticatedException;
use Authentication\Identity;
use Cake\Cache\Cache;
use Cake\Cache\Engine\NullEngine;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Routing\Router;
use Cake\View\View;
use PHPUnit\Framework\TestCase;

/** No app bootstrap, migrations, real connections or user records. */
final class ProtheusOrderAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ROOT')) {
            require dirname(__DIR__, 3) . '/config/paths.php';
        }
        Configure::write('App.namespace', 'App');
        Configure::write('App.encoding', 'UTF-8');
        Configure::write('App.paths.templates', [ROOT . '/templates/']);
        Configure::write('App.jsBaseUrl', 'js/');
        require_once CAKE . 'Core/functions_global.php';
        if (!Cache::getConfig('_cake_translations_')) {
            Cache::setConfig('_cake_translations_', ['className' => NullEngine::class]);
        }
    }

    public function testAnonymousCannotStartTheSupplementAction(): void
    {
        $controller = new PcmController($this->request());
        $this->expectException(UnauthenticatedException::class);
        $controller->Authentication->startup();
    }

    public function testAnonymousCannotRequestEquipmentSuggestions(): void
    {
        $request = new ServerRequest(['url' => '/pcm/ordens/equipamentos',
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'params' => ['controller' => 'Pcm', 'action' => 'equipmentSuggestions']]);
        $controller = new PcmController($request);
        $this->expectException(UnauthenticatedException::class);
        $controller->Authentication->startup();
    }

    public function testInvalidEquipmentSuggestionDoesNotQueryTotvs(): void
    {
        $request = new ServerRequest(['url' => '/pcm/ordens/equipamentos',
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'params' => ['controller' => 'Pcm', 'action' => 'equipmentSuggestions']]);
        foreach (['', 'x', ['invalid'], str_repeat('a', 101)] as $term) {
            $response = (new PcmController($request->withQueryParams(['q' => $term])))->equipmentSuggestions();
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testActionPlanFormAndInvalidPeriodDoNotQueryTotvs(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        $request = new ServerRequest(['url' => '/pcm/plano-de-acao',
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'params' => ['controller' => 'Pcm', 'action' => 'actionPlan']]);
        $controller = new PcmController($request);
        $controller->actionPlan();
        $view = new View($request);
        $view->setTemplatePath('Pcm');
        $html = $view->render('action_plan', false);
        self::assertStringContainsString('Baixar Plano de Ação', $html);
        self::assertStringContainsString('name="date_start"', $html);
        self::assertStringContainsString('name="date_end"', $html);
        self::assertStringContainsString('name="unidade"', $html);
        foreach (['Todas', 'Adm', 'Fábrica', 'Usina', 'Externo', 'Setores', 'Não classificados'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringContainsString('/pcm/plano-de-acao/excel', $html);
        $response = (new PcmController($request->withQueryParams([
            'date_start' => '2026-02-30', 'date_end' => '2026-03-01',
        ])))->exportActionPlan();
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $response = (new PcmController($request->withQueryParams([
            'date_start' => '2026-10-01', 'date_end' => '2026-10-22', 'unidade' => 'invalid',
        ])))->exportActionPlan();
        self::assertSame(400, $response->getStatusCode());
    }

    public function testActionPlanConcurrentGenerationIsRejectedWithoutQueryingTotvsAndLockIsReleased(): void
    {
        $request = new ServerRequest(['url' => '/pcm/plano-de-acao/excel', 'environment' => ['REQUEST_METHOD' => 'GET']]);
        $request = $request->withQueryParams(['date_start' => 'invalid', 'date_end' => 'invalid']);
        $path = TMP . 'pcm-action-plan-' . hash('sha256', 'anonymous-test') . '.lock';
        $lock = fopen($path, 'c'); self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try { self::assertSame(409, (new PcmController($request))->exportActionPlan()->getStatusCode()); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        self::assertSame(400, (new PcmController($request))->exportActionPlan()->getStatusCode());
        $lock = fopen($path, 'c');
        try { self::assertTrue(flock($lock, LOCK_EX | LOCK_NB)); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function testTvRemainsBlockedAndRegularLoggedInUserKeepsAccess(): void
    {
        foreach (['TV', 'ADMIN'] as $role) {
            $user = new Entity(['id' => 7, 'password' => 'synthetic-hash', 'role' => $role]);
            $request = $this->request()->withAttribute('identity', new Identity($user));
            $controller = new PcmController($request);
            $query = $this->createMock(SelectQuery::class);
            $query->method('contain')->willReturnSelf();
            $query->method('where')->willReturnSelf();
            $query->method('first')->willReturn($user);
            $table = $this->createMock(Table::class);
            $table->method('find')->willReturn($query);
            $locator = new TableLocator();
            $locator->set('Users', $table);
            $controller->setTableLocator($locator);
            $controller->Authentication->startup();
            try {
                $controller->beforeFilter(new Event('Controller.initialize', $controller));
                self::assertSame('ADMIN', $role);
            } catch (ForbiddenException) {
                self::assertSame('TV', $role);
            }
        }
    }

    public function testRouteAndDeferredElementRenderWithoutProtheus(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        $params = Router::parseRequest($this->request());
        self::assertSame('protheusOrderData', $params['action']);
        self::assertSame(['42'], $params['pass']);
        $view = new View($this->request());
        $view->setTemplatePath('Pcm');
        $html = $view->element('protheus_order', ['protheusUrl' => '/pcm/protheus/os/42/dados?filial=01']);
        self::assertStringContainsString('data-url="/pcm/protheus/os/42/dados?filial=01"', $html);
        self::assertStringContainsString('Detalhes da manutenção', $html);
        self::assertStringContainsString('data-protheus-dialog', $html);
        self::assertStringContainsString('pcm-protheus-order.js', $view->fetch('script'));
    }

    public function testExportRoutesRequireLoginAndRejectTvForEverySector(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        foreach (['/pcm/ordens/excel' => 'exportOrders', '/pcm/equipamento/excel' => 'exportEquipment',
            '/pcm/plano-de-acao' => 'actionPlan', '/pcm/plano-de-acao/excel' => 'exportActionPlan',
            '/pcm/ordens/apontamentos/excel' => 'exportOrderEntries',
            '/pcm/setor/ELETRI/excel' => 'exportSector', '/pcm/setor/MECANI/excel' => 'exportSector',
            '/pcm/setor/ELETRI/apontamentos/excel' => 'exportSectorEntries'] as $url => $action) {
            $request = new ServerRequest(['url' => $url, 'environment' => ['REQUEST_METHOD' => 'GET'],
                'params' => ['controller' => 'Pcm', 'action' => $action]]);
            self::assertSame($action, Router::parseRequest($request)['action']);
            try {
                (new PcmController($request))->Authentication->startup();
                self::fail('Anonymous export must be blocked.');
            } catch (UnauthenticatedException) {
                self::assertTrue(true);
            }
            foreach (['TV', 'ADMIN', 'USUARIO'] as $role) {
                $user = new Entity(['id' => 7, 'password' => 'synthetic-hash', 'role' => $role]);
                $controller = new PcmController($request->withAttribute('identity', new Identity($user)));
                $query = $this->createMock(SelectQuery::class);
                $query->method('where')->willReturnSelf();
                $query->method('first')->willReturn($user);
                $table = $this->createMock(Table::class);
                $table->method('find')->willReturn($query);
                $locator = new TableLocator();
                $locator->set('Users', $table);
                $controller->setTableLocator($locator);
                $controller->Authentication->startup();
                try {
                    $controller->beforeFilter(new Event('Controller.initialize', $controller));
                    self::assertNotSame('TV', $role);
                } catch (ForbiddenException) {
                    self::assertSame('TV', $role);
                }
            }
        }
    }

    private function request(): ServerRequest
    {
        return new ServerRequest([
            'url' => '/pcm/protheus/os/42/dados', 'environment' => ['REQUEST_METHOD' => 'GET'],
            'params' => ['controller' => 'Pcm', 'action' => 'protheusOrderData', 'pass' => ['42']],
        ]);
    }

    public function testDashboardRoutesAndTemplateDoNotRequireAnImport(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        foreach (['/pcm/data' => 'dashboardData', '/pcm/apresentacao/data' => 'presentationData'] as $url => $action) {
            $request = new ServerRequest(['url' => $url, 'environment' => ['REQUEST_METHOD' => 'GET']]);
            self::assertSame($action, Router::parseRequest($request)['action']);
        }
        $view = new View($this->request());
        $view->setTemplatePath('Pcm');
        $view->set(['presentation' => true, 'payload' => ['available' => false,
            'filters' => array_fill_keys(\App\Service\Protheus\ProtheusDashboardService::FILTERS, ''),
            'record_count' => null, 'groups' => [], 'queried_at' => null,
            'indicators' => array_fill_keys(\App\Service\Protheus\ProtheusDashboardService::CARDS, null)]]);
        $html = $view->render('protheus_dashboard', false);
        self::assertStringNotContainsString('&amp;amp;', $html);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $refreshUrl = $xpath->query('//*[@data-protheus-dashboard]')->item(0)->getAttribute('data-url');
        parse_str((string)parse_url($refreshUrl, PHP_URL_QUERY), $refreshFilters);
        foreach (\App\Service\Protheus\ProtheusDashboardService::FILTERS as $filter) {
            self::assertArrayHasKey($filter, $refreshFilters);
        }
        self::assertStringContainsString('/pcm/apresentacao/data', $html);
        self::assertSame(10, substr_count($html, 'data-dashboard-card='));
        foreach (['preventive', 'corrective', 'improvement', 'emergency', 'scheduled', 'opportunity'] as $key) {
            self::assertStringContainsString('data-dashboard-card="' . $key . '"', $html);
        }
        self::assertStringContainsString('Safra — O.S. em aberto', $html);
        self::assertStringContainsString('Entressafra — O.S. fechadas', $html);
        self::assertStringNotContainsString('Eficiência', $html);
        self::assertStringNotContainsString('Concluídas aguardam', $html);
        self::assertStringNotContainsString('Fonte: Protheus', $html);
        self::assertStringNotContainsString('Filtros da consulta Protheus', $html);
        self::assertStringNotContainsString('ANÁLISE GERAL', $html);
        self::assertStringNotContainsString('Top 10 equipamentos por O.S.', $html);
        self::assertStringNotContainsString('Comparar legado Excel', $html);
        self::assertStringContainsString('href="/pcm" class="btn btn-sm btn-outline-secondary">Sair da apresentação</a>', $html);
        self::assertStringNotContainsString('data-pcm-current-version', $html);
        self::assertStringNotContainsString('Importe um XLSX', $html);
        $view->set('presentation', false);
        $generalPayload = $view->get('payload');
        $generalPayload['analysis'] = ['total' => 37, 'equipment' => [], 'genericEquipment' => [], 'services' => [],
            'costCenters' => [], 'maintenance' => [], 'sectors' => [],
            'status' => ['completed' => 0, 'open' => 0]];
        $generalPayload['detail'] = ['available' => true,
            'operational' => ['total' => 10, 'open' => 3, 'closed' => 7],
            'breakdown' => array_fill_keys(array_keys(\App\Service\Protheus\ProtheusSectorService::CATEGORIES), ['open' => 0, 'closed' => 0]),
            'backlog' => ['total' => 3, 'ages' => array_fill_keys(array_keys(\App\Service\Protheus\ProtheusSectorService::BACKLOG_AGES), 0), 'as_of' => '28/09/2026'],
            'checklists' => ['total' => 2, 'ages' => array_replace(array_fill_keys(array_keys(\App\Service\Protheus\RouteChecklist::AGES), 0), ['route_attention' => 2]), 'as_of' => '28/09/2026', 'visible' => true],
            'missing_start' => 2, 'charts' => ['equipment' => [], 'services' => []], 'orders' => [],
            'page' => 1, 'limit' => 20, 'has_more' => false];
        $view->set('payload', $generalPayload);
        $general = $view->render('protheus_dashboard', false);
        self::assertStringContainsString('Fonte: Protheus', $general);
        self::assertStringContainsString('ANÁLISE GERAL', $general);
        self::assertStringContainsString('Top 10 equipamentos', $general);
        self::assertStringContainsString('Top 10 serviços', $general);
        self::assertStringContainsString('Top 10 centros de custo', $general);
        self::assertStringContainsString('O.S. por Tipo de Manutenção', $general);
        self::assertStringContainsString('O.S. por Setor', $general);
        self::assertStringContainsString('Situação das O.S.', $general);
        self::assertStringContainsString('Distribuição das O.S.', $general);
        foreach (['Resumo operacional geral', 'Detalhamento por classificação', 'Backlog de O.S. normais', 'Checklists das rotas em aberto',
            'Ordens de Serviço'] as $heading) self::assertStringContainsString($heading, $general);
        self::assertStringNotContainsString('Pontos de atenção', $general);
        self::assertStringContainsString('backlog_age=route_attention', $general);
        self::assertStringContainsString('Mecânica: 0 a 7 dias · Elétrica: 0 a 17 dias', $general);
        self::assertStringContainsString('Mecânica: 8 a 14 dias · Elétrica: 18 a 20 dias', $general);
        self::assertStringNotContainsString('Situação / término', $general);
        self::assertSame(1, substr_count($general, 'data-analysis-chart='));
        $scripts = $view->fetch('script');
        self::assertMatchesRegularExpression('~/js/chart\.umd\.min\.js\?[0-9]+~', $scripts);
        self::assertMatchesRegularExpression('~/js/pcm-protheus-dashboard\.js\?[0-9]+~', $scripts);
        self::assertLessThan(strpos($scripts, 'chart.umd.min.js'), strpos($scripts, 'pcm-protheus-dashboard.js'));
        self::assertStringContainsString('data-analysis-total>37', $general);
        self::assertStringContainsString('pcm-chart-card', $general);
        self::assertStringContainsString('ANÁLISE GERAL · SAFRA', $general);
        self::assertStringContainsString('exclusivamente O.S. da Safra', $general);
        self::assertStringContainsString('pcm-filter-panel pcm-history-scope-filter', $general);
        self::assertStringContainsString('class="form-select"', $general);
        self::assertStringNotContainsString('setor=', $general);
        self::assertSame(4, substr_count($general, 'data-dashboard-card='));
        foreach (['Preventivas', 'Corretivas', 'Melhorias', 'Paradas por Oportunidade'] as $label) {
            self::assertStringContainsString($label, $general);
        }
        $view->set('currentUser', new Entity(['nome' => 'Teste', 'role' => 'ADMIN']));
        $page = $view->render('protheus_dashboard', 'default');
        self::assertStringNotContainsString('href="/pcm/analises"', $page);
        self::assertStringNotContainsString('>Análises<', $page);
        self::assertStringContainsString('href="/pcm/ordens"', $page);
        self::assertStringContainsString('href="/usuarios"', $page);
        self::assertStringNotContainsString('/importacoes', $page);
        self::assertStringNotContainsString('legado Excel', $page);
        $view->set('currentUser', new Entity(['nome' => 'Teste', 'role' => 'USUARIO']));
        $page = $view->render('protheus_dashboard', 'default');
        self::assertStringNotContainsString('href="/usuarios"', $page);
    }

    public function testDirectDetailRouteAndPageNeedNoSnapshot(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        $request = new ServerRequest(['url' => '/pcm/protheus/os/004368',
            'environment' => ['REQUEST_METHOD' => 'GET'], 'query' => ['filial' => '01'],
            'params' => ['controller' => 'Pcm', 'action' => 'protheusOrder', 'pass' => ['004368']]]);
        self::assertSame('protheusOrder', Router::parseRequest($request)['action']);
        $controller = new PcmController($request);
        $controller->protheusOrder('004368');
        self::assertSame(['source_order_number' => '004368', 'branch_code' => '01', 'unit' => ''], $controller->viewBuilder()->getVar('identity'));
        $view = new View($request);
        $view->setTemplatePath('Pcm');
        $view->set('identity', $controller->viewBuilder()->getVar('identity'));
        $html = $view->render('protheus_order', false);
        self::assertStringContainsString('/pcm/protheus/os/004368/dados?filial=01', $html);
        self::assertStringContainsString('unit=', $html);
        self::assertStringNotContainsString('/pcm/os/42', $html);
        self::assertStringContainsString('Fonte: Protheus', $html);
    }

    public function testChecklistFiltersRemainInPaginationAndExportLinks(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        $view = new View($this->request());
        $view->setTemplatePath('Pcm');
        $listing = ['filters' => ['service_scope' => 'checklist', 'checklist_service' => '000123'],
            'page' => 2, 'limit' => 20, 'total' => 42, 'has_more' => true, 'available' => true, 'orders' => []];
        $view->set('listing', $listing);
        $view->set('checklistServices', ['000123' => '000123 — Rota <teste>']);
        $html = $view->render('orders', false);
        self::assertStringContainsString('Rotas de Checklist', $html);
        self::assertStringContainsString('Rota &lt;teste&gt;', $html);
        self::assertStringContainsString('<option value="000123" selected="selected">', $html);
        self::assertSame(4, substr_count($html, 'service_scope=checklist&amp;checklist_service=000123'));
        self::assertStringContainsString('page=3', $html);
        self::assertStringContainsString('pcm-checklist-filter.js', $view->fetch('script'));
        $listing['filters'] = [];
        $view->set('listing', $listing);
        $html = $view->render('orders', false);
        self::assertMatchesRegularExpression('/<select[^>]*name="checklist_service"[^>]*disabled/', $html);
    }

    public function testCleanListingKeepsUserScopeAndDoesNotRenderExecutionSummary(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        $view = new View($this->request());
        $view->setTemplatePath('Pcm');
        $row = ['TJ_ORDEM' => '007659', 'TJ_FILIAL' => '01', 'origin_date' => '2502-08-14',
            'TJ_DTORIGI' => '25020814', 'TJ_DTMPINI' => '25020814', 'TJ_CODAREA' => 'MECANI',
            'TJ_CODBEM' => 'BEM', 'equipment_name' => 'Bem', 'TJ_SERVICO' => 'PREMEC',
            'service_name' => 'Preventiva', 'TJ_CCUSTO' => 'CC01', 'TJ_SITUACA' => 'C', 'TJ_TERMINO' => 'N',
            'execution_summary' => ['text' => 'Sem serviço executado registrado', 'source_label' => 'Sem execução']];
        $listing = ['filters' => ['initial_user' => 'Inicio', 'order_status' => 'cancelled',
            'service_scope' => 'checklist', 'checklist_service' => 'PREMEC', 'centro' => 'CC01'],
            'page' => 2, 'limit' => 20, 'total' => 42, 'has_more' => true, 'available' => true, 'orders' => [$row]];
        $before = $listing;
        $view->set('listing', $listing);
        $view->set('initialUsers', ['Inicio' => 'Inicio', 'Outro' => 'Outro']);
        $view->set('checklistServices', \App\Service\Protheus\ChecklistServiceFilter::SERVICES);
        $html = $view->render('orders', false);
        self::assertStringNotContainsString('<summary>Serviço Executado</summary>', $html);
        self::assertStringNotContainsString('Sem serviço executado registrado', $html);
        self::assertStringNotContainsString('Motivo do cancelamento', $html);
        self::assertStringNotContainsString('filter-centro', $html);
        self::assertStringNotContainsString('Usuário de início (TOTVS)', $html);
        self::assertSame(1, substr_count($html, 'Usuário de início'));
        self::assertSame(1, substr_count($html, 'name="initial_user"'));
        self::assertStringContainsString('<option value="Inicio" selected="selected">Inicio</option>', $html);
        self::assertLessThan(strpos($html, 'name="initial_user"'), strpos($html, 'id="filter-os"'));
        self::assertLessThan(strpos($html, 'id="filter-equipment"'), strpos($html, 'name="initial_user"'));
        self::assertSame(4, substr_count($html, 'initial_user=Inicio&amp;order_status=cancelled&amp;service_scope=checklist&amp;checklist_service=PREMEC'));
        self::assertStringContainsString('name="centro"', $html); // Preserve existing drill-down scopes, without a visible filter.
        self::assertStringContainsString('page=3', $html);
        self::assertStringContainsString('/pcm/protheus/os/007659?filial=01', $html);
        self::assertMatchesRegularExpression('/<a\b(?=[^>]*text-danger-emphasis)(?=[^>]*\/pcm\/protheus\/os\/007659)[^>]*>007659<\/a>/', $html);
        self::assertStringContainsString('<td class="text-warning fw-semibold">14/08/2502</td>', $html);
        self::assertSame($before, $listing);
    }

    public function testListingFilterOrderAndFutureDatesUseSharedValidation(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        $view = new View($this->request());
        $view->setTemplatePath('Pcm');
        $orders = [];
        foreach (['007881' => '2026-11-25', '007880' => '2026-11-25', '007492' => '2026-12-16', '007659' => '2502-08-14'] as $number => $date) {
            $orders[] = ['TJ_ORDEM' => $number, 'TJ_FILIAL' => '01', 'origin_date' => $date,
                'TJ_DTMPINI' => str_replace('-', '', $date), 'TJ_CODAREA' => 'MECANI',
                'TJ_CODBEM' => 'BEM', 'equipment_name' => 'Bem', 'TJ_SERVICO' => 'PREMEC',
                'service_name' => 'Preventiva', 'TJ_CCUSTO' => 'CC01', 'TJ_SITUACA' => 'L', 'TJ_TERMINO' => 'N'];
        }
        $view->set('listing', ['filters' => [], 'page' => 1, 'limit' => 20, 'total' => 4,
            'has_more' => false, 'available' => true, 'orders' => $orders]);
        $view->set('checklistServices', \App\Service\Protheus\ChecklistServiceFilter::SERVICES);
        $html = $view->render('orders', false);
        self::assertLessThan(strpos($html, 'id="filter-service-scope"'), strpos($html, 'id="filter-equipment"'));
        self::assertLessThan(strpos($html, 'id="filter-checklist-service"'), strpos($html, 'id="filter-service-scope"'));
        self::assertStringContainsString('<option value="PREVLA">PREVLAM — Laminadores</option>', $html);
        self::assertStringNotContainsString('Data posterior', $html);
        self::assertStringContainsString('25/11/2026', $html);
        self::assertStringContainsString('16/12/2026', $html);
        self::assertStringNotContainsString('Data fora do intervalo aceitável', $html);
        self::assertStringContainsString('<td class="text-warning fw-semibold">14/08/2502</td>', $html);
        self::assertStringContainsString('<td>25/11/2026</td>', $html);
        self::assertStringContainsString('<td>16/12/2026</td>', $html);
        self::assertSame(1, substr_count($html, 'class="text-warning fw-semibold"'));
        foreach (['007881', '007880', '007492', '007659'] as $number) self::assertStringContainsString($number, $html);
    }

    public function test005810ListingUsesMaintenanceDatesAndPreservesClosedStatus(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        $view = new View($this->request());
        $view->setTemplatePath('Pcm');
        $row = array_fill_keys(['equipment_name', 'TJ_CODBEM', 'TJ_SERVICO', 'service_name', 'TJ_CCUSTO', 'TJ_TIPO'], '');
        $row += ['TJ_ORDEM' => '005810', 'TJ_FILIAL' => '01', 'TJ_CODAREA' => 'MECANI',
            'TJ_SITUACA' => 'L', 'TJ_TERMINO' => 'S', 'TJ_DTMRINI' => '20261028',
            'TJ_HOMRINI' => '07:30', 'TJ_DTMRFIM' => '20261028', 'TJ_HOMRFIM' => '17:00', 'TJ_DTPRFIM' => ''];
        $view->set('listing', ['filters' => ['os' => '005810', 'filial' => '01', 'unidade' => ''],
            'page' => 1, 'limit' => 20, 'total' => 1, 'has_more' => false, 'available' => true, 'orders' => [$row]]);
        $view->set('areas', ['MECANI']);
        $html = $view->render('orders', false);
        self::assertSame(2, substr_count($html, '<td>28/10/2026</td>'));
        self::assertStringContainsString('<td>Fechada</td>', $html);
        self::assertStringContainsString('/pcm/protheus/os/005810?filial=01', $html);
    }

    public function testListingEscapesSourceValuesAndKeepsFallbackExplicit(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        $view = new View($this->request());
        $view->setTemplatePath('Pcm');
        $row = array_fill_keys(['equipment_name', 'TJ_CODBEM', 'TJ_SERVICO',
            'service_name', 'TJ_CODAREA', 'TJ_CCUSTO', 'TJ_TIPO', 'TJ_SITUACA', 'TJ_TERMINO'], '<script>alert(1)</script>');
        $row += ['TJ_ORDEM' => '004368', 'TJ_FILIAL' => '01', 'origin_date' => '2026-09-23',
            'descricao' => '<script>alert(2)</script>', 'TJ_DTMRINI' => '', 'TJ_DTMRFIM' => '20260924', 'TJ_DTPRFIM' => '20250101'];
        $listing = ['filters' => ['os' => '004368', 'filial' => '01', 'bem' => '',
            'historico' => '1', 'unidade' => 'factory', 'nome_bem' => 'BOMBA'],
            'page' => 1, 'limit' => 20, 'has_more' => true, 'available' => true, 'orders' => [$row]];
        $view->set('listing', $listing);
        $view->set('areas', ['ELETRI', 'MECANI']);
        $html = $view->render('orders', false);
        self::assertStringContainsString('Exportar apontamentos', $html);
        self::assertStringContainsString('/pcm/ordens/apontamentos/excel', $html);
        self::assertStringContainsString('<option value="ELETRI">Elétrica/Instrumentação</option>', $html);
        self::assertStringContainsString('/pcm/protheus/os/004368?filial=01', $html);
        self::assertStringContainsString('23/09/2026', $html);
        self::assertStringContainsString('24/09/2026', $html);
        self::assertStringContainsString('pcm-dashboard-section pcm-equipment-history', $html);
        self::assertStringContainsString('pcm-sector-table-scroll', $html);
        self::assertStringContainsString('table pcm-orders-table pcm-order-listing align-middle', $html);
        self::assertStringContainsString('<th class="pcm-order-number">O.S.</th>', $html);
        self::assertStringContainsString('<th class="pcm-order-description">Descrição</th>', $html);
        self::assertStringContainsString('<td class="pcm-order-description">', $html);
        self::assertSame(2, substr_count($html, '<td class="pcm-order-text">'));
        self::assertStringContainsString('pcm-pagination', $html);
        foreach (['Data de origem', 'Início real', 'Fim real', 'Equipamento',
            'Serviço', 'Situação', 'Centro de custo', 'Área/Setor'] as $heading) {
            self::assertStringContainsString('<th>' . $heading . '</th>', $html);
        }
        self::assertStringContainsString('Período pela Data de origem da OS.', $html);
        self::assertStringContainsString('page=2', $html);
        self::assertStringContainsString('historico=1', $html);
        self::assertStringContainsString('unidade=factory', $html);
        self::assertStringContainsString('nome_bem=BOMBA', $html);
        self::assertStringContainsString('Bem / Equipamento', $html);
        self::assertStringContainsString('value="BOMBA"', $html);
        $listing['filters'] += ['area' => 'MECANI', 'card' => 'offseason', 'card_status' => 'EM ABERTO',
            'backlog_age' => '', 'q' => '', 'status' => '', 'service_name' => ''];
        $listing['drilldown'] = ['label' => 'Entressafra · Em aberto', 'total' => 376];
        $view->set('listing', $listing);
        $drilldownHtml = $view->render('orders', false);
        self::assertStringContainsString('Filtro ativo: Mecânica · Entressafra · Em aberto', $drilldownHtml);
        self::assertStringContainsString('376 O.S. encontradas', $drilldownHtml);
        self::assertStringContainsString('card=offseason', $drilldownHtml);
        self::assertStringContainsString('card_status=EM+ABERTO', $drilldownHtml);
        self::assertStringNotContainsString('backlog_age=', $drilldownHtml);
        self::assertStringNotContainsString('safra=', $drilldownHtml);
        self::assertStringContainsString('page=2', $drilldownHtml);
        self::assertStringContainsString('<form method="get"', $drilldownHtml);
        self::assertStringContainsString('type="hidden" name="card" value="offseason"', $drilldownHtml);
        self::assertStringContainsString('type="hidden" name="card_status" value="EM ABERTO"', $drilldownHtml);
        self::assertStringNotContainsString('type="hidden" name="backlog_age" value=""', $drilldownHtml);
        self::assertStringNotContainsString('type="hidden" name="safra" value=""', $drilldownHtml);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        $friendly = $row;
        $friendly['TJ_CODAREA'] = 'MECANI';
        $friendly['TJ_CCUSTO'] = '';
        $friendly['TJ_SITUACA'] = 'L';
        $friendly['TJ_TERMINO'] = 'S';
        $listing['orders'] = [$friendly];
        $view->set('listing', $listing);
        $friendlyHtml = $view->render('orders', false);
        self::assertStringContainsString('Mecânica', $friendlyHtml);
        self::assertStringContainsString('Fechada', $friendlyHtml);
        self::assertMatchesRegularExpression('~<td>—<small[^>]*>Não classificados</small>\s*</td>\s*<td>Mecânica</td>~', $friendlyHtml);
        self::assertStringNotContainsString('COR / L / S', $friendlyHtml);
        $listing['available'] = false;
        $view->set('listing', $listing);
        $html = $view->render('orders', false);
        self::assertStringContainsString('temporariamente indisponíveis', $html);
        self::assertStringNotContainsString('/pcm/ordens/legado', $html);
        self::assertStringNotContainsString('/pcm/protheus/os/004368?', $html);
    }
}
