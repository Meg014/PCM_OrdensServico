<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Import\DuplicateReportException;
use App\Service\Import\PendingReportLocator;
use App\Service\Import\ReportFileProcessor;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Log\Log;
use Throwable;

final class ImportPendingReportsCommand extends Command
{
    /** Scans configured reports once and imports every stable unseen snapshot. */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $startedAt = microtime(true);
        Log::info('Início da verificação automática de relatórios PCM.');
        $processor = new ReportFileProcessor();
        $processor->ensureDirectories();
        $files = (new PendingReportLocator())->scan();
        if ($files === []) {
            $io->out('Nenhum relatório CSV ou XLSX pendente encontrado.');
            Log::info(sprintf('Fim da verificação PCM: nenhum arquivo; duração=%.3fs.', microtime(true) - $startedAt));

            return static::CODE_SUCCESS;
        }
        $errors = 0;
        foreach ($files as $file) {
            $io->out('Arquivo encontrado: ' . $file['name']);
            Log::info(sprintf('Relatório encontrado: %s.', $file['name']));
            if (!$file['ready']) {
                $io->warning('Adiado: ' . $file['reason']);
                continue;
            }
            try {
                $hash = hash_file('sha256', $file['path']);
                $import = $processor->processIncomingFile($file['path']);
                $io->success(sprintf('Importado: %s — %d registros.', $file['name'], $import->rows_imported));
                Log::info(sprintf(
                    'Importação PCM concluída: arquivo=%s hash=%s registros=%d report_date=%s duração=%.3fs.',
                    $file['name'],
                    $hash ?: 'indisponível',
                    $import->rows_imported,
                    $import->report_date->format('Y-m-d'),
                    microtime(true) - $startedAt,
                ));
            } catch (DuplicateReportException) {
                $io->warning('Ignorado por duplicidade: ' . $file['name']);
                Log::info('Relatório ignorado por SHA-256 duplicado: ' . $file['name'] . '.');
            } catch (Throwable $exception) {
                $errors++;
                $io->error(sprintf('Erro em %s: %s', $file['name'], $exception->getMessage()));
                Log::error(sprintf('Falha na importação PCM de %s: %s', $file['name'], $exception->getMessage()));
            }
        }

        Log::info(sprintf('Fim da verificação PCM: erros=%d duração=%.3fs.', $errors, microtime(true) - $startedAt));

        return $errors === 0 ? static::CODE_SUCCESS : static::CODE_ERROR;
    }
}
