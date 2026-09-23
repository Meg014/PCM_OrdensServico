<?php
declare(strict_types=1);

namespace App\Database\Driver;

use App\Service\Protheus\ProtheusQueries;
use Cake\Database\Driver\Sqlserver;
use Cake\Database\Query;
use Cake\Database\StatementInterface;
use LogicException;
use PDO;
use RuntimeException;

/** Defense in depth; SQL Server credentials must also have SELECT-only permissions. */
final class ProtheusReadOnly extends Sqlserver
{
    private ?int $queryTimeout = null;

    /** Web reads are bounded without introducing session SQL or changing the allowlist. */
    public function limitQueryTime(int $seconds): void
    {
        $this->queryTimeout = max(1, min(5, $seconds));
        if (!defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            return;
        }
        if ($this->pdo !== null) {
            $this->pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $this->queryTimeout);
        }
    }

    public function connect(): void
    {
        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (trim((string)($this->_config[$key] ?? '')) === '') {
                throw new RuntimeException('Configure PROTHEUS_DB_HOST, DATABASE, USERNAME e PASSWORD.');
            }
        }
        // Never let local configuration introduce SQL executed during connection startup.
        foreach (['init', 'settings', 'attributes'] as $key) {
            if (!empty($this->_config[$key])) {
                throw new LogicException('Inicialização SQL customizada não permitida no Protheus.');
            }
        }
        parent::connect();
        if ($this->queryTimeout !== null) {
            $this->pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $this->queryTimeout);
        }
    }

    public function prepare(Query|string $query): StatementInterface
    {
        if (!is_string($query) || !ProtheusQueries::allows($query)) {
            throw new LogicException('Protheus somente leitura: consulta fora da lista permitida.');
        }

        return parent::prepare($query);
    }

    public function exec(string $sql): int|false
    {
        throw new LogicException('Execução direta bloqueada no Protheus. Use o repositório de leitura.');
    }
}
