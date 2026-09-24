<?php
declare(strict_types=1);
namespace App\Test\TestCase\Controller;

use App\Application;
use App\Controller\AppController;
use App\Controller\ProfileController;
use App\Controller\UsersController;
use App\Middleware\NormalizeLoginEmailMiddleware;
use App\Model\Entity\User;
use App\Model\Table\UsersTable;
use App\Service\UserEmailAudit;
use Authentication\Identity;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\FactoryLocator;
use Cake\Event\Event;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\Locator\TableLocator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

/** Isolated SQLite only. Does not load application bootstrap or run migrations. */
final class PasswordProfileTest extends TestCase
{
    private UsersTable $users;
    private Connection $connection;
    private TableLocator $locator;

    protected function setUp(): void
    {
        if (!defined('ROOT')) require dirname(__DIR__, 3) . '/config/paths.php';
        require_once CAKE . 'Core/functions_global.php';
        Configure::write('App.namespace', 'App');
        Configure::write('App.encoding', 'UTF-8');
        if (!Cache::getConfig('_cake_translations_')) Cache::setConfig('_cake_translations_', ['className' => \Cake\Cache\Engine\NullEngine::class]);
        $this->connection = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);
        $this->connection->execute('CREATE TABLE maintenance_areas (id INTEGER PRIMARY KEY, source_code TEXT)');
        $this->connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT UNIQUE, password TEXT, role TEXT, ativo BOOLEAN, maintenance_area_id INTEGER NULL, must_change_password BOOLEAN DEFAULT 0 NOT NULL, created DATETIME, modified DATETIME)');
        $this->connection->execute('CREATE TABLE tv_devices (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT, expires_at DATETIME)');
        $this->locator = new TableLocator();
        foreach (['Users','MaintenanceAreas','TvDevices'] as $name) $this->locator->setConfig($name, ['connection' => $this->connection]);
        FactoryLocator::add('Table', $this->locator);
        $this->users = $this->locator->get('Users');
    }

    protected function tearDown(): void
    {
        $this->locator->clear();
        $this->connection->getDriver()->disconnect();
        FactoryLocator::add('Table', new TableLocator());
    }

    private function user(string $role, string $email = ''): User
    {
        $user = $this->users->newEntity(['nome' => $role, 'email' => $email ?: strtolower($role).'@example.com',
            'password' => 'Initial-password-2026', 'role' => $role, 'ativo' => true]);
        $this->users->saveOrFail($user);
        return $this->users->get($user->id);
    }

    private function session(?User $user = null): Session
    {
        $data = $user ? ['Auth' => $user] : [];
        $session = $this->createMock(Session::class);
        $session->method('check')->willReturnCallback(static function ($key) use (&$data) { return isset($data[$key]); });
        $session->method('read')->willReturnCallback(static function ($key = null) use (&$data) { return $key === null ? $data : ($data[$key] ?? null); });
        $session->method('write')->willReturnCallback(static function ($key, $value = null) use (&$data): void { $data[$key] = $value; });
        $session->method('delete')->willReturnCallback(static function ($key) use (&$data): void { unset($data[$key]); });
        return $session;
    }

    private function controller(string $class, User $user, string $name, string $action, string $path, array $post = []): AppController
    {
        $request = new ServerRequest(['url' => $path, 'environment' => ['REQUEST_METHOD' => $post ? 'POST' : 'GET'],
            'params' => ['controller' => $name, 'action' => $action], 'post' => $post, 'session' => $this->session($user)]);
        $auth = (new Application(CONFIG))->getAuthenticationService($request);
        $auth->authenticate($request);
        $request = $request->withAttribute('identity', new Identity($user))->withAttribute('authentication', $auth);
        $controller = new $class($request);
        $controller->setTableLocator($this->locator);
        return $controller;
    }

    public function testEmailLoginForAdminUsuarioAndInvalidCredentials(): void
    {
        foreach (['ADMIN','USUARIO'] as $role) $this->user($role);
        foreach ([[' ADMIN@EXAMPLE.COM ', 'Initial-password-2026', true], ['USUARIO@example.com', 'Initial-password-2026', true],
            ['admin@example.com', 'wrong', false], ['invalid-email', 'Initial-password-2026', false]] as [$email,$password,$valid]) {
            $request = new ServerRequest(['url' => '/login', 'environment' => ['REQUEST_METHOD' => 'POST'],
                'params' => ['controller' => 'Auth', 'action' => 'login'], 'post' => compact('email','password'), 'session' => $this->session()]);
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->method('handle')->willReturnCallback(function ($normalized) use ($valid) {
                $auth = (new Application(CONFIG))->getAuthenticationService($normalized);
                self::assertSame($valid, $auth->authenticate($normalized)->isValid());
                return new Response();
            });
            (new NormalizeLoginEmailMiddleware())->process($request, $handler);
        }
    }

    public function testForcedPasswordCannotBeBypassedAndDoesNotAffectTv(): void
    {
        foreach (['ADMIN','USUARIO'] as $role) {
            $user = $this->user($role);
            self::assertTrue($user->must_change_password);
            foreach ([['Auth','login','/login'], ['Pcm','index','/pcm'], ['Pcm','equipment','/pcm/equipamento'],
                ['Pcm','sectorData','/pcm/setor/MECANI/data'], ['Pcm','presentation','/pcm/apresentacao']] as [$name,$action,$path]) {
                $controller = $this->controller(AppController::class, $user, $name, $action, $path);
                $event = new Event('Controller.initialize', $controller);
                $controller->beforeFilter($event);
                self::assertTrue($event->isStopped());
                self::assertSame('/meu-perfil', $event->getResult()->getHeaderLine('Location'));
            }
            $controller = $this->controller(ProfileController::class, $user, 'Profile', 'index', '/meu-perfil');
            $event = new Event('Controller.initialize', $controller);
            $controller->beforeFilter($event);
            self::assertFalse($event->isStopped());
        }
        $tv = $this->user('TV');
        self::assertFalse($tv->must_change_password);
        // Even an old/incorrect flag on TV must not redirect the presentation.
        $this->users->updateAll(['must_change_password' => true], ['id' => $tv->id]);
        foreach (['presentation' => '/pcm/apresentacao', 'presentationData' => '/pcm/apresentacao/data'] as $action => $path) {
            $controller = $this->controller(AppController::class, $tv, 'Pcm', $action, $path);
            $event = new Event('Controller.initialize', $controller);
            $controller->beforeFilter($event);
            self::assertFalse($event->isStopped());
        }
        foreach ([['Profile','index','/meu-perfil'], ['Pcm','index','/pcm'], ['Users','index','/usuarios']] as [$name,$action,$path]) {
            $controller = $this->controller(AppController::class, $tv, $name, $action, $path);
            try { $controller->beforeFilter(new Event('Controller.initialize', $controller)); self::fail('TV access'); }
            catch (ForbiddenException) { self::assertTrue(true); }
        }
    }

    public function testOwnPasswordChangesOnlyAuthenticatedUserAndRefreshesSession(): void
    {
        $user = $this->user('USUARIO');
        $admin = $this->user('ADMIN');
        $oldHash = $user->password;
        $controller = $this->controller(ProfileController::class, $user, 'Profile', 'index', '/meu-perfil', [
            'new_password' => 'Personal-password-2026', 'password_confirm' => 'Personal-password-2026',
            'id' => $admin->id, 'email' => 'attacker@example.com', 'nome' => 'attacker', 'role' => 'ADMIN', 'ativo' => false,
            'maintenance_area_id' => 99, 'must_change_password' => true]);
        $controller->beforeFilter(new Event('Controller.initialize', $controller));
        self::assertSame('/pcm', $controller->index()->getHeaderLine('Location'));
        $saved = $this->users->get($user->id);
        self::assertFalse($saved->must_change_password);
        self::assertSame('USUARIO', $saved->role);
        self::assertSame('USUARIO', $saved->nome);
        self::assertSame('usuario@example.com', $saved->email);
        self::assertNull($saved->maintenance_area_id);
        self::assertTrue($saved->ativo);
        self::assertTrue(password_verify('Personal-password-2026', $saved->password));
        self::assertSame($admin->password, $this->users->get($admin->id)->password);
        self::assertArrayNotHasKey('password', $saved->toArray());
        self::assertSame($saved->password, $controller->getRequest()->getSession()->read('Auth')->password);
        $stale = $this->controller(AppController::class, $user, 'Pcm', 'index', '/pcm');
        $event = new Event('Controller.initialize', $stale);
        $stale->beforeFilter($event);
        self::assertSame('/login', $event->getResult()->getHeaderLine('Location'));
        self::assertNotSame($oldHash, $saved->password);
    }

    public function testVoluntaryChangeRequiresCurrentPasswordAndMatchingConfirmation(): void
    {
        $user = $this->user('USUARIO');
        $this->users->updateAll(['must_change_password' => false], ['id' => $user->id]);
        foreach ([['wrong','New-password-2026'], ['Initial-password-2026','mismatch']] as [$current,$confirm]) {
            $entity = $this->users->get($user->id);
            self::assertFalse($this->users->changeOwnPassword($entity, ['current_password' => $current,
                'new_password' => 'New-password-2026', 'password_confirm' => $confirm]));
            self::assertSame($user->password, $this->users->get($user->id)->password);
        }
        self::assertTrue($this->users->changeOwnPassword($this->users->get($user->id), [
            'current_password' => 'Initial-password-2026', 'new_password' => 'New-password-2026', 'password_confirm' => 'New-password-2026']));
    }

    public function testOnlyAdminResetsOthersAndTvResetNeverRequiresChange(): void
    {
        $admin = $this->user('ADMIN');
        $user = $this->user('USUARIO');
        $tv = $this->user('TV');
        $this->users->updateAll(['must_change_password' => false], ['id IN' => [$admin->id,$user->id]]);
        $admin = $this->users->get($admin->id);
        foreach (['index','add','edit','password','revokeTv'] as $action) {
            $controller = $this->controller(UsersController::class, $user, 'Users', $action, '/usuarios');
            try { $controller->beforeFilter(new Event('Controller.initialize', $controller)); self::fail('User administration'); }
            catch (ForbiddenException) { self::assertTrue(true); }
        }
        foreach ([$user,$tv] as $target) {
            $controller = $this->controller(UsersController::class, $admin, 'Users', 'password', '/usuarios/'.$target->id.'/senha', ['password' => 'Temporary-reset-2026']);
            $controller->beforeFilter(new Event('Controller.initialize', $controller));
            self::assertSame('/usuarios', $controller->password($target->id)->getHeaderLine('Location'));
            $saved = $this->users->get($target->id);
            self::assertTrue(password_verify('Temporary-reset-2026', $saved->password));
            self::assertSame($target->role !== 'TV', $saved->must_change_password);
        }
        $changed = $this->users->get($user->id);
        $controller = $this->controller(AppController::class, $changed, 'Pcm', 'index', '/pcm');
        $event = new Event('Controller.initialize', $controller);
        $controller->beforeFilter($event);
        self::assertSame('/meu-perfil', $event->getResult()->getHeaderLine('Location'));
    }

    public function testEmailAuditNormalizationAndDuplicateRejection(): void
    {
        $user = $this->user('USUARIO', ' Person@Example.Com ');
        self::assertSame('person@example.com', $user->email);
        $admin = $this->user('ADMIN');
        $controller = $this->controller(UsersController::class, $admin, 'Users', 'edit', '/usuarios/'.$user->id.'/editar', ['email' => ' Replacement@Example.Com ']);
        // Complete the first access for this test administrator; the edit itself remains the real action.
        $this->users->updateAll(['must_change_password' => false], ['id' => $admin->id]);
        $controller->beforeFilter(new Event('Controller.initialize', $controller));
        self::assertSame('/usuarios', $controller->edit($user->id)->getHeaderLine('Location'));
        self::assertSame('replacement@example.com', $this->users->get($user->id)->email);
        $duplicate = $this->users->newEntity(['nome' => 'Duplicate', 'email' => ' REPLACEMENT@example.com ',
            'role' => 'ADMIN', 'ativo' => true, 'password' => 'Initial-password-2026']);
        self::assertFalse($this->users->save($duplicate));
        self::assertNotEmpty($duplicate->getError('email'));
        $audit = UserEmailAudit::inspect([['id'=>1,'email'=>''], ['id'=>2,'email'=>' A@example.com '], ['id'=>3,'email'=>'a@example.com']]);
        self::assertCount(2, $audit['issues']);
        self::assertSame('a@example.com', $audit['changes'][2]);
    }

    public function testMigrationPreservesAccountsAndStopsBeforeSchemaChangeOnConflict(): void
    {
        require_once ROOT . '/config/Migrations/20260924160000_AddPasswordChangeRequirement.php';
        foreach ([false, true] as $conflict) {
            $db = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);
            $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password TEXT, role TEXT)');
            $db->execute("INSERT INTO users VALUES (1, ' Person@Example.Com ', 'unchanged-hash', 'ADMIN')");
            if ($conflict) $db->execute("INSERT INTO users VALUES (2, 'person@example.com', 'other-hash', 'USUARIO')");
            $migration = new \AddPasswordChangeRequirement();
            $migration->setAdapter(new \Migrations\Db\Adapter\SqliteAdapter(['connection' => $db]));
            if ($conflict) {
                try { $migration->up(); self::fail('Conflicting accounts must block migration'); }
                catch (\RuntimeException $e) { self::assertStringContainsString('IDs 1, 2', $e->getMessage()); }
                self::assertNotContains('must_change_password', $db->getSchemaCollection()->describe('users')->columns());
                self::assertSame(' Person@Example.Com ', $db->execute('SELECT email FROM users WHERE id = 1')->fetch('assoc')['email']);
            } else {
                $migration->up();
                $row = $db->execute('SELECT * FROM users WHERE id = 1')->fetch('assoc');
                self::assertSame('person@example.com', $row['email']);
                self::assertSame('unchanged-hash', $row['password']);
                self::assertSame(0, (int)$row['must_change_password']);
            }
            $db->getDriver()->disconnect();
        }
    }
}
