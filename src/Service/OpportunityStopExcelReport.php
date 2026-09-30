<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Protheus\OpportunityStopService;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/** Isolated editable workbook; advanced Excel features are intentionally not added to the streaming writer. */
final class OpportunityStopExcelReport
{
    private const HEADER_ROW = 5;
    private const EXTRA_ROWS = 10;
    private const MAX_ROWS = 10000;
    private const STATUS = ['CONCLUÍDO', 'EM ANDAMENTO', 'REPROGRAMADO', 'CANCELADO'];

    /** Returns the report timestamp in the user-facing PCM timezone. */
    public function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    }

    /** Builds a filesystem-safe report filename. */
    public function filename(DateTimeImmutable $generated): string
    {
        $timestamp = $generated->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d_His');

        return 'PARADAS_POR_OPORTUNIDADE_' . $timestamp . '.xlsx';
    }

    /** @return array{stream: resource, count: int, peak_memory: int} */
    public function write(array $query, DateTimeImmutable $generated, ?callable $fetchBatch = null): array
    {
        $fetchBatch ??= static fn(int $page): array => (new OpportunityStopService())->load($query, true, $page);
        $first = $fetchBatch(1);
        if (!$first['available']) {
            throw new RuntimeException('Protheus temporariamente indisponível.');
        }
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Paradas por Oportunidade');
        $this->heading($sheet, $first['area_name'], $first['filters']['cost_center'], $generated);
        $row = self::HEADER_ROW;
        $count = 0;
        $page = 1;
        $batch = $first;
        do {
            foreach ($batch['orders'] as $source) {
                if (++$count > self::MAX_ROWS) {
                    throw new DomainException('A exportação excede 10.000 O.S.; aplique um filtro de setor.');
                }
                $this->orderRow($sheet, ++$row, $source);
            }
            if (!$batch['has_more']) {
                break;
            }
            ++$page;
            $batch = $fetchBatch($page);
            if (!$batch['available'] || $batch['orders'] === []) {
                throw new RuntimeException('Paginação inconsistente durante a exportação.');
            }
        } while (true);
        $editableEnd = $row + self::EXTRA_ROWS;
        for ($extra = $row + 1; $extra <= $editableEnd; ++$extra) {
            $this->manualRow($sheet, $extra);
        }
        $this->finish($book, $sheet, self::HEADER_ROW + 1, $editableEnd);
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Não foi possível preparar o Excel.');
        }
        try {
            (new Xlsx($book))->save($stream);
            rewind($stream);
        } catch (Throwable $error) {
            fclose($stream);
            throw $error;
        } finally {
            $book->disconnectWorksheets();
        }

        return ['stream' => $stream, 'count' => $count, 'peak_memory' => memory_get_peak_usage(true)];
    }

    /** Mirrors the workbook formula for boundary-focused tests. */
    public static function priorityForHours(?float $hours): ?int
    {
        return $hours === null ? null : ($hours < 2 ? 2 : ($hours <= 4 ? 1 : 0));
    }

    /** Writes report metadata, headers and the priority legend. */
    private function heading(
        Worksheet $sheet,
        string $workshop,
        string $costCenter,
        DateTimeImmutable $generated,
    ): void {
        $headers = ['O.S.', 'PRIORIDADE', 'DESCRIÇÃO DO SERVIÇO', 'EQUIPAMENTO', 'SETOR', 'OFICINA',
            'TIPO MANUTENÇÃO', 'TEMPO PREVISTO', 'EXECUTANTE', 'DATA FIM', 'STATUS', 'EXECUTANTE RESPONSÁVEL'];
        $sheet->mergeCells('A1:B4')->mergeCells('C1:I1')->mergeCells('C2:I2')->mergeCells('C3:I3');
        $this->logo($sheet);
        $this->text($sheet, 'C1', 'PARADAS POR OPORTUNIDADE');
        $extracted = $generated->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y \à\s H:i');
        $this->text($sheet, 'C2', 'Extraído em: ' . $extracted);
        $this->text($sheet, 'C3', 'Oficina: ' . $workshop . ' | Centro de custo: '
            . ($costCenter === '' ? 'Todos' : $costCenter));
        $this->priorityLegend($sheet);
        foreach ($headers as $index => $header) {
            $this->text($sheet, chr(65 + $index) . self::HEADER_ROW, $header);
        }
        $sheet->getStyle('C1:I1')->getFont()->setBold(true)->setSize(16)->setColor(new Color('FF285780'));
        $sheet->getStyle('C2:I3')->getFont()->setSize(10)->setColor(new Color('FF4B5563'));
        $sheet->getStyle('C1:I3')->getAlignment()->setVertical('center');
        $sheet->getStyle('A' . self::HEADER_ROW . ':L' . self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '285780']],
        ]);
        foreach (['A' => 13, 'B' => 12, 'C' => 38, 'D' => 30, 'E' => 16, 'F' => 16,
            'G' => 18, 'H' => 17, 'I' => 22, 'J' => 17, 'K' => 20, 'L' => 25] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        foreach ([1 => 23, 2 => 19, 3 => 19, 4 => 19, self::HEADER_ROW => 28] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
    }

    /** Embeds the bundled BIOLASA logo with its original proportions. */
    private function logo(Worksheet $sheet): void
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'webroot' . DIRECTORY_SEPARATOR
            . 'img' . DIRECTORY_SEPARATOR . 'biolasa-logo.png';
        if (!is_file($path)) {
            throw new RuntimeException('Logo institucional indisponível.');
        }
        $drawing = new Drawing();
        $drawing->setName('Logo BIOLASA')->setDescription('BIOLASA Energia Renovável')
            ->setPath($path)->setCoordinates('A1')->setHeight(72)->setOffsetX(4)->setOffsetY(3)->setWorksheet($sheet);
    }

    /** Writes the compact priority legend using the same colors as the conditional formatting. */
    private function priorityLegend(Worksheet $sheet): void
    {
        $sheet->mergeCells('J1:L1');
        $this->text($sheet, 'J1', 'PRIORIDADE');
        $sheet->getStyle('J1:L1')->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle('J1:L1')->getFill()->setFillType('solid')->getStartColor()->setRGB('285780');
        foreach ([[2, 'D9EAD3', '2 — Abaixo de 2 horas'], [1, 'FFF2CC', '1 — Entre 2 e 4 horas'],
            [0, 'F4CCCC', '0 — Acima de 4 horas']] as $index => [$priority, $color, $label]) {
            $row = $index + 2;
            $sheet->mergeCells('K' . $row . ':L' . $row);
            $sheet->setCellValue('J' . $row, $priority);
            $this->text($sheet, 'K' . $row, $label);
            $sheet->getStyle('J' . $row)->getFill()->setFillType('solid')->getStartColor()->setRGB($color);
            $sheet->getStyle('J' . $row)->getFont()->setBold(true);
        }
        $sheet->getStyle('J1:L4')->getAlignment()->setHorizontal('center')->setVertical('center');
    }

    /** Writes one Protheus order using explicit text cells for injection safety. */
    private function orderRow(Worksheet $sheet, int $row, array $source): void
    {
        $description = trim((string)($source['service_name'] ?? ''));
        $equipment = trim((string)($source['TJ_CODBEM'] ?? '')) . ' — ' . trim((string)($source['equipment_name'] ?? ''));
        $area = trim((string)($source['TJ_CODAREA'] ?? ''));
        $type = trim((string)($source['TJ_TIPO'] ?? ''));
        foreach (
            ['A' => trim((string)$source['TJ_ORDEM']), 'C' => $description, 'D' => trim($equipment, ' —'),
            'E' => trim((string)($source['cost_center_name'] ?? $source['TJ_CCUSTO'] ?? '')),
            'F' => OpportunityStopService::WORKSHOPS[$area] ?? $area,
            'G' => ['COR' => 'Corretiva','PRE' => 'Preventiva','MEL' => 'Melhoria'][$type] ?? $type] as $column => $value
        ) {
            $this->text($sheet, $column . $row, $value);
        }
        $this->manualRow($sheet, $row);
    }

    /** Adds the live priority formula and editable operational cells. */
    private function manualRow(Worksheet $sheet, int $row): void
    {
        $sheet->setCellValue('B' . $row, '=IF(H' . $row . '="","",IF(H' . $row . '<2,2,IF(H' . $row . '<=4,1,0)))');
        foreach (['H','I','J','K','L'] as $column) {
            if ($sheet->getCell($column . $row)->getValue() === null) {
                $sheet->setCellValue($column . $row, '');
            }
        }
    }

    /** Applies editing aids and workbook-level presentation. */
    private function finish(Spreadsheet $book, Worksheet $sheet, int $start, int $end): void
    {
        $sheet->getStyle('A1:O' . $end)->getAlignment()->setWrapText(true)->setVertical('top');
        $sheet->getStyle('C1:L4')->getAlignment()->setVertical('center');
        $sheet->getStyle('A' . self::HEADER_ROW . ':L' . self::HEADER_ROW)
            ->getAlignment()->setHorizontal('center')->setVertical('center');
        foreach (['A', 'B', 'E', 'F', 'G', 'H', 'J', 'K'] as $column) {
            $sheet->getStyle($column . $start . ':' . $column . $end)
                ->getAlignment()->setHorizontal('center')->setVertical('center');
        }
        $sheet->getStyle('H' . $start . ':H' . $end)->getNumberFormat()->setFormatCode('0.00 "HORA(S)"');
        $sheet->getStyle('J' . $start . ':J' . $end)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        $sheet->setAutoFilter('A' . self::HEADER_ROW . ':L' . $end);
        $sheet->freezePane('A' . (self::HEADER_ROW + 1));
        $sheet->setShowGridlines(false);
        $list = $book->createSheet()->setTitle('Listas');
        foreach (self::STATUS as $index => $status) {
            $this->text($list, 'A' . ($index + 1), $status);
        }
        $book->addNamedRange(new NamedRange('StatusValues', $list, '$A$1:$A$4'));
        $list->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        for ($row = $start; $row <= $end; ++$row) {
            $validation = new DataValidation();
            $validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setFormula1('=StatusValues');
            $sheet->getCell('K' . $row)->setDataValidation($validation);
        }
        $conditions = [];
        foreach ([[0, 'F4CCCC'], [1, 'FFF2CC'], [2, 'D9EAD3']] as [$priority, $color]) {
            $condition = new Conditional();
            $condition->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_EQUAL)
                ->addCondition((string)$priority);
            $condition->getStyle()->getFill()->setFillType('solid')->getStartColor()->setRGB($color);
            $condition->getStyle()->getFont()->setColor(new Color(Color::COLOR_BLACK))->setBold(true);
            $conditions[] = $condition;
        }
        $sheet->getStyle('B' . $start . ':B' . $end)->setConditionalStyles($conditions);
    }

    /** Writes untrusted values as literal strings, never formulas. */
    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }
}
