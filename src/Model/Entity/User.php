<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;

class User extends Entity
{
    protected array $_accessible = [
        'nome' => true, 'email' => true, 'password' => true, 'role' => true,
        'maintenance_area_id' => true, 'ativo' => true,
    ];

    protected array $_hidden = ['password'];

    /** Hashes every new password before persistence. */
    protected function _setPassword(string $password): string
    {
        return (new DefaultPasswordHasher())->hash($password);
    }

    /** Normalizes stored email addresses. */
    protected function _setEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
