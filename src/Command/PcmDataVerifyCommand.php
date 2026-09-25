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

final class PcmDataVerifyCommand extends Command
{
    /** Defines the transfer file to compare. */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Compara o arquivo lógico com o banco PCM atual sem modificar dados.')
            ->addArgument('file', ['required' => true, 'help' => 'Arquivo .jsonl de transferência.']);
    }

    /** Compares the file to the default datasource without writing. */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $result = (new PcmDataTransferService(ConnectionManager::get('default')))
                ->verify((string)$args->getArgument('file'));
            foreach ($result['tables'] as $table => $check) {
                $io->out(sprintf(
                    '%s: arquivo=%d banco=%d IDs=%s dados=%s',
                    $table,
                    $check['expected']['count'],
                    $check['actual']['count'],
                    $check['expected']['id_sha256'] === $check['actual']['id_sha256'] ? 'OK' : 'DIVERGENTE',
                    $check['expected']['data_sha256'] === $check['actual']['data_sha256'] ? 'OK' : 'DIVERGENTE',
                ));
            }
            foreach ($result['relationships'] as $relationship => $count) {
                $io->out(sprintf('%s: órfãos=%d', $relationship, $count));
            }
            if (!$result['valid']) {
                $io->error('Verificação encontrou divergências. Nenhum dado foi modificado.');

                return self::CODE_ERROR;
            }
            $io->success('Arquivo e banco são equivalentes; relacionamentos íntegros.');

            return self::CODE_SUCCESS;
        } catch (Throwable $exception) {
            $io->error('Verificação falhou: ' . $exception->getMessage());

            return self::CODE_ERROR;
        }
    }
}
