<?php
declare(strict_types=1);

namespace App\Test\TestCase;

use App\Application;
use Authentication\UrlChecker\DefaultUrlChecker;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use PHPUnit\Framework\TestCase;

final class AuthenticationBasePathTest extends TestCase
{
    public function testLoginAndRedirectsRespectTheApplicationBasePath(): void
    {
        if (!defined('ROOT')) {
            require dirname(__DIR__, 2) . '/config/paths.php';
        }
        Configure::write('App.namespace', 'App');
        Configure::write('App.encoding', 'UTF-8');
        Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(Router::createRouteBuilder('/'));
        Router::fullBaseUrl('http://localhost');
        foreach (['', '/PCM_OrdensServico'] as $base) {
            $request = (new ServerRequest(['url' => '/login']))
                ->withAttribute('base', $base)
                ->withAttribute('params', ['controller' => 'Auth', 'action' => 'login', 'plugin' => null]);
            Router::setRequest($request);
            $service = (new Application(ROOT . '/config'))->getAuthenticationService($request);
            $loginUrl = $base . '/login';
            self::assertSame($loginUrl, $service->getConfig('unauthenticatedRedirect'));
            self::assertSame($loginUrl, $service->authenticators()->get('Form')->getConfig('loginUrl'));
            self::assertTrue((new DefaultUrlChecker())->check($request, $loginUrl));
            $controller = new Controller($request);
            self::assertSame('http://localhost' . $base . '/pcm', $controller->redirect('/pcm')->getHeaderLine('Location'));
        }
        Router::reload();
    }
}

