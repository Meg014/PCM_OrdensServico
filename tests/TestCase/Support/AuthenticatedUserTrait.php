<?php
declare(strict_types=1);

namespace App\Test\TestCase\Support;

use Cake\Datasource\FactoryLocator;

trait AuthenticatedUserTrait
{
    private ?int $authenticatedUserId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $users = FactoryLocator::get('Table')->get('Users');
        $user = $users->newEntity([
            'nome' => 'Admin Teste', 'email' => 'admin-' . bin2hex(random_bytes(6)) . '@example.com',
            'password' => 'Test-password-2026', 'role' => 'ADMIN', 'ativo' => true,
        ]);
        $users->saveOrFail($user);
        $this->authenticatedUserId = (int)$user->id;
        $this->session(['Auth' => $user]);
    }

    protected function tearDown(): void
    {
        if ($this->authenticatedUserId !== null) {
            FactoryLocator::get('Table')->get('Users')->deleteAll(['id' => $this->authenticatedUserId]);
        }
        parent::tearDown();
    }
}
