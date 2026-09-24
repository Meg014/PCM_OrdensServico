<?php
declare(strict_types=1);

use App\Service\UserEmailAudit;
use Migrations\BaseMigration;

final class AddPasswordChangeRequirement extends BaseMigration
{
    public function up(): void
    {
        // Check before DDL: never invent an address or merge conflicting accounts.
        $audit = UserEmailAudit::inspect($this->fetchAll('SELECT id, email FROM users'));
        if ($audit['issues']) {
            throw new RuntimeException("Regularize os usuários antes da migração:\n" . implode("\n", $audit['issues']));
        }
        $this->table('users')->addColumn('must_change_password', 'boolean', ['default' => false, 'null' => false])->update();
        foreach ($audit['changes'] as $id => $email) {
            $this->execute('UPDATE users SET email = ? WHERE id = ?', [$email, $id]);
        }
    }

    public function down(): void
    {
        $this->table('users')->removeColumn('must_change_password')->update();
    }
}
