<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class OrderEntryExcelReport
{
    private const FILTER_LABELS = [
        'filial' => 'Filial', 'status' => 'Status', 'equipment' => 'Equipamento', 'service' => 'Serviço',
        'service_name' => 'Nome do serviço', 'cost_center' => 'Centro de custo',
        'maintenance_type' => 'Tipo de manutenção', 'q' => 'Busca textual', 'date_start' => 'Data inicial',
        'date_end' => 'Data final', 'card' => 'Indicador', 'card_status' => 'Status do indicador',
        'backlog_age' => 'Faixa de backlog', 'entry_type' => 'Tipo de apontamento',
        'professional' => 'Responsável/profissional',
    ];

    public function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    }

    public function filename(string $area, DateTimeImmutable $generated): string
    {
        $safe = substr(trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $area), '_-'), 0, 60);
        return 'APONTAMENTOS_OS_' . ($safe ?: 'SETOR') . '_' . $generated
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d_His') . '.xlsx';
    }

    public function workbook(array $data, DateTimeImmutable $generated): Spreadsheet
    {
        if (!$data['available']) throw new RuntimeException('Não foi possível gerar o relatório: Protheus temporariamente indisponível. Tente novamente.');
        $columns = [
            'TJ_ORDEM' => ['Número da OS', 'text'], 'TJ_FILIAL' => ['Filial', 'text'],
            'TJ_CODBEM' => ['Código do equipamento', 'text'], 'equipment_name' => ['Nome do equipamento', 'text'],
            'descricao' => ['Descrição da OS', 'text'], 'TJ_SERVICO' => ['Código do serviço', 'text'],
            'service_name' => ['Nome do serviço', 'text'], 'TJ_CODAREA' => ['Área/Setor', 'text'],
            'TJ_CCUSTO' => ['Centro de custo', 'text'], 'TJ_TIPO' => ['Tipo de manutenção', 'text'],
            'status' => ['Status da OS', 'text'], 'entry_type' => ['Tipo do apontamento', 'text'],
            'TL_CODIGO' => ['Código do apontamento', 'text'], 'professional_code' => ['Código do responsável/profissional', 'text'],
            'professional_name' => ['Nome do responsável/profissional', 'text'],
            'product_code' => ['Código do material/produto', 'text'], 'product_name' => ['Nome/descrição do material/produto', 'text'],
            'TL_DTINICI' => ['Data inicial do apontamento', 'date'], 'TL_DTFIM' => ['Data final do apontamento', 'date'],
            'TL_HOINICI' => ['Hora inicial', 'time'], 'TL_HOFIM' => ['Hora final', 'time'],
            'TL_QUANTID' => ['Quantidade registrada', 'text'], 'TL_UNIDADE' => ['Unidade', 'text'],
        ];
        $textBytes = 0;
        foreach ($data['entries'] as $entry) {
            foreach ($columns as $key => $_definition) {
                $value = is_scalar($entry[$key] ?? null) ? (string)$entry[$key] : '';
                $textBytes += strlen($value);
                if (mb_strlen($value, 'UTF-8') > 32767) {
                    throw new \DomainException('Um apontamento contém texto maior que o permitido pelo Excel (32.767 caracteres por célula). Nenhum arquivo foi gerado.');
                }
            }
        }
        $memoryLimit = ini_parse_quantity((string)ini_get('memory_limit'));
        $estimated = count($data['entries']) * count($columns) * 2000 + $textBytes * 4 + 32 * 1024 * 1024;
        if ($memoryLimit > 0 && memory_get_usage(true) + $estimated > $memoryLimit) {
            throw new \DomainException('O relatório excede a memória disponível para exportação. Refine os filtros e tente novamente. Nenhum arquivo foi gerado.');
        }
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Apontamentos');
        $last = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->mergeCells('A1:' . $last . '1');
        $this->text($sheet, 'A1', 'RELATÓRIO DE APONTAMENTOS DE ORDENS DE SERVIÇO');
        $this->text($sheet, 'A2', 'Setor/Área: ' . ($data['name'] ?? $data['code'] ?? ''));
        $this->text($sheet, 'A3', 'Gerado em:');
        $sheet->setCellValue('C3', Date::PHPToExcel($generated->setTimezone(new DateTimeZone('America/Sao_Paulo'))));
        $sheet->getStyle('C3')->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        $this->text($sheet, 'A4', 'Total de apontamentos:');
        $sheet->setCellValue('C4', count($data['entries']));
        $distinct = [];
        foreach ($data['entries'] as $entry) $distinct[(string)($entry['TJ_FILIAL'] ?? '') . "\0" . (string)($entry['TJ_ORDEM'] ?? '')] = true;
        $this->text($sheet, 'A5', 'OS distintas:');
        $sheet->setCellValue('C5', count($distinct));
        $row = 6;
        $filters = [];
        foreach ($data['filters'] as $key => $value) {
            if (!isset(self::FILTER_LABELS[$key]) || !is_scalar($value)) continue;
            $value = trim((string)$value);
            if (in_array(mb_strtolower($value, 'UTF-8'), ['', 'all', 'todos', 'todas'], true)) continue;
            $filters[] = self::FILTER_LABELS[$key] . ': ' . $value;
        }
        if ($filters !== []) {
            $sheet->mergeCells('A' . $row . ':' . $last . $row);
            $this->text($sheet, 'A' . $row++, 'Filtros: ' . implode(' | ', $filters));
        }
        $header = $row;
        $index = 1;
        foreach ($columns as [$label, $type]) {
            $letter = Coordinate::stringFromColumnIndex($index++);
            $this->text($sheet, $letter . $header, $label);
            $sheet->getColumnDimension($letter)->setWidth($type === 'text' ? 24 : 18);
        }
        foreach ($data['entries'] as $entry) {
            ++$row;
            $entry['entry_type'] = match (rtrim((string)($entry['TL_TIPOREG'] ?? ''))) {
                'M' => 'Mão de obra', 'P' => 'Material', default => rtrim((string)($entry['TL_TIPOREG'] ?? '')),
            };
            $entry['professional_code'] = rtrim((string)($entry['TL_TIPOREG'] ?? '')) === 'M' ? ($entry['TL_CODIGO'] ?? '') : '';
            $entry['product_code'] = rtrim((string)($entry['TL_TIPOREG'] ?? '')) === 'P' ? ($entry['TL_CODIGO'] ?? '') : '';
            $index = 1;
            foreach ($columns as $key => [, $type]) {
                $cell = Coordinate::stringFromColumnIndex($index++) . $row;
                $value = $entry[$key] ?? null;
                if ($type === 'date' && is_scalar($value) && preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/D', rtrim((string)$value), $parts)
                    && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
                    $sheet->setCellValue($cell, Date::PHPToExcel(new DateTimeImmutable($parts[1] . '-' . $parts[2] . '-' . $parts[3])));
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif ($type === 'time' && is_scalar($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', rtrim((string)$value))) {
                    [$hour, $minute] = array_map('intval', explode(':', rtrim((string)$value)));
                    $sheet->setCellValue($cell, ($hour * 60 + $minute) / 1440);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('hh:mm');
                } else {
                    $this->text($sheet, $cell, is_scalar($value) ? rtrim((string)$value) : '');
                }
            }
        }
        $sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A' . $header . ':' . $last . $header)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '285780']],
        ]);
        $sheet->getStyle('A1:' . $last . $row)->getAlignment()->setWrapText(true)->setVertical('top');
        $sheet->setAutoFilter('A' . $header . ':' . $last . $row);
        $sheet->freezePane('A' . ($header + 1));
        $sheet->setShowGridlines(false);
        return $book;
    }

    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        if (mb_strlen($value, 'UTF-8') > 32767) throw new \DomainException('Uma célula excede 32.767 caracteres. Nenhum arquivo foi gerado.');
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    public function stream(Spreadsheet $book): mixed
    {
        $stream = tmpfile();
        if ($stream === false) throw new RuntimeException('Não foi possível preparar o arquivo Excel.');
        try {
            (new Xlsx($book))->save($stream);
            rewind($stream);
            return $stream;
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
