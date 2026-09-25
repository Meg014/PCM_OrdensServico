<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service;

use App\Service\OrderExcelReport;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class OrderExcelReportTest extends TestCase
{
    public function testWorkbookRoundTripWithAllRowsTextSafetyDatesAndMetadata(): void
    {
        $report = new OrderExcelReport();
        $orders = [];
        foreach (['=1+1', '+SUM(A1)', '-1+2', '@SUM(A1)'] as $text) {
            $orders[] = ['TJ_ORDEM' => '000123', 'descricao' => 'DESCRIÇÃO REAL DA OS', 'TJ_FILIAL' => '01', 'TJ_CODBEM' => '000045',
                'TJ_SERVICO' => '001', 'service_name' => 'NOME DO SERVIÇO', 'equipment_name' => $text, 'planned_date' => '2026-09-25',
                'TJ_HOMPINI' => '10:35', 'record_id' => 'SECRET_RECORD', 'token' => 'SECRET_TOKEN'];
        }
        $orders = array_merge($orders, array_fill(0, 479, $orders[0]));
        $book = $report->workbook(['available' => true, 'has_more' => false, 'orders' => $orders,
            'name' => '=unsafe', 'filters' => ['equipment' => '+unsafe']], 'sector', new \DateTimeImmutable('2026-09-25T13:35:00Z'));
        $stream = $report->stream($book);
        try {
            $loaded = IOFactory::load(stream_get_meta_data($stream)['uri']);
            $sheet = $loaded->getActiveSheet();
            self::assertSame('25/09/2026 10:35', $sheet->getCell('C3')->getFormattedValue());
            self::assertSame(483, $sheet->getCell('C4')->getValue());
            self::assertSame('Filtros: Equipamento: +unsafe', $sheet->getCell('A5')->getValue());
            self::assertTrue($sheet->getStyle('A5')->getAlignment()->getWrapText());
            self::assertSame('A7', $sheet->getFreezePane());
            self::assertSame('A6:O489', $sheet->getAutoFilter()->getRange());
            foreach (['A7' => '000123', 'C7' => '01', 'D7' => '000045', 'F7' => '001',
                'E7' => '=1+1', 'E8' => '+SUM(A1)', 'E9' => '-1+2', 'E10' => '@SUM(A1)', 'A5' => 'Filtros: Equipamento: +unsafe'] as $cell => $value) {
                self::assertSame($value, $sheet->getCell($cell)->getValue());
                self::assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType());
            }
            self::assertSame('25/09/2026', $sheet->getCell('L7')->getFormattedValue());
            self::assertSame('10:35', $sheet->getCell('M7')->getFormattedValue());
            self::assertSame('DESCRIÇÃO REAL DA OS', $sheet->getCell('B7')->getValue());
            self::assertSame('NOME DO SERVIÇO', $sheet->getCell('G7')->getValue());
            $contents = json_encode($sheet->toArray());
            foreach (['SECRET', 'record_id', 'token', 'R_E_C_N_O_'] as $secret) self::assertStringNotContainsString($secret, $contents);
            $loaded->disconnectWorksheets();
        } finally {
            fclose($stream);
        }
    }

    public function testEmptyResultStillHasHeadersAndZeroTotal(): void
    {
        $report = new OrderExcelReport();
        $book = $report->workbook(['available' => true, 'orders' => [],
            'filters' => ['status' => 'Todos', 'card' => 'all', 'q' => '  ']], 'orders', $report->generatedAt());
        self::assertSame(0, $book->getActiveSheet()->getCell('C4')->getValue());
        self::assertSame('Número da OS', $book->getActiveSheet()->getCell('A5')->getValue());
        $book->disconnectWorksheets();
    }

    public function testCompactFiltersHideAbsentValuesInEveryExportContext(): void
    {
        $report = new OrderExcelReport();
        foreach (['sector', 'orders', 'equipment'] as $context) {
            $book = $report->workbook(['available' => true, 'orders' => [], 'filters' => [
                'status' => 'Todos', 'card' => 'all', 'q' => '  ', 'equipment' => 'VAU 50 003',
                'maintenance_type' => 'COR',
            ]], $context, new \DateTimeImmutable('2026-09-25T16:40:00Z'));
            $sheet = $book->getActiveSheet();
            self::assertSame('25/09/2026 13:40', $sheet->getCell('C3')->getFormattedValue());
            self::assertSame('Filtros: Equipamento: VAU 50 003 | Tipo de manutenção: COR', $sheet->getCell('A5')->getValue());
            self::assertTrue($sheet->getStyle('A5')->getAlignment()->getWrapText());
            self::assertSame('A7', $sheet->getFreezePane());
            $book->disconnectWorksheets();
        }
    }

    public function testSafeFilenameAndPresentationTimezone(): void
    {
        $report = new OrderExcelReport();
        self::assertSame('America/Sao_Paulo', $report->generatedAt()->getTimezone()->getName());
        $name = $report->filename("../../001234\r\n\";evil", new \DateTimeImmutable('2026-09-25T13:35:00Z'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+_2026-09-25_103500\.xlsx$/D', $name);
        self::assertStringContainsString('001234', $name);
    }

    public function testFailureOrOverflowNeverProducesWorkbook(): void
    {
        $report = new OrderExcelReport();
        foreach ([['available' => false], ['available' => true, 'has_more' => true]] as $data) {
            try {
                $report->workbook($data + ['orders' => [], 'filters' => []], 'sector', $report->generatedAt());
                self::fail('An incomplete report must fail.');
            } catch (\RuntimeException $error) {
                self::assertStringNotContainsString('SQLSTATE', $error->getMessage());
            }
        }
    }

    public function testOversizedTextIsRejectedInsteadOfSilentlyTruncated(): void
    {
        $report = new OrderExcelReport();
        $this->expectException(\DomainException::class);
        $report->workbook(['available' => true, 'orders' => [['descricao' => str_repeat('x', 32768)]],
            'filters' => []], 'equipment', $report->generatedAt());
    }

    public function testInsufficientMemoryIsRejectedBeforeAllocatingWorkbook(): void
    {
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', (string)(memory_get_usage(true) + 16 * 1024 * 1024));
        try {
            $report = new OrderExcelReport();
            $this->expectException(\DomainException::class);
            $report->workbook(['available' => true, 'orders' => [], 'filters' => []], 'orders', $report->generatedAt());
        } finally {
            ini_set('memory_limit', $previous);
        }
    }
}
