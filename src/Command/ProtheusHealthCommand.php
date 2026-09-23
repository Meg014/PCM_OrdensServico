<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Protheus\ProtheusRepository;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

final class ProtheusHealthCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Testa somente leitura no Protheus; opcionalmente consulta uma OS em JSON.')
            ->addOption('os', ['help' => 'Número da OS, preservando zeros à esquerda.'])
            ->addOption('filial', ['help' => 'Filial exata da OS, quando necessária para desambiguar.']);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $repository = new ProtheusRepository();
            if (!$repository->health()) {
                $io->error('A consulta SELECT 1 não retornou o resultado esperado.');

                return static::CODE_ERROR;
            }
            if ($args->getOption('os') === null) {
                $io->success('Protheus: conexão OK; SELECT 1 OK; driver restrito a consultas permitidas.');

                return static::CODE_SUCCESS;
            }
            $order = $repository->findOrder((string)$args->getOption('os'), $args->getOption('filial'));
            if ($order === null) {
                $io->error('OS não encontrada entre os registros ativos.');

                return static::CODE_ERROR;
            }
            $io->out(json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return static::CODE_SUCCESS;
        } catch (Throwable) {
            // Driver exceptions may contain server names, SQL or connection details.
            $io->error('Falha no Protheus. Verifique PROTHEUS_DB_*, pdo_sqlsrv/ODBC, rede, TLS e SELECT nas seis tabelas.');
            $io->error('Na consulta de OS, verifique também número, --filial, campos e chaves duplicadas nos cadastros. Consulte docs/protheus.md.');

            return static::CODE_ERROR;
        }
    }
}
