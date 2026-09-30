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
        self::assertStringContainsString('/pcm/apresentacao/data', $html);
        self::assertSame(10, substr_count($html, 'data-dashboard-card='));
        foreach (['preventive', 'corrective', 'improvement', 'emergency', 'scheduled', 'opportunity'] as $key) {
            self::assertStringContainsString('data-dashboard-card="' . $key . '"', $html);
        }
        self::assertStringContainsString('Safra — O.S. em aberto', $html);
        self::assertStringContainsString('Entressafra — O.S. fechadas', $html);
        self::assertStringNotContainsString('Eficiência', $html);
        self::assertStringNotContainsString('Concluídas', $html);
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
        $generalPayload['analysis'] = ['total' => 37, 'equipment' => [[
            'code' => 'FAB 80 080', 'name' => 'EXPANDER EX-245', 'branch' => '01', 'quantity' => 37,
        ]], 'services' => [[
            'code' => 'CORMEC', 'name' => 'CORRETIVA MECANICA', 'branch' => '01', 'quantity' => 962,
        ]], 'costCenters' => [
            ['code' => '', 'mode' => 'blank', 'quantity' => 15],
        ], 'maintenance' => [], 'sectors' => [],
            'status' => ['completed' => 0, 'open' => 0]];
        $generalPayload['detail'] = ['available' => true,
            'operational' => ['total' => 10, 'open' => 3, 'closed' => 7],
            'breakdown' => array_fill_keys(array_keys(\App\Service\Protheus\ProtheusSectorService::CATEGORIES), ['open' => 0, 'closed' => 0]),
            'backlog' => ['total' => 3, 'ages' => array_fill_keys(array_keys(\App\Service\Protheus\ProtheusSectorService::BACKLOG_AGES), 0), 'as_of' => '28/09/2026'],
            'missing_start' => 2, 'charts' => ['equipment' => [], 'services' => []], 'orders' => [],
            'page' => 1, 'limit' => 20, 'has_more' => false];
        $view->set('payload', $generalPayload);
        $general = $view->render('protheus_dashboard', false);
        self::assertStringContainsString('Fonte: Protheus', $general);
        self::assertStringContainsString('ANÁLISE GERAL', $general);
        self::assertStringContainsString('Top 10 equipamentos por O.S.', $general);
        self::assertStringContainsString('Top 10 serviços por O.S.', $general);
        self::assertStringContainsString('Top 10 centros de custo por O.S.', $general);
        self::assertStringContainsString('O.S. por Tipo de Manutenção', $general);
        self::assertStringContainsString('O.S. por Setor', $general);
        self::assertStringContainsString('Situação das O.S.', $general);
        self::assertStringContainsString('Situação e concentração das O.S.', $general);
        foreach (['Resumo operacional geral', 'Detalhamento por classificação', 'Backlog / O.S. em aberto',
            'Pontos de atenção', 'Ordens de Serviço'] as $heading) self::assertStringContainsString($heading, $general);
        self::assertSame(6, substr_count($general, 'data-analysis-chart='));
        $scripts = $view->fetch('script');
        self::assertMatchesRegularExpression('~/js/chart\.umd\.min\.js\?[0-9]+~', $scripts);
        self::assertMatchesRegularExpression('~/js/pcm-protheus-dashboard\.js\?[0-9]+~', $scripts);
        self::assertLessThan(strpos($scripts, 'chart.umd.min.js'), strpos($scripts, 'pcm-protheus-dashboard.js'));
        self::assertStringContainsString('data-analysis-total>37', $general);
        self::assertStringContainsString('pcm-chart-card', $general);
        self::assertStringContainsString('bem=FAB+80+080&amp;filial=01', $general);
        self::assertStringContainsString('/pcm/ordens?centro=&amp;centro_modo=blank', $general);
        self::assertStringContainsString('/pcm/ordens?filial=01&amp;servico=CORMEC', $general);
        self::assertStringContainsString('CORMEC — CORRETIVA MECANICA', $general);
        self::assertStringContainsString('Sem centro de custo', $general);
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
        self::assertSame(['source_order_number' => '004368', 'branch_code' => '01'], $controller->viewBuilder()->getVar('identity'));
        $view = new View($request);
        $view->setTemplatePath('Pcm');
        $view->set('identity', $controller->viewBuilder()->getVar('identity'));
        $html = $view->render('protheus_order', false);
        self::assertStringContainsString('/pcm/protheus/os/004368/dados?filial=01', $html);
        self::assertStringNotContainsString('/pcm/os/42', $html);
        self::assertStringContainsString('Fonte: Protheus', $html);
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
            'descricao' => '<script>alert(2)</script>', 'TJ_DTMRINI' => '', 'TJ_DTMRFIM' => '20260924'];
        $listing = ['filters' => ['os' => '004368', 'filial' => '01', 'bem' => ''],
            'page' => 1, 'limit' => 20, 'has_more' => true, 'available' => true, 'orders' => [$row]];
        $view->set('listing', $listing);
        $view->set('areas', ['ELETRI', 'MECANI']);
        $html = $view->render('orders', false);
        self::assertStringContainsString('Exportar apontamentos', $html);
        self::assertStringContainsString('/pcm/ordens/apontamentos/excel', $html);
        self::assertStringContainsString('<option value="ELETRI">ELETRI</option>', $html);
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
        self::assertMatchesRegularExpression('~<td>—</td>\s*<td>Mecânica</td>~', $friendlyHtml);
        self::assertStringNotContainsString('COR / L / S', $friendlyHtml);
        $listing['available'] = false;
        $view->set('listing', $listing);
        $html = $view->render('orders', false);
        self::assertStringContainsString('temporariamente indisponíveis', $html);
        self::assertStringNotContainsString('/pcm/ordens/legado', $html);
        self::assertStringNotContainsString('/pcm/protheus/os/004368?', $html);
    }
}
