<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\OrderEntryExcelReport;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\TestCase;

final class OrderEntryExcelReportTest extends TestCase
{
    public function testOneRowPerEntryCountsDistinctOrdersAndKeepsTextSafe(): void
    {
        $base = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '005472', 'TJ_CODBEM' => '000045',
            'equipment_name' => 'EQUIPAMENTO X', 'descricao' => 'DESCRIÇÃO X', 'TJ_SERVICO' => '001',
            'service_name' => 'SERVIÇO DIFERENTE', 'TJ_CODAREA' => 'ELETRI', 'TJ_CCUSTO' => '0007',
            'TJ_TIPO' => 'COR', 'status' => 'EM ABERTO', 'TL_DTINICI' => '20260925',
            'TL_DTFIM' => '20260925', 'TL_HOINICI' => '09:30', 'TL_HOFIM' => '10:30',
            'TL_QUANTID' => '1.00', 'TL_UNIDADE' => 'H'];
        $entries = [
            $base + ['TL_TIPOREG' => 'M', 'TL_CODIGO' => '008382', 'professional_name' => '=PERIGOSO'],
            $base + ['TL_TIPOREG' => 'M', 'TL_CODIGO' => '009999', 'professional_name' => 'MARIA'],
            $base + ['TL_TIPOREG' => 'P', 'TL_CODIGO' => '002075', 'product_name' => '+MATERIAL'],
            array_replace($base, ['TJ_ORDEM' => '005473', 'TL_TIPOREG' => 'P', 'TL_CODIGO' => '000110',
                'product_name' => 'ROLAMENTO']),
        ];
        $report = new OrderEntryExcelReport();
        $book = $report->workbook(['available' => true, 'has_more' => false, 'entries' => $entries,
            'name' => 'Elétrica', 'filters' => ['status' => 'Todos', 'entry_type' => '', 'equipment' => '000045']],
            new \DateTimeImmutable('2026-09-25T17:00:00Z'));
        $sheet = $book->getActiveSheet();
        self::assertSame(4, $sheet->getCell('C4')->getValue());
        self::assertSame(2, $sheet->getCell('C5')->getValue());
        self::assertSame('Filtros: Equipamento: 000045', $sheet->getCell('A6')->getValue());
        self::assertSame('A8', $sheet->getFreezePane());
        self::assertSame('A7:W11', $sheet->getAutoFilter()->getRange());
        self::assertSame(['005472', '005472', '005472', '005473'], array_map(
            static fn (int $row): string => (string)$sheet->getCell('A' . $row)->getValue(), range(8, 11)));
        self::assertSame('DESCRIÇÃO X', $sheet->getCell('E8')->getValue());
        self::assertSame('SERVIÇO DIFERENTE', $sheet->getCell('G8')->getValue());
        self::assertSame('Mão de obra', $sheet->getCell('L8')->getValue());
        self::assertSame('Material', $sheet->getCell('L10')->getValue());
        self::assertSame('008382', $sheet->getCell('N8')->getValue());
        self::assertSame('002075', $sheet->getCell('P10')->getValue());
        foreach (['O8' => '=PERIGOSO', 'Q10' => '+MATERIAL'] as $cell => $value) {
            self::assertSame($value, $sheet->getCell($cell)->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType());
        }
        self::assertSame('25/09/2026', $sheet->getCell('R8')->getFormattedValue());
        self::assertSame('09:30', $sheet->getCell('T8')->getFormattedValue());
        $book->disconnectWorksheets();
    }

    public function testEmptyAndOverflowBehavior(): void
    {
        $report = new OrderEntryExcelReport();
        $book = $report->workbook(['available' => true, 'entries' => [], 'filters' => []], new \DateTimeImmutable());
        self::assertSame(0, $book->getActiveSheet()->getCell('C4')->getValue());
        self::assertSame('Número da OS', $book->getActiveSheet()->getCell('A6')->getValue());
        $book->disconnectWorksheets();
    }
}
