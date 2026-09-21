<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\Datasource\FactoryLocator;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class AuthUsersControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use PcmSnapshotFixture;

    private object $admin;
    private object $user;

    protected function setUp(): void
    {
        parent::setUp();
        self::connection()->begin();
        $ids = self::seedValidatedSnapshot();
        $table = FactoryLocator::get('Table')->get('Users');
        foreach (['admin' => 'ADMIN', 'user' => 'USUARIO'] as $key => $role) {
            $this->{$key} = $table->newEntity([
                'nome' => $key, 'email' => $key . '@example.com', 'password' => 'Test-password-2026',
                'role' => $role, 'ativo' => true, 'maintenance_area_id' => $ids['mechanicalId'],
            ]);
            $table->saveOrFail($this->{$key});
        }
        $this->enableCsrfToken();
    }

    protected function tearDown(): void
    {
        self::connection()->rollback();
        parent::tearDown();
    }

    public function testLoginAndHash(): void
    {
        $this->assertNotSame('Test-password-2026', $this->admin->password);
        $this->assertTrue(password_verify('Test-password-2026', $this->admin->password));
        $this->assertArrayNotHasKey('password', $this->admin->toArray());
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'Test-password-2026']);
        $this->assertRedirect('/pcm');
        $this->assertSession('admin@example.com', 'Auth.email');
    }

    public function testWrongPassword(): void
    {
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
        $this->assertResponseOk();
        $this->assertResponseContains('E-mail ou senha inválidos');
        $this->assertSession(null, 'Auth');
    }

    public function testInactiveUserCannotLogin(): void
    {
        FactoryLocator::get('Table')->get('Users')->updateAll(['ativo' => false], ['id' => $this->admin->id]);
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'Test-password-2026']);
        $this->assertResponseOk();
        $this->assertSession(null, 'Auth');
    }

    public function testDeactivationRevokesSession(): void
    {
        $this->session(['Auth' => $this->admin]);
        FactoryLocator::get('Table')->get('Users')->updateAll(['ativo' => false], ['id' => $this->admin->id]);
        $this->get('/pcm');
        $this->assertRedirect('/login');
    }

    public function testLogout(): void
    {
        $this->session(['Auth' => $this->admin]);
        $this->post('/logout');
        $this->assertRedirect('/login');
        $this->assertSession(null, 'Auth');
        $this->session(['Auth' => null]);
        $this->get('/pcm');
        $this->assertRedirect('/login');
    }

    public function testProtectedPagesRequireLogin(): void
    {
        foreach (['/pcm', '/usuarios', '/pcm/apresentacao', '/pcm/apresentacao/data', '/importacoes', '/users/index'] as $url) {
            $this->get($url);
            $this->assertRedirect('/login');
        }
    }

    public function testAdminCanManageUsers(): void
    {
        $this->session(['Auth' => $this->admin]);
        $this->get('/usuarios');
        $this->assertResponseOk();
        $this->post('/usuarios/novo', ['nome' => 'Novo', 'email' => 'novo@example.com',
            'password' => 'Other-password-2026', 'role' => 'USUARIO', 'ativo' => true]);
        $this->assertRedirect('/usuarios');
        $this->assertTrue(FactoryLocator::get('Table')->get('Users')->exists(['email' => 'novo@example.com']));
        $this->post('/usuarios/' . $this->user->id . '/editar', ['nome' => 'Editado', 'ativo' => false]);
        $this->assertRedirect('/usuarios');
        $this->assertFalse(FactoryLocator::get('Table')->get('Users')->get($this->user->id)->ativo);
    }

    public function testUserCannotManageUsersOrImport(): void
    {
        $this->session(['Auth' => $this->user]);
        foreach (['/usuarios', '/usuarios/novo', '/usuarios/' . $this->admin->id . '/editar', '/users/index', '/importacoes/manual'] as $url) {
            $this->get($url);
            $this->assertResponseCode(403);
        }
        $this->post('/usuarios/' . $this->admin->id . '/senha', ['password' => 'Malicious-password']);
        $this->assertResponseCode(403);
    }

    public function testAssignedSectorDoesNotRestrictOtherSectors(): void
    {
        $this->session(['Auth' => $this->user]);
        $this->get('/pcm/setor/ELETRI');
        $this->assertResponseOk();
        $this->assertResponseContains('Elétrica');
    }

    public function testPasswordResetRevokesOldSession(): void
    {
        $this->session(['Auth' => $this->admin]);
        $this->post('/usuarios/' . $this->user->id . '/senha', ['password' => 'Changed-password-2026']);
        $this->assertRedirect('/usuarios');
        $this->session(['Auth' => $this->user]);
        $this->get('/pcm');
        $this->assertRedirect('/login');
    }

    public function testCsrfIsRequired(): void
    {
        $this->_csrfToken = false;
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'Test-password-2026']);
        $this->assertResponseCode(403);
    }

    public function testPaginationPreservesFilters(): void
    {
        $this->session(['Auth' => $this->user]);
        $this->get('/pcm/setor/MECANI?date_start=2026-01-01&date_end=2026-12-31&status=FECHADA&equipment=EQ-M1');
        $this->assertResponseOk();
        $this->assertResponseContains('date_start=2026-01-01');
        $this->assertResponseContains('date_end=2026-12-31');
        $this->assertResponseContains('equipment=EQ-M1');
        $this->assertResponseContains('page=2');
    }
}
