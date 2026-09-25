<?php
declare(strict_types=1);

namespace App\Service\DataTransfer;

use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class PcmDataTransferService
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** Creates a transfer service bound exclusively to the supplied PCM connection. */
    public function __construct(
        private readonly Connection $connection,
        private readonly PostgresSequenceSynchronizer $sequences = new PostgresSequenceSynchronizer(),
    ) {
    }

    /** Exports a transactionally consistent, read-only logical transfer file. */
    public function export(string $path, bool $overwrite = false): array
    {
        $path = $this->validatedOutputPath($path, $overwrite);
        $temporary = $path . '.part-' . bin2hex(random_bytes(6));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário de transferência.');
        }
        try {
            $counts = $this->connection->transactional(function () use ($handle): array {
                $this->writeLine($handle, [
                    'record' => 'header',
                    'format' => PcmTransferSchema::FORMAT,
                    'version' => PcmTransferSchema::VERSION,
                    'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
                    'tables' => PcmTransferSchema::TABLES,
                ]);
                $counts = [];
                $summaries = [];
                foreach (PcmTransferSchema::TABLES as $table) {
                    $summary = $this->exportTable($handle, $table);
                    $counts[$table] = $summary['count'];
                    $summaries[$table] = $summary;
                }
                $this->writeLine($handle, [
                    'record' => 'end',
                    'counts' => $counts,
                    'manifest_sha256' => $this->digest($summaries),
                ]);

                return $counts;
            });
            if (!fflush($handle)) {
                throw new RuntimeException('Não foi possível finalizar o arquivo de transferência.');
            }
            fclose($handle);
            $handle = null;
            $this->publishExport($temporary, $path, $overwrite);

            return $counts;
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw $exception;
        }
    }

    /** Imports into an empty migrated database and rolls everything back on failure. */
    public function import(string $path): array
    {
        $this->assertReadableFile($path);

        return $this->connection->transactional(function () use ($path): array {
            $this->assertDestinationEmpty();
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Não foi possível abrir o arquivo de transferência.');
            }
            try {
                $header = $this->readLine($handle, 1);
                $this->assertHeader($header);
                $counts = [];
                $summaries = [];
                $line = 1;
                foreach (PcmTransferSchema::TABLES as $table) {
                    [$summary, $line] = $this->importTable($handle, $table, $line);
                    $counts[$table] = $summary['count'];
                    $summaries[$table] = $summary;
                }
                $end = $this->readLine($handle, ++$line);
                if (
                    ($end['record'] ?? null) !== 'end'
                    || ($end['counts'] ?? null) !== $counts
                    || ($end['manifest_sha256'] ?? null) !== $this->digest($summaries)
                ) {
                    throw new RuntimeException('Manifesto final inválido ou divergente.');
                }
                if ($this->nextNonEmptyLine($handle) !== null) {
                    throw new RuntimeException('Conteúdo inesperado após o manifesto final.');
                }
            } finally {
                fclose($handle);
            }
            $this->assertRelationships();
            foreach ($summaries as $table => $expected) {
                if ($this->databaseSummary($table) !== $expected) {
                    throw new RuntimeException("Dados persistidos divergiram do arquivo na tabela {$table}.");
                }
            }
            if ($this->connection->getDriver() instanceof Postgres) {
                $this->sequences->synchronize($this->connection);
            }

            return $counts;
        });
    }

    /** Compares counts, IDs, normalized content checksums and essential relationships. */
    public function verify(string $path): array
    {
        $manifest = $this->readManifest($path);
        $result = ['valid' => true, 'tables' => [], 'relationships' => []];
        foreach (PcmTransferSchema::TABLES as $table) {
            $actual = $this->databaseSummary($table);
            $expected = $manifest[$table];
            $valid = $actual === $expected;
            $result['tables'][$table] = compact('valid', 'expected', 'actual');
            $result['valid'] = $result['valid'] && $valid;
        }
        foreach ($this->relationshipErrors() as $relationship => $count) {
            $result['relationships'][$relationship] = $count;
            $result['valid'] = $result['valid'] && $count === 0;
        }

        return $result;
    }

    /** Streams one table and returns its integrity summary. */
    private function exportTable(mixed $handle, string $table): array
    {
        $schema = $this->connection->getSchemaCollection()->describe($table);
        $columns = [];
        foreach ($schema->columns() as $column) {
            $columns[$column] = $schema->getColumnType($column);
        }
        $this->writeLine($handle, ['record' => 'table', 'table' => $table, 'columns' => $columns]);
        $context = $this->summaryContext();
        $statement = $this->connection->execute("SELECT * FROM {$table} ORDER BY id ASC");
        while (($row = $statement->fetch('assoc')) !== false) {
            $row = $this->normalizeRow($table, $row, $columns);
            $this->updateSummary($context, $row);
            $this->writeLine($handle, ['record' => 'row', 'table' => $table, 'data' => $row]);
        }
        $summary = $this->finishSummary($context);
        $this->writeLine($handle, ['record' => 'table_end', 'table' => $table] + $summary);

        return $summary;
    }

    /** Imports and validates one ordered table section. */
    private function importTable(mixed $handle, string $table, int $line): array
    {
        $start = $this->readLine($handle, ++$line);
        if (
            ($start['record'] ?? null) !== 'table' || ($start['table'] ?? null) !== $table
            || !is_array($start['columns'] ?? null)
        ) {
            throw new RuntimeException("Seção da tabela {$table} inválida na linha {$line}.");
        }
        $columns = $start['columns'];
        $destinationSchema = $this->connection->getSchemaCollection()->describe($table);
        $destinationColumns = $destinationSchema->columns();
        if (array_keys($columns) !== $destinationColumns) {
            throw new RuntimeException("Schema divergente para a tabela {$table}.");
        }
        $destinationTypes = [];
        foreach ($destinationColumns as $column) {
            $destinationTypes[$column] = in_array(
                $column,
                PcmTransferSchema::JSON_COLUMNS[$table] ?? [],
                true,
            ) ? 'json' : $destinationSchema->getColumnType($column);
        }
        $context = $this->summaryContext();
        while (true) {
            $record = $this->readLine($handle, ++$line);
            if (($record['record'] ?? null) === 'table_end') {
                $actual = $this->finishSummary($context);
                $expected = array_intersect_key($record, array_flip(['count', 'id_sha256', 'data_sha256']));
                if (($record['table'] ?? null) !== $table || $expected !== $actual) {
                    throw new RuntimeException("Checksum ou contagem inválida para {$table}.");
                }

                return [$actual, $line];
            }
            if (
                ($record['record'] ?? null) !== 'row' || ($record['table'] ?? null) !== $table
                || !is_array($record['data'] ?? null) || array_keys($record['data']) !== array_keys($columns)
            ) {
                throw new RuntimeException("Registro inválido para {$table} na linha {$line}.");
            }
            $row = $record['data'];
            $this->updateSummary($context, $row);
            $this->connection->insert(
                $table,
                $row,
                $destinationTypes,
            );
        }
    }

    /** Validates the complete file and returns its table summaries. */
    private function readManifest(string $path): array
    {
        $this->assertReadableFile($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo de transferência.');
        }
        try {
            $this->assertHeader($this->readLine($handle, 1));
            $manifest = [];
            $line = 1;
            foreach (PcmTransferSchema::TABLES as $table) {
                $start = $this->readLine($handle, ++$line);
                if (($start['record'] ?? null) !== 'table' || ($start['table'] ?? null) !== $table) {
                    throw new RuntimeException("Ordem de tabelas inválida na linha {$line}.");
                }
                $context = $this->summaryContext();
                while (true) {
                    $record = $this->readLine($handle, ++$line);
                    if (($record['record'] ?? null) === 'table_end') {
                        $summary = $this->finishSummary($context);
                        $expected = array_intersect_key($record, array_flip(['count', 'id_sha256', 'data_sha256']));
                        if (($record['table'] ?? null) !== $table || $summary !== $expected) {
                            throw new RuntimeException("Arquivo adulterado ou truncado na tabela {$table}.");
                        }
                        $manifest[$table] = $summary;
                        break;
                    }
                    if (
                        ($record['record'] ?? null) !== 'row' || ($record['table'] ?? null) !== $table
                        || !is_array($record['data'] ?? null)
                    ) {
                        throw new RuntimeException("Registro inválido na linha {$line}.");
                    }
                    $this->updateSummary($context, $record['data']);
                }
            }
            $end = $this->readLine($handle, ++$line);
            $counts = array_map(static fn(array $summary): int => $summary['count'], $manifest);
            if (
                ($end['record'] ?? null) !== 'end'
                || ($end['counts'] ?? null) !== $counts
                || ($end['manifest_sha256'] ?? null) !== $this->digest($manifest)
            ) {
                throw new RuntimeException('Manifesto final inválido.');
            }
            if ($this->nextNonEmptyLine($handle) !== null) {
                throw new RuntimeException('Conteúdo inesperado após o manifesto final.');
            }

            return $manifest;
        } finally {
            fclose($handle);
        }
    }

    /** Calculates the same canonical summary directly from the database. */
    private function databaseSummary(string $table): array
    {
        $schema = $this->connection->getSchemaCollection()->describe($table);
        $columns = [];
        foreach ($schema->columns() as $column) {
            $columns[$column] = $schema->getColumnType($column);
        }
        $context = $this->summaryContext();
        $statement = $this->connection->execute("SELECT * FROM {$table} ORDER BY id ASC");
        while (($row = $statement->fetch('assoc')) !== false) {
            $this->updateSummary($context, $this->normalizeRow($table, $row, $columns));
        }

        return $this->finishSummary($context);
    }

    /** Normalizes driver-specific values into the portable transfer representation. */
    private function normalizeRow(string $table, array $row, array $columns): array
    {
        $normalized = [];
        foreach ($columns as $column => $type) {
            $value = $row[$column] ?? null;
            if ($value !== null && $type === 'boolean') {
                if (is_string($value) && in_array(strtolower($value), ['t', 'f'], true)) {
                    $value = strtolower($value) === 't';
                } else {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                }
                if ($value === null) {
                    throw new RuntimeException("Valor booleano inválido em {$column}.");
                }
            } elseif (
                $value !== null
                && in_array($column, PcmTransferSchema::JSON_COLUMNS[$table] ?? [], true)
            ) {
                if (is_string($value)) {
                    $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                }
                $value = $this->canonicalize($value);
            } elseif ($value !== null && in_array($type, ['integer', 'biginteger'], true)) {
                $value = (int)$value;
            } elseif ($value !== null && $type === 'decimal') {
                $value = (string)$value;
            } elseif ($value !== null && $type === 'date') {
                $value = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string)$value;
            } elseif ($value !== null && in_array($type, ['datetime', 'timestamp', 'timestampfractional'], true)) {
                $value = $value instanceof DateTimeInterface
                    ? $value->format('Y-m-d H:i:s.u')
                    : (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            }
            $normalized[$column] = $value;
        }

        return $normalized;
    }

    /** Refuses merge semantics and any destination containing target data. */
    private function assertDestinationEmpty(): void
    {
        $nonEmpty = [];
        foreach (PcmTransferSchema::TABLES as $table) {
            $count = (int)$this->connection->execute("SELECT COUNT(*) FROM {$table}")->fetchColumn(0);
            if ($count !== 0) {
                $nonEmpty[$table] = $count;
            }
        }
        if ($nonEmpty !== []) {
            throw new RuntimeException('Destino não está vazio: ' . json_encode($nonEmpty, self::JSON_FLAGS));
        }
    }

    /** Rejects any orphaned essential relationship. */
    private function assertRelationships(): void
    {
        $errors = array_filter($this->relationshipErrors());
        if ($errors !== []) {
            throw new RuntimeException('Relacionamentos inválidos: ' . json_encode($errors, self::JSON_FLAGS));
        }
    }

    /** Returns orphan counts for every declared foreign-key relationship. */
    private function relationshipErrors(): array
    {
        $errors = [];
        foreach (PcmTransferSchema::RELATIONSHIPS as $child => $relationships) {
            foreach ($relationships as $foreignKey => $parent) {
                $sql = "SELECT COUNT(*) FROM {$child} c LEFT JOIN {$parent} p ON p.id = c.{$foreignKey} "
                    . "WHERE c.{$foreignKey} IS NOT NULL AND p.id IS NULL";
                $errors["{$child}.{$foreignKey}->{$parent}.id"] =
                    (int)$this->connection->execute($sql)->fetchColumn(0);
            }
        }

        return $errors;
    }

    /** Creates incremental checksum contexts without retaining rows in memory. */
    private function summaryContext(): array
    {
        return ['count' => 0, 'ids' => hash_init('sha256'), 'data' => hash_init('sha256')];
    }

    /** Adds one canonical row to its count and checksums. */
    private function updateSummary(array &$context, array $row): void
    {
        $context['count']++;
        hash_update($context['ids'], (string)($row['id'] ?? '') . "\n");
        hash_update($context['data'], $this->encode($row) . "\n");
    }

    /** Finalizes incremental hashes into a serializable summary. */
    private function finishSummary(array $context): array
    {
        return [
            'count' => $context['count'],
            'id_sha256' => hash_final($context['ids']),
            'data_sha256' => hash_final($context['data']),
        ];
    }

    /** Sorts JSON object keys recursively while retaining list order. */
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** Produces a SHA-256 digest of canonical encoded data. */
    private function digest(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    /** Encodes one deterministic JSON value. */
    private function encode(array $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    /** Writes one JSON Lines record. */
    private function writeLine(mixed $handle, array $value): void
    {
        if (fwrite($handle, $this->encode($value) . "\n") === false) {
            throw new RuntimeException('Falha ao gravar o arquivo de transferência.');
        }
    }

    /** Reads and decodes one required JSON Lines record. */
    private function readLine(mixed $handle, int $line): array
    {
        $raw = $this->nextNonEmptyLine($handle);
        if ($raw === null) {
            throw new RuntimeException("Arquivo truncado antes da linha {$line}.");
        }
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("JSON inválido na linha {$line}.");
        }

        return $decoded;
    }

    /** Reads the next non-empty physical line. */
    private function nextNonEmptyLine(mixed $handle): ?string
    {
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                return $line;
            }
        }

        return null;
    }

    /** Enforces the exact format version and table order. */
    private function assertHeader(array $header): void
    {
        if (
            ($header['record'] ?? null) !== 'header'
            || ($header['format'] ?? null) !== PcmTransferSchema::FORMAT
            || ($header['version'] ?? null) !== PcmTransferSchema::VERSION
            || ($header['tables'] ?? null) !== PcmTransferSchema::TABLES
        ) {
            throw new RuntimeException('Formato ou versão do arquivo de transferência incompatível.');
        }
    }

    /** Ensures an input file is a readable regular file. */
    private function assertReadableFile(string $path): void
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Arquivo de transferência inexistente ou sem leitura.');
        }
    }

    /** Validates a local export destination without creating directories. */
    private function validatedOutputPath(string $path, bool $overwrite): string
    {
        if ($path === '' || is_dir($path)) {
            throw new RuntimeException('Informe um arquivo de destino válido.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('A pasta de destino não existe ou não permite escrita.');
        }
        if (file_exists($path)) {
            if (!$overwrite) {
                throw new RuntimeException('O arquivo já existe; use --overwrite somente após conferir o destino.');
            }
            if (!is_file($path) || !is_writable($path)) {
                throw new RuntimeException('O arquivo existente não pode ser substituído com segurança.');
            }
        }

        return $path;
    }

    /** Atomically publishes an export while protecting an explicitly replaced file. */
    private function publishExport(string $temporary, string $path, bool $overwrite): void
    {
        if (!file_exists($path)) {
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Não foi possível publicar o arquivo de transferência completo.');
            }

            return;
        }
        if (!$overwrite) {
            throw new RuntimeException('O arquivo já existe.');
        }
        $backup = $path . '.previous-' . bin2hex(random_bytes(6));
        if (!rename($path, $backup)) {
            throw new RuntimeException('Não foi possível proteger o arquivo anterior antes da substituição.');
        }
        if (!rename($temporary, $path)) {
            rename($backup, $path);
            throw new RuntimeException('Não foi possível publicar o novo arquivo; o anterior foi restaurado.');
        }
        if (!unlink($backup)) {
            throw new RuntimeException('Exportação concluída, mas a cópia anterior não pôde ser removida.');
        }
    }
}
