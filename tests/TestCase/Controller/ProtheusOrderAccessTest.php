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
}
