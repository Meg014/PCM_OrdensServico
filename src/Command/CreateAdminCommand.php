<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

class CreateAdminCommand extends Command
{
    /** Describes the environment-only bootstrap interface. */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->setDescription(
            'Cria o primeiro ADMIN usando PCM_ADMIN_NAME, PCM_ADMIN_EMAIL e PCM_ADMIN_PASSWORD.',
        );
    }

    /** Creates the initial administrator without disclosing credentials. */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $users = $this->fetchTable('Users');
        if ($users->exists(['role' => 'ADMIN'])) {
            $io->err('Já existe um ADMIN. Use a administração de usuários.');

            return self::CODE_ERROR;
        }
        $user = $users->newEntity([
            'nome' => env('PCM_ADMIN_NAME', ''), 'email' => env('PCM_ADMIN_EMAIL', ''),
            'password' => env('PCM_ADMIN_PASSWORD', ''), 'role' => 'ADMIN', 'ativo' => true,
        ]);
        if (!$users->save($user)) {
            $io->err('Não foi possível criar o ADMIN. Confira as variáveis PCM_ADMIN_*: '
                . 'nome, e-mail único e senha de 12 a 72 caracteres (máximo 72 bytes).');

            return self::CODE_ERROR;
        }
        $io->success('Primeiro ADMIN criado. Acesse /login.');

        return self::CODE_SUCCESS;
    }
}
