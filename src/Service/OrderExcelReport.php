<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Protheus\Presentation\OrderSupplementMapper;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/** Explicit operational allowlist; source strings are never interpreted as formulas. */
final class OrderExcelReport
{
    private const FILTER_LABELS = [
        'filial' => 'Filial', 'status' => 'Status', 'equipment' => 'Equipamento', 'bem' => 'Equipamento',
        'service' => 'Serviço', 'service_name' => 'Nome do serviço', 'cost_center' => 'Centro de custo',
        'centro' => 'Centro de custo', 'maintenance_type' => 'Tipo de manutenção', 'type' => 'Tipo de manutenção',
        'q' => 'Busca textual', 'os' => 'Número da OS', 'date_start' => 'Data inicial', 'date_end' => 'Data final',
        'card' => 'Indicador', 'card_status' => 'Status do indicador', 'backlog_age' => 'Faixa de backlog',
    ];

    public function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    }

    public function filename(string $context, DateTimeImmutable $generated): string
    {
        $safe = substr(trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $context), '_-'), 0, 100);
        return ($safe ?: 'OS') . '_' . $generated->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d_His') . '.xlsx';
    }

    public function workbook(array $data, string $context, DateTimeImmutable $generated): Spreadsheet
    {
        if (!$data['available']) throw new RuntimeException('Não foi possível gerar o relatório: Protheus temporariamente indisponível. Tente novamente.');
        $columns = [
            'TJ_ORDEM' => ['Número da OS', 'text'], 'descricao' => ['Descrição da OS', 'text'],
            'TJ_FILIAL' => ['Filial', 'text'],
            'TJ_CODBEM' => ['Código do equipamento', 'text'], 'equipment_name' => ['Nome do equipamento', 'text'],
            'TJ_SERVICO' => ['Código do serviço', 'text'], 'service_name' => ['Nome do serviço', 'text'],
            'TJ_TIPO' => ['Tipo de manutenção', 'text'], 'TJ_CODAREA' => ['Área/Setor', 'text'],
            'TJ_CCUSTO' => ['Centro de custo', 'text'],
        ];
        if ($context === 'sector') {
            $columns += ['status' => ['Status', 'text'], 'planned_date' => ['Início previsto (manutenção)', 'date'],
                'TJ_HOMPINI' => ['Hora prevista (manutenção)', 'time'],
                'TJ_DTPRINI' => ['Início real (geral)', 'date'], 'TJ_HOPRINI' => ['Hora real (geral)', 'time']];
        } else {
            $columns += ['TJ_SITUACA' => ['Situação (código TOTVS)', 'text'], 'TJ_TERMINO' => ['Término (indicador TOTVS)', 'text'],
                'reference_date' => ['Data de referência', 'date'], 'TJ_DTORIGI' => ['Data de origem', 'date']];
            foreach (['PP' => 'previsto (geral)', 'PR' => 'real (geral)', 'MP' => 'previsto (manutenção)', 'MR' => 'real (manutenção)'] as $code => $label) {
                foreach (['INI' => 'Início', 'FIM' => 'Término'] as $suffix => $part) {
                    $columns['TJ_DT' . $code . $suffix] = [$part . ' ' . $label, 'date'];
                    $columns['TJ_HO' . $code . $suffix] = ['Hora: ' . $part . ' ' . $label, 'time'];
                }
            }
        }
        $textBytes = 0;
        foreach ($data['orders'] as $order) {
            foreach ($columns as $key => $_definition) {
                $value = (string)($order[$key] ?? '');
                $textBytes += strlen($value);
                if (mb_strlen($value, 'UTF-8') > 32767) {
                    throw new \DomainException('Uma OS contém texto maior que o permitido pelo Excel (32.767 caracteres por célula). Nenhum arquivo foi gerado.');
                }
            }
        }
        // Conservative cell/writer allowance; never raise the server memory limit.
        $memoryLimit = ini_parse_quantity((string)ini_get('memory_limit'));
        $estimated = count($data['orders']) * count($columns) * 2000 + $textBytes * 4 + 32 * 1024 * 1024;
        if ($memoryLimit > 0 && memory_get_usage(true) + $estimated > $memoryLimit) {
            throw new \DomainException('O relatório excede a memória disponível para exportação. Refine os filtros e tente novamente. Nenhum arquivo foi gerado.');
        }
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Ordens de Serviço');
        $last = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->mergeCells('A1:' . $last . '1');
        $this->text($sheet, 'A1', $context === 'equipment' ? 'HISTÓRICO DE MANUTENÇÃO DO EQUIPAMENTO' : 'RELATÓRIO DE ORDENS DE SERVIÇO');
        $this->text($sheet, 'A2', 'Setor/Área: ' . ($data['name'] ?? 'Conforme área de cada OS'));
        $this->text($sheet, 'A3', 'Gerado em:');
        $sheet->setCellValue('C3', Date::PHPToExcel($generated->setTimezone(new DateTimeZone('America/Sao_Paulo'))));
        $sheet->getStyle('C3')->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        $this->text($sheet, 'A4', 'Total de OS:');
        $sheet->setCellValue('C4', count($data['orders']));
        $row = 5;
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
            $sheet->getColumnDimension($letter)->setWidth($type === 'text' ? 25 : 22);
            if ($type !== 'text' && $data['orders'] !== []) {
                $sheet->getStyle($letter . ($header + 1) . ':' . $letter . ($header + count($data['orders'])))
                    ->getNumberFormat()->setFormatCode($type === 'date' ? 'dd/mm/yyyy' : 'hh:mm');
            }
        }
        $mapper = new OrderSupplementMapper();
        foreach ($data['orders'] as $order) {
            ++$row;
            $index = 1;
            foreach ($columns as $key => [$label, $type]) {
                $cell = Coordinate::stringFromColumnIndex($index++) . $row;
                $value = $order[$key] ?? null;
                if ($type === 'date') {
                    $iso = $mapper->date($value);
                    if ($iso !== null) {
                        $sheet->setCellValue($cell, Date::PHPToExcel(new DateTimeImmutable($iso, new DateTimeZone('America/Sao_Paulo'))));
                    }
                } elseif ($type === 'time') {
                    $time = is_string($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value) ? $value : null;
                    if ($time !== null) {
                        [$h, $m] = array_map('intval', explode(':', $time));
                        $sheet->setCellValue($cell, ($h * 60 + $m) / 1440);
                    }
                } else {
                    $this->text($sheet, $cell, (string)($value ?? ''));
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

    /** All text, including metadata, follows the same formula-safe path. */
    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    /** Temporary stream is removed automatically when the response releases it. */
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
