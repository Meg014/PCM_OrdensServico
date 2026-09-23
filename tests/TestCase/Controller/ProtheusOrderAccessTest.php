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
        self::assertSame('orderProtheus', $params['action']);
        self::assertSame(['42'], $params['pass']);
        $view = new View($this->request());
        $view->setTemplatePath('Pcm');
        $html = $view->element('protheus_order', ['snapshotId' => 42]);
        self::assertStringContainsString('data-url="/pcm/os/42/protheus"', $html);
        self::assertStringContainsString('Detalhes da manutenção', $html);
        self::assertStringContainsString('data-protheus-dialog', $html);
        self::assertStringContainsString('pcm-protheus-order.js', $view->fetch('script'));
    }

    private function request(): ServerRequest
    {
        return new ServerRequest([
            'url' => '/pcm/os/42/protheus', 'environment' => ['REQUEST_METHOD' => 'GET'],
            'params' => ['controller' => 'Pcm', 'action' => 'orderProtheus', 'pass' => ['42']],
        ]);
    }

    public function testDashboardRoutesAndTemplateDoNotRequireAnImport(): void
    {
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        foreach (['/pcm/data' => 'dashboardData', '/pcm/apresentacao/data' => 'presentationData',
            '/pcm/legado' => 'indexLegacy', '/pcm/apresentacao/legado/data' => 'presentationLegacyData'] as $url => $action) {
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
        self::assertStringNotContainsString('Comparar legado Excel', $html);
        self::assertStringContainsString('href="/pcm" class="btn btn-sm btn-outline-secondary">Sair da apresentação</a>', $html);
        self::assertStringNotContainsString('data-pcm-current-version', $html);
        self::assertStringNotContainsString('Importe um XLSX', $html);
        $view->set('presentation', false);
        $general = $view->render('protheus_dashboard', false);
        self::assertStringContainsString('Fonte: Protheus', $general);
        self::assertSame(4, substr_count($general, 'data-dashboard-card='));
        foreach (['Preventivas', 'Corretivas', 'Melhorias', 'PARADAS POR OPORTUNIDADE'] as $label) {
            self::assertStringNotContainsString($label, $general);
        }
        $view->set('currentUser', new Entity(['nome' => 'Teste', 'role' => 'ADMIN']));
        $page = $view->render('protheus_dashboard', 'default');
        self::assertStringNotContainsString('href="/pcm/analises"', $page);
        self::assertStringNotContainsString('>Análises<', $page);
        self::assertStringContainsString('href="/pcm/ordens"', $page);
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
        $row = array_fill_keys(['reference_date', 'equipment_name', 'TJ_CODBEM', 'TJ_SERVICO',
            'service_name', 'TJ_CODAREA', 'TJ_CCUSTO', 'TJ_TIPO', 'TJ_SITUACA', 'TJ_TERMINO'], '<script>alert(1)</script>');
        $row += ['TJ_ORDEM' => '004368', 'TJ_FILIAL' => '01'];
        $listing = ['filters' => ['os' => '004368', 'filial' => '01', 'bem' => ''],
            'page' => 1, 'limit' => 20, 'has_more' => true, 'available' => true, 'orders' => [$row]];
        $view->set('listing', $listing);
        $html = $view->render('orders', false);
        self::assertStringContainsString('/pcm/protheus/os/004368?filial=01', $html);
        self::assertStringContainsString('page=2', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        $listing['available'] = false;
        $view->set('listing', $listing);
        $html = $view->render('orders', false);
        self::assertStringContainsString('temporariamente indisponíveis', $html);
        self::assertStringContainsString('/pcm/ordens/legado', $html);
        self::assertStringNotContainsString('/pcm/protheus/os/004368?', $html);
    }
}
