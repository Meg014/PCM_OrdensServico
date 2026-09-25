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

final class PcmDataImportCommand extends Command
{
    /** Defines the required transfer file argument. */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Importa um arquivo lógico PCM em um banco migrado e completamente vazio.')
            ->addArgument('file', ['required' => true, 'help' => 'Arquivo .jsonl previamente exportado.']);
    }

    /** Imports atomically into an empty default datasource. */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $counts = (new PcmDataTransferService(ConnectionManager::get('default')))
                ->import((string)$args->getArgument('file'));
            foreach ($counts as $table => $count) {
                $io->out(sprintf('%s: %d', $table, $count));
            }
            $io->success('Importação lógica concluída e validada.');

            return self::CODE_SUCCESS;
        } catch (Throwable $exception) {
            $io->error('Importação cancelada e revertida: ' . $exception->getMessage());

            return self::CODE_ERROR;
        }
    }
}
