<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\FactoryLocator;
use Cake\TestSuite\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionManager::get('test')->begin();
        FactoryLocator::get('Table')->get('Users')->deleteAll([]);
        putenv('PCM_ADMIN_NAME=First Admin');
        putenv('PCM_ADMIN_EMAIL=first@example.com');
        putenv('PCM_ADMIN_PASSWORD=First-password-2026');
    }

    protected function tearDown(): void
    {
        foreach (['PCM_ADMIN_NAME', 'PCM_ADMIN_EMAIL', 'PCM_ADMIN_PASSWORD'] as $name) {
            putenv($name);
        }
        ConnectionManager::get('test')->rollback();
        parent::tearDown();
    }

    public function testCreatesFirstAdminWithHashWithoutPrintingPassword(): void
    {
        $this->exec('create_admin');
        $this->assertExitSuccess();
        $this->assertOutputNotContains('First-password-2026');
        $user = FactoryLocator::get('Table')->get('Users')->find()->firstOrFail();
        $this->assertSame('ADMIN', $user->role);
        $this->assertTrue($user->ativo);
        $this->assertTrue(password_verify('First-password-2026', $user->password));
    }

    public function testRefusesAnotherBootstrapAdmin(): void
    {
        $users = FactoryLocator::get('Table')->get('Users');
        $users->saveOrFail($users->newEntity(['nome' => 'Existing', 'email' => 'existing@example.com',
            'password' => 'Existing-password-2026', 'role' => 'ADMIN', 'ativo' => true]));
        $this->exec('create_admin');
        $this->assertExitError();
        $this->assertSame(1, $users->find()->count());
    }

    public function testRejectsMissingSecret(): void
    {
        putenv('PCM_ADMIN_PASSWORD');
        $this->exec('create_admin');
        $this->assertExitError();
        $this->assertSame(0, FactoryLocator::get('Table')->get('Users')->find()->count());
    }
}
