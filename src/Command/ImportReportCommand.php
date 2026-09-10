<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ReportFileProcessor;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

final class ImportReportCommand extends Command
{
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->setDescription('Importa um relatório CSV ou XLSX do TOTVS.')
            ->addArgument('arquivo', ['help' => 'Caminho completo do relatório TOTVS', 'required' => true]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $import = (new ReportFileProcessor())->processFile((string)$args->getArgument('arquivo'));
            $io->success(sprintf('Importação %d concluída: %d registros.', $import->id, $import->rows_imported));

            return static::CODE_SUCCESS;
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return static::CODE_ERROR;
        }
    }
}
