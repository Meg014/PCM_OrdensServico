<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\OrderStreamingExport;
use App\Service\StreamingXlsxReport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OrderStreamingExportTest extends TestCase
{
    public function testGeneralOrderColumnsAreCompactAndUseConfirmedOperationalFields(): void
    {
        $method = new ReflectionMethod(OrderStreamingExport::class, 'orderColumns');
        $columns = $method->invoke(new OrderStreamingExport(), 'orders');

        self::assertSame([
            'TJ_ORDEM', 'descricao', 'TJ_CODBEM', 'equipment_name', 'TJ_SERVICO', 'service_name',
            'TJ_TIPO', 'TJ_CODAREA', 'TJ_CCUSTO', 'situation_label', 'finished_label',
            'TJ_DTORIGI', 'actual_start', 'actual_end',
        ], array_keys($columns));
        self::assertArrayNotHasKey('TJ_FILIAL', $columns);
        self::assertSame('Descrição/Nome do serviço', $columns['service_name'][0]);
        self::assertSame('LIBERADA', $columns['situation_label'][3](['TJ_SITUACA' => 'L ']));
        self::assertSame('CANCELADA', $columns['situation_label'][3](['TJ_SITUACA' => 'C']));
        self::assertSame('PENDENTE', $columns['situation_label'][3](['TJ_SITUACA' => 'P']));
        self::assertSame('X', $columns['situation_label'][3](['TJ_SITUACA' => 'X']));
        self::assertSame('SIM', $columns['finished_label'][3](['TJ_TERMINO' => 'S']));
        self::assertSame('NÃO', $columns['finished_label'][3](['TJ_TERMINO' => 'N']));
        self::assertSame('Data de origem', $columns['TJ_DTORIGI'][0]);
        self::assertSame(['20260925', '07:45'], $columns['actual_start'][3]([
            'TJ_DTMRINI' => '20260925', 'TJ_HOMRINI' => '07:45',
        ]));
        self::assertSame(['20260925', '13:40'], $columns['actual_end'][3]([
            'TJ_DTMRFIM' => '20260925', 'TJ_HOMRFIM' => '13:40',
        ]));
    }

    public function testEquipmentAndSectorSchemasRemainUnchanged(): void
    {
        $method = new ReflectionMethod(OrderStreamingExport::class, 'orderColumns');
        $export = new OrderStreamingExport();

        self::assertArrayHasKey('TJ_FILIAL', $method->invoke($export, 'sector'));
        self::assertArrayHasKey('TJ_DTORIGI', $method->invoke($export, 'equipment'));
    }

    public function testGeneralWorkbookUsesOnlyValidatedOriginDate(): void
    {
        $method = new ReflectionMethod(OrderStreamingExport::class, 'orderColumns');
        $columns = $method->invoke(new OrderStreamingExport(), 'orders');
        $spec = ['title' => 'R', 'scope' => 'S', 'generated' => new \DateTimeImmutable('2026-09-25 15:05:00'),
            'total_label' => 'Total', 'filter_text' => '', 'table_row' => 5, 'sheet_name' => 'OS',
            'columns' => $columns];
        $row = array_fill_keys(array_keys($columns), '');
        $row['TJ_ORDEM'] = '005464';
        $row['TJ_DTORIGI'] = '20260923';
        $result = (new StreamingXlsxReport())->write($spec, static fn (): array => [
            'available' => true, 'rows' => [$row], 'has_more' => false,
        ]);

        $book = IOFactory::load(stream_get_meta_data($result['stream'])['uri']);
        $sheet = $book->getActiveSheet();
        self::assertSame('Data de origem', $sheet->getCell('L5')->getFormattedValue());
        self::assertSame('005464', $sheet->getCell('A6')->getFormattedValue());
        self::assertSame('23/09/2026', $sheet->getCell('L6')->getFormattedValue());
        self::assertSame('Data/Hora de início', $sheet->getCell('M5')->getFormattedValue());
        self::assertSame('Data/Hora de fechamento', $sheet->getCell('N5')->getFormattedValue());
        $book->disconnectWorksheets();
        fclose($result['stream']);
    }
}
