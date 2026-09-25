<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\StreamingXlsxReport;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class StreamingXlsxReportTest extends TestCase
{
    public function testMoreThanFiveThousandRowsAreWrittenAcrossBatchesWithoutLoss(): void
    {
        $calls = [];
        $spec = ['title' => 'RELATÓRIO', 'scope' => 'Abrangência: Todos os setores',
            'generated' => new \DateTimeImmutable('2026-09-25T17:00:00Z'), 'total_label' => 'Total de OS',
            'filter_text' => 'Filtros: Área/Setor: ELETRI', 'table_row' => 6, 'sheet_name' => 'Teste',
            'columns' => ['TJ_ORDEM' => ['Número da OS', 'text'], 'descricao' => ['Descrição', 'text'],
                'data' => ['Data', 'date'], 'hora' => ['Hora', 'time']]];
        $result = (new StreamingXlsxReport())->write($spec, function (int $page, int $limit) use (&$calls): array {
            $calls[] = [$page, $limit];
            $start = ($page - 1) * $limit + 1;
            $end = min(5001, $start + $limit - 1);
            $rows = [];
            for ($number = $start; $number <= $end; ++$number) {
                $rows[] = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => str_pad((string)$number, 6, '0', STR_PAD_LEFT),
                    'descricao' => $number === 1 ? "=SEGURO & <xml> \" ' á\nli\x01nha\x0Bfim" : 'Linha ' . $number,
                    'data' => '20260925', 'hora' => '13:40'];
            }
            return ['available' => true, 'rows' => $rows, 'has_more' => $end < 5001];
        });
        self::assertSame(5001, $result['count']);
        self::assertCount(6, $calls);
        self::assertSame([1, 1000], $calls[0]);
        $path = stream_get_meta_data($result['stream'])['uri'];
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $required = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'];
        foreach ($required as $part) {
            self::assertNotFalse($zip->locateName($part), $part . ' must exist');
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML((string)$zip->getFromName($part)), $part . ' must be well-formed');
        }
        $worksheet = new \DOMDocument();
        $worksheet->loadXML((string)$zip->getFromName('xl/worksheets/sheet1.xml'));
        $children = [];
        foreach ($worksheet->documentElement->childNodes as $node) if ($node instanceof \DOMElement) $children[] = $node->localName;
        self::assertSame(['sheetPr', 'dimension', 'sheetViews', 'sheetFormatPr', 'cols', 'sheetData', 'autoFilter', 'mergeCells'], $children);
        $xpath = new \DOMXPath($worksheet);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        self::assertGreaterThan(0, $xpath->query('//x:c[@t="inlineStr"]/x:is/x:t')->length);
        self::assertSame('A1:D5007', $xpath->query('/x:worksheet/x:dimension')->item(0)->getAttribute('ref'));
        self::assertSame('A6:D5007', $xpath->query('/x:worksheet/x:autoFilter')->item(0)->getAttribute('ref'));
        self::assertSame('A7', $xpath->query('/x:worksheet/x:sheetViews/x:sheetView/x:pane')->item(0)->getAttribute('topLeftCell'));
        self::assertSame('5007', $xpath->query('/x:worksheet/x:sheetData/x:row[last()]')->item(0)->getAttribute('r'));
        $workbookRelationships = new \DOMDocument();
        $workbookRelationships->loadXML((string)$zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach ($workbookRelationships->getElementsByTagName('Relationship') as $relationship) {
            self::assertNotFalse($zip->locateName('xl/' . $relationship->getAttribute('Target')));
        }
        $rootRelationships = new \DOMDocument();
        $rootRelationships->loadXML((string)$zip->getFromName('_rels/.rels'));
        foreach ($rootRelationships->getElementsByTagName('Relationship') as $relationship) {
            self::assertNotFalse($zip->locateName($relationship->getAttribute('Target')));
        }
        $contentTypes = new \DOMDocument();
        $contentTypes->loadXML((string)$zip->getFromName('[Content_Types].xml'));
        foreach ($contentTypes->getElementsByTagName('Override') as $override) {
            self::assertNotFalse($zip->locateName(ltrim($override->getAttribute('PartName'), '/')));
        }
        $zip->close();
        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        self::assertSame(5001, $sheet->getCell('C4')->getValue());
        self::assertSame('000001', $sheet->getCell('A7')->getFormattedValue());
        self::assertSame('005001', $sheet->getCell('A5007')->getFormattedValue());
        $values = [];
        for ($row = 7; $row <= 5007; ++$row) $values[] = $sheet->getCell('A' . $row)->getFormattedValue();
        self::assertSame(5001, count(array_unique($values)));
        self::assertSame("=SEGURO & <xml> \" ' á\nlinhafim", $sheet->getCell('B7')->getFormattedValue());
        self::assertSame(DataType::TYPE_INLINE, $sheet->getCell('B7')->getDataType());
        self::assertSame('25/09/2026', $sheet->getCell('C7')->getFormattedValue());
        self::assertSame('13:40', $sheet->getCell('D7')->getFormattedValue());
        self::assertSame('A7', $sheet->getFreezePane());
        $book->disconnectWorksheets();
        fclose($result['stream']);
    }

    public function testFailureInLaterBatchDoesNotReturnPartialWorkbook(): void
    {
        $returned = false;
        try {
            (new StreamingXlsxReport())->write(['title' => 'R', 'scope' => 'S', 'generated' => new \DateTimeImmutable(),
                'total_label' => 'Total', 'filter_text' => '', 'table_row' => 5, 'sheet_name' => 'Teste',
                'columns' => ['TJ_ORDEM' => ['OS', 'text']]], static function (int $page) {
                if ($page === 2) throw new \RuntimeException('falha simulada');
                return ['available' => true, 'rows' => [['TJ_ORDEM' => '000001']], 'has_more' => true];
            });
            $returned = true;
        } catch (\RuntimeException $error) {
            self::assertSame('falha simulada', $error->getMessage());
        }
        self::assertFalse($returned);
    }

    public function testCombinedDateTimeDoesNotInventMidnightWhenTimeIsMissing(): void
    {
        $spec = ['title' => 'R', 'scope' => 'S', 'generated' => new \DateTimeImmutable('2026-09-25 13:40:00'),
            'total_label' => 'Total', 'filter_text' => '', 'table_row' => 5, 'sheet_name' => 'Teste',
            'columns' => ['moment' => ['Momento', 'datetime', 22, static fn (array $row): array => [$row['date'], $row['time']]]]];
        $result = (new StreamingXlsxReport())->write($spec, static fn (): array => ['available' => true,
            'rows' => [['date' => '20260925', 'time' => '13:40'], ['date' => '20260926', 'time' => ''],
                ['date' => '', 'time' => '13:40']],
            'has_more' => false]);

        $book = IOFactory::load(stream_get_meta_data($result['stream'])['uri']);
        $sheet = $book->getActiveSheet();
        self::assertSame('25/09/2026 13:40', $sheet->getCell('A6')->getFormattedValue());
        self::assertSame('dd/mm/yyyy hh:mm', $sheet->getCell('A6')->getStyle()->getNumberFormat()->getFormatCode());
        self::assertSame('26/09/2026', $sheet->getCell('A7')->getFormattedValue());
        self::assertSame('dd/mm/yyyy', $sheet->getCell('A7')->getStyle()->getNumberFormat()->getFormatCode());
        self::assertNull($sheet->getCell('A8')->getValue());
        $book->disconnectWorksheets();
        fclose($result['stream']);
    }

    public function testMoreThanFiveThousandEntriesKeepDistinctOrderCount(): void
    {
        $spec = ['title' => 'APONTAMENTOS', 'scope' => 'Abrangência: Todos os setores',
            'generated' => new \DateTimeImmutable(), 'total_label' => 'Total de apontamentos',
            'distinct_label' => 'OS distintas', 'filter_text' => '', 'table_row' => 6,
            'sheet_name' => 'Apontamentos', 'columns' => ['TJ_ORDEM' => ['OS', 'text']]];
        $result = (new StreamingXlsxReport())->write($spec, static function (int $page, int $limit): array {
            $start = ($page - 1) * $limit;
            $quantity = max(0, min($limit, 5001 - $start));
            $rows = [];
            for ($index = 0; $index < $quantity; ++$index) {
                $order = intdiv($start + $index, 2) + 1;
                $rows[] = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => str_pad((string)$order, 6, '0', STR_PAD_LEFT)];
            }
            return ['available' => true, 'rows' => $rows, 'has_more' => $start + $quantity < 5001];
        });
        self::assertSame(5001, $result['count']);
        self::assertSame(2501, $result['distinct_orders']);
        $book = IOFactory::load(stream_get_meta_data($result['stream'])['uri']);
        $sheet = $book->getActiveSheet();
        self::assertSame(5001, $sheet->getCell('C4')->getValue());
        self::assertSame(2501, $sheet->getCell('C5')->getValue());
        self::assertSame('000001', $sheet->getCell('A7')->getFormattedValue());
        self::assertSame('002501', $sheet->getCell('A5007')->getFormattedValue());
        $book->disconnectWorksheets();
        fclose($result['stream']);
    }
}
