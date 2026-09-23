<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Protheus\ProtheusRepository;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use PDOException;
use Throwable;

final class ProtheusHealthCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Testa somente leitura no Protheus; opcionalmente consulta uma OS em JSON.')
            ->addOption('os', ['help' => 'Número da OS, preservando zeros à esquerda.'])
            ->addOption('bem', ['help' => 'Código exato do equipamento para consultar seu histórico.'])
            ->addOption('pagina', ['help' => 'Página do histórico (a partir de 1).', 'default' => '1'])
            ->addOption('limite', ['help' => 'OS por página (1 a 100).', 'default' => '20'])
            ->addOption('filial', ['help' => 'Filial exata da OS, quando necessária para desambiguar.']);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        if ($args->getOption('os') !== null && $args->getOption('bem') !== null) {
            $io->error('Use --os ou --bem, separadamente.');

            return static::CODE_ERROR;
        }
        $page = filter_var($args->getOption('pagina'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($args->getOption('limite'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $limit === false || $page > intdiv(PHP_INT_MAX, $limit)) {
            $io->error('Use --pagina positiva e --limite entre 1 e 100.');

            return static::CODE_ERROR;
        }
        $historyQuery = false;
        try {
            $repository = new ProtheusRepository();
            if (!$repository->health()) {
                $io->error('A consulta SELECT 1 não retornou o resultado esperado.');

                return static::CODE_ERROR;
            }
            if ($args->getOption('bem') !== null) {
                $historyQuery = true;
                $history = $repository->findEquipmentHistory(
                    (string)$args->getOption('bem'), $args->getOption('filial'), $page, $limit,
                );
                // Summary deliberately omits users, full observations and internal record IDs.
                $history['orders'] = array_map(static fn(array $order): array => array_intersect_key($order, array_flip([
                    'TJ_FILIAL', 'TJ_ORDEM', 'TJ_CODBEM', 'equipment_name', 'TJ_SERVICO', 'service_name',
                    'TJ_TIPO', 'TJ_CODAREA', 'TJ_CCUSTO', 'TJ_SITUACA', 'TJ_TERMINO', 'reference_date',
                    'TJ_DTMPINI', 'TJ_HOMPINI', 'TJ_DTMPFIM', 'TJ_HOMPFIM',
                    'TJ_DTMRINI', 'TJ_HOMRINI', 'TJ_DTMRFIM', 'TJ_HOMRFIM',
                ])), $history['orders']);
                $io->out(json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return static::CODE_SUCCESS;
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
        } catch (Throwable $exception) {
            // Driver exceptions may contain server names, SQL or connection details.
            $io->error('Falha no Protheus. Verifique PROTHEUS_DB_*, pdo_sqlsrv/ODBC, rede, TLS e SELECT nas seis tabelas.');
            $io->error('Verifique também --os/--bem, --filial, campos e chaves duplicadas. Consulte docs/protheus-historico.md.');
            if ($historyQuery && PHP_SAPI === 'cli' && Configure::read('debug') === true) {
                $io->err($this->sanitizedHistoryError($exception, ConnectionManager::getConfig('protheus') ?? []));
            }

            return static::CODE_ERROR;
        }
    }

    /** Temporary development-only CLI diagnostic; never output query text or a trace. */
    private function sanitizedHistoryError(Throwable $exception, array $config): string
    {
        $state = null;
        do {
            if ($exception instanceof PDOException && isset($exception->errorInfo[0])) {
                $state = (string)$exception->errorInfo[0];
            } elseif (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $exception->getMessage(), $match)) {
                $state = $match[1];
            }
            $cause = $exception;
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        $message = $cause instanceof PDOException && isset($cause->errorInfo[2])
            ? (string)$cause->errorInfo[2] : $cause->getMessage();
        $message = preg_split('/(?:\r?\n\s*Query:|\bSQL:\s|\bStack trace:)/i', $message, 2)[0];
        // Mask configured values first, including DSNs and encoded credentials.
        foreach (['password', 'username', 'host', 'database', 'url', 'dsn'] as $key) {
            $value = (string)($config[$key] ?? '');
            if ($value !== '') {
                $message = str_ireplace([$value, rawurlencode($value), urlencode($value)], '[redigido]', $message);
            }
        }
        $message = preg_replace(
            '/\b(password|pwd|username|user(?:\s+id)?|uid|host|server|database|senha|usuário|servidor)(?:\s*[=:]\s*|\s+)(?:\x27[^\x27]*\x27|"[^"]*"|[^\s;,]+)/iu',
            '$1 [redigido]',
            $message,
        ) ?? 'Mensagem técnica indisponível.';
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $message = str_replace(['<', '>'], ['[', ']'], $message);
        $state = is_string($state) && preg_match('/^[A-Z0-9]{5}$/D', $state) ? $state : 'indisponível';

        return 'Histórico Protheus — SQLSTATE: ' . $state . '; causa: ' . mb_substr($message, 0, 1200);
    }
}
