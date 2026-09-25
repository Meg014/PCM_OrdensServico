<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\DataTransfer\PcmDataTransferService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;
use Throwable;

final class PcmDataExportCommand extends Command
{
    /** Defines the required destination and explicit overwrite switch. */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Exporta logicamente os dados locais do PCM sem modificar o banco.')
            ->addArgument('file', ['required' => true, 'help' => 'Arquivo .jsonl de destino.'])
            ->addOption('overwrite', ['boolean' => true, 'default' => false]);
    }

    /** Runs a read-only logical export and prints per-table counts. */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $counts = (new PcmDataTransferService(ConnectionManager::get('default')))->export(
                (string)$args->getArgument('file'),
                (bool)$args->getOption('overwrite'),
            );
            $this->printCounts($io, $counts);
            $io->success('Exportação lógica concluída. O banco de origem não foi modificado.');

            return self::CODE_SUCCESS;
        } catch (Throwable $exception) {
            $io->error('Exportação recusada: ' . $exception->getMessage());

            return self::CODE_ERROR;
        }
    }

    /** Prints a compact export inventory. */
    private function printCounts(ConsoleIo $io, array $counts): void
    {
        foreach ($counts as $table => $count) {
            $io->out(sprintf('%s: %d', $table, $count));
        }
    }
}
