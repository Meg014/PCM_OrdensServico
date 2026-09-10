<?php
declare(strict_types=1);

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

final class TotvsWorkbookReader
{
    public function __construct(private readonly TotvsHeaderValidator $headerValidator = new TotvsHeaderValidator())
    {
    }

    /** @return array{sheet_name:string,headers:list<mixed>,rows:list<array{source_row_number:int,values:list<mixed>}>,warnings:list<string>,header_signature:string} */
    public function read(string $path, string $sheetName = 'sclxd280'): array
    {
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('O arquivo informado não é um XLSX válido.');
        }
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable $exception) {
            throw new RuntimeException('Não foi possível abrir o XLSX: ' . $exception->getMessage(), 0, $exception);
        }
        $worksheet = $spreadsheet->getSheetByName($sheetName);
        if ($worksheet === null) {
            if ($spreadsheet->getSheetCount() !== 1) {
                $spreadsheet->disconnectWorksheets();
                throw new RuntimeException(sprintf('A aba obrigatória "%s" não foi encontrada.', $sheetName));
            }
            $worksheet = $spreadsheet->getSheet(0);
        }
        $actualSheetName = $worksheet->getTitle();
        $highestRow = $worksheet->getHighestDataRow();
        $headers = array_values($worksheet->rangeToArray('A1:BE1', null, false, true, false)[0]);
        $warnings = $this->headerValidator->validate($headers);
        if ($actualSheetName !== $sheetName) {
            $warnings[] = sprintf('A aba única "%s" foi aceita após validação integral dos 57 cabeçalhos.', $actualSheetName);
        }
        if ($highestRow < 2) {
            $spreadsheet->disconnectWorksheets();
            throw new RuntimeException('O relatório não contém linhas de dados.');
        }
        $rows = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            // Dados formatados preservam zeros à esquerda em códigos do TOTVS.
            $values = array_values($worksheet->rangeToArray("A{$row}:BE{$row}", null, false, true, false)[0]);
            if (count(array_filter($values, static fn(mixed $value): bool => $value !== null && $value !== '')) === 0) {
                continue;
            }
            $rows[] = ['source_row_number' => $row, 'values' => $values];
        }
        $spreadsheet->disconnectWorksheets();
        if ($rows === []) {
            throw new RuntimeException('O relatório não contém linhas de dados válidas.');
        }

        return ['sheet_name' => $actualSheetName, 'headers' => $headers, 'rows' => $rows, 'warnings' => $warnings,
            'header_signature' => hash('sha256', json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }
}
