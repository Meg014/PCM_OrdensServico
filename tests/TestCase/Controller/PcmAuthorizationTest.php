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

    public function testAdminAndUsuarioAccessAllAreasRegardlessOfAssignedSector(): void
    {
        foreach (['ADMIN', 'USUARIO'] as $role) {
            foreach (['ELETRI', null] as $assignedArea) {
                foreach (['index', 'dashboardData', 'orders', 'equipment', 'sectorOptions', 'protheusOrder', 'protheusOrderData', 'sector', 'sectorData', 'presentation', 'presentationData'] as $action) {
                    foreach (['MECANI', 'CALDEI'] as $target) {
                        $controller = $this->controller($role, 'Pcm', $action, $assignedArea, [$target]);
                        $controller->beforeFilter(new Event('Controller.initialize', $controller));
                        self::assertNull($controller->getRequest()->getAttribute('pcmAreaScope'));
                    }
                }
            }
        }
    }

    public function testUserAdministrationRequiresFreshAdminRole(): void
    {
        foreach (['index', 'add', 'edit', 'password', 'revokeTv'] as $action) {
            foreach (['ADMIN', 'USUARIO'] as $role) {
                $controller = $this->controller($role, 'Users', $action, sessionRole: 'ADMIN');
                try {
                    $controller->beforeFilter(new Event('Controller.initialize', $controller));
                    self::assertSame('ADMIN', $role);
                } catch (ForbiddenException) {
                    self::assertSame('USUARIO', $role);
                }
            }
        }
    }

    public function testTvOnlyAccessesPresentationAndItsRefreshEndpoint(): void
    {
        foreach (['presentation' => '/pcm/apresentacao', 'presentationData' => '/pcm/apresentacao/data'] as $action => $path) {
            $controller = $this->controller('TV', 'Pcm', $action, null, [], $path);
            $controller->beforeFilter(new Event('Controller.initialize', $controller));
            self::assertNull($controller->getRequest()->getAttribute('pcmAreaScope'));
        }
        foreach ([['Pcm', 'index', '/pcm'], ['Pcm', 'dashboardData', '/pcm/data'],
            ['Pcm', 'sector', '/pcm/setor/ELETRI'], ['Pcm', 'sectorData', '/pcm/setor/ELETRI/data'],
            ['Pcm', 'sectorOptions', '/pcm/setores/data'], ['Pcm', 'orders', '/pcm/ordens'],
            ['Pcm', 'equipment', '/pcm/equipamento'],
            ['Pcm', 'protheusOrder', '/pcm/protheus/os/004368'],
            ['Pcm', 'protheusOrderData', '/pcm/protheus/os/004368/dados'],
            ['Users', 'index', '/usuarios'], ['Users', 'add', '/usuarios/novo'],
            ['Users', 'edit', '/usuarios/7/editar']] as [$name, $action, $path]) {
            $controller = $this->controller('TV', $name, $action, null, ['ELETRI'], $path);
            try {
                $controller->beforeFilter(new Event('Controller.initialize', $controller));
                self::fail("TV should be forbidden: $path");
            } catch (ForbiddenException) {
                self::assertTrue(true);
            }
        }
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
