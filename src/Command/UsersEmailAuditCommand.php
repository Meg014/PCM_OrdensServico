<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\UserEmailAudit;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

final class UsersEmailAuditCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $audit = UserEmailAudit::inspect($this->fetchTable('Users')->find()->select(['id', 'email'])->enableHydration(false)->all());
            $io->out(sprintf('Contas verificadas: %d. E-mails a normalizar: %d.', $audit['count'], count($audit['changes'])));
            foreach ($audit['issues'] as $issue) $io->err($issue);
            if ($audit['issues']) return static::CODE_ERROR;
            $io->success('E-mails compatíveis. Nenhum dado foi alterado.');
            return static::CODE_SUCCESS;
        } catch (\Throwable) {
            $io->error('Não foi possível verificar usuários no banco local do PCM. Nenhum dado foi alterado.');
            return static::CODE_ERROR;
        }
    }
}
