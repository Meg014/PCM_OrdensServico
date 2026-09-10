<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\CurrentSnapshotService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Throwable;

final class PcmHealthCommand extends Command
{
    /** Checks application dependencies without exposing credentials. */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $errors = 0;
        $connected = false;
        try {
            $connection = ConnectionManager::get('default');
            $config = $connection->config();
            $driver = $connection->getDriver();
            $io->out('Datasource: default');
            $io->out('Driver: ' . $driver::class);
            $io->out('Host: ' . (string)($config['host'] ?? 'não configurado'));
            $io->out('Porta: ' . (string)($config['port'] ?? 'não configurada'));
            $io->out('Banco: ' . (string)($config['database'] ?? 'não configurado'));
            $connection->execute('SELECT 1')->fetchColumn(0);
            $connected = true;
            $io->success('Banco de dados: acessível.');
        } catch (Throwable) {
            $errors++;
            $io->error('Banco de dados: indisponível. Verifique DB_*, pdo_mysql, serviço MariaDB/MySQL e rede.');
        }
        if ($connected) {
            try {
                $io->out('Versão do servidor: ' . $driver->version());
            } catch (Throwable) {
                $errors++;
                $io->error('Versão do servidor: não foi possível consultar.');
            }
        }

        $reportPath = (string)Configure::read('Pcm.reports.incoming', '');
        $io->out('Pasta de relatórios: ' . ($reportPath !== '' ? $reportPath : 'não configurada'));
        if (!$this->canListReportDirectory($reportPath)) {
            $errors++;
            $io->error('Pasta de relatórios: inexistente ou sem permissão de leitura.');
        } else {
            $io->success('Pasta de relatórios: acessível para leitura.');
        }

        if ($connected) {
            try {
                $current = (new CurrentSnapshotService())->currentImport();
                if ($current === null) {
                    $io->warning('Última importação: nenhuma importação bem-sucedida.');
                } else {
                    $io->out('Última importação: ' . $current->file_name);
                    $io->out('report_date: ' . $current->report_date->format('Y-m-d'));
                    $io->out('registros: ' . $current->rows_imported);
                }
            } catch (Throwable) {
                $errors++;
                $io->error('Última importação: consulta indisponível. Verifique as migrations do datasource default.');
            }
        } else {
            $io->warning('Última importação: consulta não realizada por falta de conexão.');
        }

        return $errors === 0 ? static::CODE_SUCCESS : static::CODE_ERROR;
    }

    /**
     * Checks that the configured directory exists and can really be opened for listing.
     */
    private function canListReportDirectory(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (!is_dir($path)) {
            return false;
        }
        // A denied network share is a diagnostic result, not a PHP warning.
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        $handle = @opendir($path);
        if ($handle === false) {
            return false;
        }
        closedir($handle);

        return true;
    }
}
