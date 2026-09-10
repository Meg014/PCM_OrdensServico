<?php
declare(strict_types=1);

namespace App\Service\Import;

use RuntimeException;

final class TotvsCsvReader
{
    /** Creates the CSV reader with the shared TOTVS header contract. */
    public function __construct(private readonly TotvsHeaderValidator $headerValidator = new TotvsHeaderValidator())
    {
    }

    /** Reads a delimited TOTVS export into the same row contract used by the XLSX reader. */
    public function read(string $path): array
    {
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('O arquivo informado não é um CSV válido.');
        }

        $encoding = $this->detectEncoding($path);
        $delimiter = $this->detectDelimiter($path, $encoding);
        $handle = $this->openUtf8Stream($path, $encoding);
        $headers = null;
        $headerLine = 0;
        $rows = [];
        $line = 0;
        while (($values = fgetcsv($handle, null, $delimiter, '"', '\\')) !== false) {
            $line++;
            if ($headers === null) {
                if (trim((string)($values[0] ?? '')) !== 'Filial') {
                    continue;
                }
                $headers = array_values($values);
                $headerLine = $line;
                continue;
            }
            if (count($values) !== count(TotvsHeaderValidator::EXPECTED)) {
                fclose($handle);
                throw new RuntimeException(sprintf(
                    'Linha %d inválida: esperadas 57 colunas, encontradas %d.',
                    $line,
                    count($values),
                ));
            }
            if (count(array_filter($values, static fn(mixed $value): bool => $value !== null && $value !== '')) === 0) {
                continue;
            }
            $rows[] = ['source_row_number' => $line, 'values' => array_values($values)];
        }
        fclose($handle);

        if ($headers === null) {
            throw new RuntimeException('Cabeçalho TOTVS não encontrado no CSV.');
        }
        $warnings = $this->headerValidator->validate($headers);
        if ($rows === []) {
            throw new RuntimeException('O relatório CSV não contém linhas de dados válidas.');
        }
        $warnings[] = sprintf(
            'CSV lido em %s com delimitador "%s"; cabeçalho encontrado na linha %d.',
            $encoding,
            $delimiter === "\t" ? 'TAB' : $delimiter,
            $headerLine,
        );

        return [
            'sheet_name' => 'CSV',
            'headers' => $headers,
            'rows' => $rows,
            'warnings' => array_values(array_unique($warnings)),
            'header_signature' => hash(
                'sha256',
                json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ),
        ];
    }

    /** Detects UTF-8 and falls back to the encoding used by the TOTVS export. */
    private function detectEncoding(string $path): string
    {
        $sample = file_get_contents($path, false, null, 0, 65536);
        if ($sample === false) {
            throw new RuntimeException('Não foi possível ler o CSV para detectar o encoding.');
        }
        if (str_starts_with($sample, "\xEF\xBB\xBF") || mb_check_encoding($sample, 'UTF-8')) {
            return 'UTF-8';
        }

        return 'Windows-1252';
    }

    /** Finds the delimiter whose candidate header matches all 57 columns. */
    private function detectDelimiter(string $path, string $encoding): string
    {
        $handle = $this->openUtf8Stream($path, $encoding);
        for ($line = 0; $line < 20; $line++) {
            $text = fgets($handle);
            if ($text === false) {
                break;
            }
            foreach ([';', ',', "\t"] as $delimiter) {
                $values = str_getcsv(rtrim($text, "\r\n"), $delimiter, '"', '\\');
                if (trim((string)($values[0] ?? '')) === 'Filial' && count($values) === 57) {
                    fclose($handle);

                    return $delimiter;
                }
            }
        }
        fclose($handle);

        throw new RuntimeException('Não foi possível identificar delimitador e cabeçalho do CSV TOTVS.');
    }

    /** @return resource */
    private function openUtf8Stream(string $path, string $encoding)
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o CSV.');
        }
        if ($encoding !== 'UTF-8') {
            $filter = stream_filter_append($handle, "convert.iconv.{$encoding}/UTF-8");
            if ($filter === false) {
                fclose($handle);
                throw new RuntimeException('Não foi possível converter o CSV para UTF-8.');
            }
        }

        return $handle;
    }
}
