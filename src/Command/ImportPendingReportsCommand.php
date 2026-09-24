<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

/** Retained command name: old scheduled jobs must never import operational data. */
final class ImportPendingReportsCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $io->error('Importação de planilhas desativada. Fonte operacional: Protheus.');

        return static::CODE_ERROR;
    }
}
