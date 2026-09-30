<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\Datasource\FactoryLocator;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class UsersPasswordControllerTest extends TestCase
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
                'nome' => $key,
                'email' => $key . '-password-test@example.com',
                'password' => 'Original-password-2026',
                'role' => $role,
                'ativo' => true,
                'maintenance_area_id' => $ids['mechanicalId'],
            ]);
            $table->saveOrFail($this->{$key});
        }
        $table->updateAll(['must_change_password' => false], ['id IN' => [$this->admin->id, $this->user->id]]);
        $this->admin = $table->get($this->admin->id);
        $this->user = $table->get($this->user->id);
        $this->enableCsrfToken();
    }

    protected function tearDown(): void
    {
        self::connection()->rollback();
        parent::tearDown();
    }

    public function testAuthorizedGetDisplaysPasswordFormUsingPut(): void
    {
        $this->session(['Auth' => $this->admin]);

        $this->get('/usuarios/' . $this->user->id . '/senha');

        $this->assertResponseOk();
        $this->assertResponseContains('Redefinir senha');
        $this->assertResponseContains('name="password"');
        $this->assertResponseContains('name="_method" value="PUT"');
    }

    public function testValidGeneratedMethodChangesPassword(): void
    {
        $this->session(['Auth' => $this->admin]);

        $this->put('/usuarios/' . $this->user->id . '/senha', ['password' => 'Changed-password-2026']);

        $this->assertRedirect('/usuarios');
        $saved = FactoryLocator::get('Table')->get('Users')->get($this->user->id);
        $this->assertTrue(password_verify('Changed-password-2026', $saved->password));
        $this->assertTrue($saved->must_change_password);
    }

    public function testInvalidPasswordDoesNotChangePasswordAndDisplaysError(): void
    {
        $oldHash = $this->user->password;
        $this->session(['Auth' => $this->admin]);

        $this->put('/usuarios/' . $this->user->id . '/senha', ['password' => 'short']);

        $this->assertResponseOk();
        $this->assertResponseContains('Revise a nova senha.');
        $saved = FactoryLocator::get('Table')->get('Users')->get($this->user->id);
        $this->assertSame($oldHash, $saved->password);
    }

    public function testNonAdminCannotAccessPasswordReset(): void
    {
        $this->session(['Auth' => $this->user]);

        $this->get('/usuarios/' . $this->admin->id . '/senha');

        $this->assertResponseCode(403);
    }

    public function testMissingUserReturnsNotFound(): void
    {
        $this->session(['Auth' => $this->admin]);

        $this->get('/usuarios/999999999/senha');

        $this->assertResponseCode(404);
    }
}
