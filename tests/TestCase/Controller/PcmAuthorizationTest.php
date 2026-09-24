<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\PcmController;
use Authentication\Identity;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\ServerRequest;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Routing\Router;
use PHPUnit\Framework\TestCase;

/** Offline authorization checks: no application bootstrap, migrations or database. */
final class PcmAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT')) require dirname(__DIR__, 3) . '/config/paths.php';
        Configure::write('App.namespace', 'App');
        Configure::write('App.encoding', 'UTF-8');
    }

    private function controller(string $role, string $controller, string $action, ?string $area = 'ELETRI', array $pass = [], string $path = '/pcm', ?string $sessionRole = null): PcmController
    {
        $user = new Entity(['id' => 7, 'password' => 'synthetic-hash', 'role' => $role,
            'maintenance_area' => $area === null ? null : new Entity(['source_code' => $area])]);
        $identity = clone $user;
        $identity->role = $sessionRole ?? $role;
        $request = new ServerRequest(['url' => $path, 'environment' => ['REQUEST_METHOD' => 'GET'],
            'params' => compact('controller', 'action', 'pass'),
            'query' => ['area' => 'MECANI', 'scope_area' => 'MECANI']]);
        $instance = new PcmController($request->withAttribute('identity', new Identity($identity)));
        $query = $this->createMock(SelectQuery::class);
        $query->method('contain')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('first')->willReturn($user);
        $table = $this->createMock(Table::class);
        $table->method('find')->willReturn($query);
        $locator = new TableLocator();
        $locator->set('Users', $table);
        $instance->setTableLocator($locator);

        return $instance;
    }

    public function testSectorScopeUsesFreshUserAndCannotBeOverriddenByQuery(): void
    {
        foreach (['orders', 'sectorOptions', 'protheusOrder', 'protheusOrderData', 'sector', 'sectorData'] as $action) {
            $controller = $this->controller('USUARIO', 'Pcm', $action, pass: ['ELETRI'], sessionRole: 'ADMIN');
            $controller->beforeFilter(new Event('Controller.initialize', $controller));
            self::assertSame('ELETRI', $controller->getRequest()->getAttribute('pcmAreaScope'));
        }
    }

    public function testOtherSectorsGlobalEndpointsAndUserAdministrationAreForbidden(): void
    {
        $cases = [['Pcm', 'sector', 'ELETRI'], ['Pcm', 'sectorData', 'ELETRI'],
            ['Pcm', 'orders', null], ['Pcm', 'dashboardData', 'ELETRI'],
            ['Pcm', 'presentation', 'ELETRI'], ['Pcm', 'presentationData', 'ELETRI']];
        foreach (['index', 'add', 'edit', 'password', 'revokeTv'] as $action) $cases[] = ['Users', $action, 'ELETRI'];
        foreach ($cases as [$name, $action, $area]) {
            $controller = $this->controller('USUARIO', $name, $action, $area, ['MECANI']);
            try {
                $controller->beforeFilter(new Event('Controller.initialize', $controller));
                self::fail("Access should be forbidden: $name/$action");
            } catch (ForbiddenException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAdminAndExistingTvPresentationRemainAvailable(): void
    {
        foreach ([['ADMIN', 'Users', 'edit', '/usuarios/7/editar'], ['ADMIN', 'Pcm', 'sector', '/pcm/setor/MECANI'],
            ['TV', 'Pcm', 'presentation', '/pcm/apresentacao'], ['TV', 'Pcm', 'presentationData', '/pcm/apresentacao/data']] as [$role, $name, $action, $path]) {
            $controller = $this->controller($role, $name, $action, null, ['MECANI'], $path);
            $controller->beforeFilter(new Event('Controller.initialize', $controller));
            self::assertNull($controller->getRequest()->getAttribute('pcmAreaScope'));
        }
        $controller = $this->controller('TV', 'Pcm', 'orders', path: '/pcm/ordens');
        $this->expectException(ForbiddenException::class);
        $controller->beforeFilter(new Event('Controller.initialize', $controller));
    }

    public function testLegacyActionsAreBlockedEvenForAdminAndHaveNoRoutes(): void
    {
        foreach ([['ReportImports', 'index'], ['ReportImports', 'manual'], ['Pcm', 'ordersLegacy'], ['Pcm', 'order'], ['Pcm', 'orderProtheus']] as [$name, $action]) {
            $controller = $this->controller('ADMIN', $name, $action);
            try {
                $controller->beforeFilter(new Event('Controller.initialize', $controller));
                self::fail('Legacy action exposed');
            } catch (NotFoundException) {
                self::assertTrue(true);
            }
        }
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        foreach (['/importacoes', '/report-imports/manual', '/pcm/ordens/legado', '/pcm/os/42', '/pcm/orders-legacy'] as $url) {
            try {
                Router::parseRequest(new ServerRequest(['url' => $url, 'environment' => ['REQUEST_METHOD' => 'GET']]));
                self::fail('Legacy route exposed: ' . $url);
            } catch (\Cake\Routing\Exception\MissingRouteException) {
                self::assertTrue(true);
            }
        }
    }
}
