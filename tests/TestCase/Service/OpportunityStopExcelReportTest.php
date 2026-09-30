<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\OpportunityStopExcelReport;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class OpportunityStopExcelReportTest extends TestCase
{
    public function testPriorityBoundaries(): void
    {
        self::assertNull(OpportunityStopExcelReport::priorityForHours(null));
        self::assertSame(2, OpportunityStopExcelReport::priorityForHours(1.99));
        self::assertSame(1, OpportunityStopExcelReport::priorityForHours(2));
        self::assertSame(1, OpportunityStopExcelReport::priorityForHours(4));
        self::assertSame(0, OpportunityStopExcelReport::priorityForHours(4.01));
    }

    public function testEditableWorkbookRoundTripAndSafety(): void
    {
        $calls = [];
        $fetch = static function (int $page) use (&$calls): array {
            $calls[] = $page;

            return [
                'available' => true,
                'area_name' => 'Mecânica',
                'filters' => ['cost_center' => '3101005'],
                'has_more' => false,
                'orders' => [[
                    'TJ_ORDEM' => '005464',
                    'descricao' => '=SUM(1,1)',
                    'TJ_CODBEM' => 'EQ001',
                    'equipment_name' => 'MÁQUINA ÁGUA',
                    'TJ_CODAREA' => 'MECANI',
                    'TJ_CCUSTO' => '3101005',
                    'cost_center_name' => 'EXTRACAO',
                    'TJ_TIPO' => 'COR',
                    'service_name' => '=SUM(1,1)',
                ]],
            ];
        };
        $result = (new OpportunityStopExcelReport())->write([], new DateTimeImmutable('2026-09-29T14:30:00-03:00'), $fetch);
        try {
            self::assertSame([1], $calls);
            self::assertSame(1, $result['count']);
            $book = IOFactory::load(stream_get_meta_data($result['stream'])['uri']);
            $sheet = $book->getSheetByName('Paradas por Oportunidade');
            self::assertNotNull($sheet);
            self::assertSame('005464', $sheet->getCell('A6')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A6')->getDataType());
            self::assertSame('=SUM(1,1)', $sheet->getCell('C6')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('C6')->getDataType());
            self::assertSame('=IF(H6="","",IF(H6<2,2,IF(H6<=4,1,0)))', $sheet->getCell('B6')->getValue());
            self::assertSame('=IF(H16="","",IF(H16<2,2,IF(H16<=4,1,0)))', $sheet->getCell('B16')->getValue());
            self::assertSame('=StatusValues', $sheet->getCell('K6')->getDataValidation()->getFormula1());
            self::assertSame('=StatusValues', $sheet->getCell('K16')->getDataValidation()->getFormula1());
            self::assertSame('A5:L16', $sheet->getAutoFilter()->getRange());
            self::assertSame('A6', $sheet->getFreezePane());
            self::assertNotTrue($sheet->getProtection()->getSheet());
            $conditions = $sheet->getStyle('B6')->getConditionalStyles();
            self::assertCount(3, $conditions);
            self::assertSame(['F4CCCC', 'FFF2CC', 'D9EAD3'], array_map(
                static fn($condition): string => $condition->getStyle()->getFill()->getEndColor()->getRGB(),
                $conditions,
            ));
            self::assertSame('Extraído em: 29/09/2026 às 14:30', $sheet->getCell('C2')->getValue());
            self::assertSame('Oficina: Mecânica | Centro de custo: 3101005', $sheet->getCell('C3')->getValue());
            self::assertSame('EXTRACAO', $sheet->getCell('E6')->getValue());
            self::assertSame('MECÂNICA', $sheet->getCell('F6')->getValue());
            self::assertCount(1, $sheet->getDrawingCollection());
            self::assertSame('A1', $sheet->getDrawingCollection()[0]->getCoordinates());
            self::assertSame('hidden', $book->getSheetByName('Listas')->getSheetState());
            self::assertSame(
                ['CONCLUÍDO', 'EM ANDAMENTO', 'REPROGRAMADO', 'CANCELADO'],
                array_column($book->getSheetByName('Listas')->rangeToArray('A1:A4', null, false, false, false), 0),
            );
            $book->disconnectWorksheets();
        } finally {
            fclose($result['stream']);
        }
    }

    public function testPaginationIsConsumedInBatches(): void
    {
        $fetch = static fn(int $page): array => [
            'available' => true,
            'area_name' => 'Todos',
            'filters' => ['cost_center' => ''],
            'has_more' => $page === 1,
            'orders' => [[
                'TJ_ORDEM' => str_pad((string)$page, 6, '0', STR_PAD_LEFT), 'descricao' => 'OS',
                'TJ_CODBEM' => '', 'equipment_name' => '', 'TJ_CODAREA' => '', 'TJ_CCUSTO' => '',
                'TJ_TIPO' => '', 'service_name' => '',
            ]],
        ];
        $result = (new OpportunityStopExcelReport())->write([], new DateTimeImmutable(), $fetch);
        try {
            self::assertSame(2, $result['count']);
        } finally {
            fclose($result['stream']);
        }
    }
}
