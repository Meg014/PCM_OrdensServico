<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use InvalidArgumentException;
use RuntimeException;

final class ProtheusRepository
{
    private Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        $connection ??= ConnectionManager::get('protheus');
        if (!$connection instanceof Connection || !$connection->getDriver() instanceof ProtheusReadOnly) {
            throw new RuntimeException('O datasource protheus exige o driver ProtheusReadOnly.');
        }
        $this->connection = $connection;
    }

    public function health(): bool
    {
        return (int)$this->read(ProtheusQueries::HEALTH)[0]['connection_ok'] === 1;
    }

    /** Returns original Protheus fields; unknown field semantics are deliberately not inferred. */
    public function findOrder(string $numero, ?string $filial = null): ?array
    {
        $numero = trim($numero);
        if ($numero === '' || strlen($numero) > 50 || preg_match('/^[0-9A-Za-z]+$/D', $numero) !== 1) {
            throw new InvalidArgumentException('Número da OS inválido. Preserve os zeros à esquerda.');
        }
        $params = ['numero' => $numero];
        $sql = ProtheusQueries::ORDER;
        if ($filial !== null) {
            $params['filial'] = rtrim($filial);
            $sql = ProtheusQueries::ORDER_BRANCH;
        }
        $order = $this->one($sql, $params);
        if ($order === null) {
            return null;
        }
        $description = $order['pcm_descricao'];
        unset($order['TJ_OBSERVA'], $order['pcm_descricao']);
        $result = [
            'numero' => $numero,
            'dados_principais' => $order,
            'descricao' => $description,
            'equipamento' => $this->one(ProtheusQueries::EQUIPMENT, ['codigo' => $order['TJ_CODBEM']]),
            'servico' => $this->one(ProtheusQueries::SERVICE, ['codigo' => $order['TJ_SERVICO']]),
            'mao_de_obra' => [],
            'materiais' => [],
            'outros_apontamentos' => [],
        ];
        $entries = $this->read(ProtheusQueries::ENTRIES, ['numero' => $numero, 'filial' => $order['TJ_FILIAL']]);
        foreach ($entries as $entry) {
            switch ($entry['TL_TIPOREG']) {
                case 'M':
                    $result['mao_de_obra'][] = [
                        'apontamento' => $entry,
                        'profissional' => $this->one(ProtheusQueries::PROFESSIONAL, ['codigo' => $entry['TL_CODIGO']]),
                    ];
                    break;
                case 'P':
                    $result['materiais'][] = [
                        'apontamento' => $entry,
                        'produto' => $this->one(ProtheusQueries::PRODUCT, ['codigo' => $entry['TL_CODIGO']]),
                    ];
                    break;
                default:
                    $result['outros_apontamentos'][] = $entry;
            }
        }

        return $result;
    }

    private function one(string $sql, array $params): ?array
    {
        $rows = $this->read($sql, $params);
        if (count($rows) > 1) {
            throw new RuntimeException('Chave Protheus ambígua. Informe --filial para a OS; valide o compartilhamento dos cadastros se persistir.');
        }

        return $rows[0] ?? null;
    }

    /**
     * Shared read boundary for detail and future paginated equipment-history queries.
     * Future SQL must also be registered in ProtheusQueries; history should call read()
     * directly rather than findOrder() per row (which loads all resource entries).
     */
    private function read(string $sql, array $params = []): array
    {
        $statement = $this->connection->execute($sql, $params, array_fill_keys(array_keys($params), 'string'));
        try {
            $rows = $statement->fetchAll('assoc');
        } finally {
            $statement->closeCursor();
        }
        foreach ($rows as &$row) {
            foreach ($row as &$value) {
                if (is_string($value)) {
                    $value = rtrim($value, ' ');
                }
            }
            unset($value);
        }
        unset($row);

        return $rows;
    }
}
