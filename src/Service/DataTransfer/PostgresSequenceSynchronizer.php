<?php
declare(strict_types=1);

namespace App\Service\DataTransfer;

use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use RuntimeException;

final class PostgresSequenceSynchronizer
{
    /** Advances every PostgreSQL identity after explicit ID inserts. */
    public function synchronize(Connection $connection): void
    {
        $driver = $connection->getDriver();
        if (!$driver instanceof Postgres) {
            throw new RuntimeException('Sincronização de sequences exige o driver PostgreSQL.');
        }
        foreach (PcmTransferSchema::TABLES as $table) {
            $row = $connection->execute(
                "SELECT pg_get_serial_sequence('{$table}', 'id') sequence_name, MAX(id) max_id FROM {$table}",
            )->fetch('assoc');
            if (!is_array($row) || !is_string($row['sequence_name'] ?? null)) {
                throw new RuntimeException("Sequence da tabela {$table} não encontrada.");
            }
            $next = isset($row['max_id']) ? (int)$row['max_id'] + 1 : 1;
            $connection->execute($this->restartStatement($row['sequence_name'], $next, $driver));
        }
    }

    /** Builds a transactional sequence restart using only validated identifiers. */
    public function restartStatement(string $sequence, int $next, Postgres $driver): string
    {
        $parts = explode('.', $sequence);
        if (
            $next < 1 || count($parts) > 2 || array_filter(
                $parts,
                static fn(string $part): bool => preg_match('/^[a-z_][a-z0-9_$]*$/i', $part) !== 1,
            )
        ) {
            throw new RuntimeException('Nome de sequence ou próximo ID inválido.');
        }
        $quoted = implode('.', array_map($driver->quoteIdentifier(...), $parts));

        return "ALTER SEQUENCE {$quoted} RESTART WITH {$next}";
    }
}
