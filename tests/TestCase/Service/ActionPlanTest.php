<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ActionPlanExcelReport;
use App\Service\ActionPlanService;
use App\Service\Protheus\ActionPlanQuery;
use App\Service\Protheus\ProtheusQueries;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ActionPlanTest extends TestCase
{
    private function template(): string
    {
        return dirname(__DIR__, 3) . '/resources/templates/PLANO DE AÇÃO 2026 ATUALIZADO.xlsx';
    }

    private function order(): array
    {
        return ['number' => '000007', 'date' => '2026-10-01', 'sector' => 'Setor',
            'equipment' => 'Equipamento', 'mechanics' => 'Ana / José',
            'description' => '=texto & <ação>', 'service' => 'Serviço', 'status' => 'Aberta'];
    }

    public function testPeriodAndClosedReadAllowlist(): void
    {
        self::assertSame(['date_start' => '2024-02-29', 'date_end' => '2026-10-20'],
            ActionPlanService::period(['date_start' => '2024-02-29', 'date_end' => '2026-10-20']));
        self::assertTrue(ProtheusQueries::allows(ActionPlanQuery::SQL));
        self::assertFalse(ProtheusQueries::allows(ActionPlanQuery::SQL . '; DELETE FROM STJ010'));
        self::assertStringContainsString('CASE WHEN a.entry_count > 0 THEN a.first_entry', ActionPlanQuery::SQL);
        self::assertStringNotContainsString('EXISTS', ActionPlanQuery::SQL);
        foreach ([['2026-02-30', '2026-03-01'], ['2026-10-20', '2026-01-01'], ['', '2026-01-01'],
            ['2026-1-01', '2026-01-01'], [[], '2026-01-01']] as [$start, $end]) {
            try {
                ActionPlanService::period(['date_start' => $start, 'date_end' => $end]);
                self::fail('Invalid period accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOneRowPerBranchAndOrderAndEntireMechanicHistory(): void
    {
        $row = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000007', 'TJ_TERMINO' => 'N',
            'reference_date' => '2026-10-01', 'cost_center_name' => null, 'equipment_name' => null,
            'description' => "Ação\0 ", 'service_name' => null, 'TL_CODIGO' => '001',
            'professional_name' => 'Ana', 'identity_count' => 1, 'TL_TIPOREG' => 'M'];
        $rows = [$row, $row, array_replace($row, ['TL_CODIGO' => '002', 'professional_name' => 'José']),
            array_replace($row, ['TL_CODIGO' => '003']),
            array_replace($row, ['TJ_FILIAL' => '02', 'TJ_TERMINO' => 'S', 'TL_CODIGO' => null, 'professional_name' => null])];
        $orders = (new ActionPlanService())->group($rows);
        self::assertCount(2, $orders);
        self::assertSame('000007', $orders[0]['number']);
        self::assertSame('Ana / José', $orders[0]['mechanics']);
        self::assertSame('', $orders[1]['mechanics']);
        self::assertSame('Aberta', $orders[0]['status']);
        self::assertSame('Finalizada', $orders[1]['status']);
        self::assertSame('Ação', $orders[0]['description']);
    }

    public function testDescriptionPrecedenceFirstValidEntryAndEmptySources(): void
    {
        $base = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000001', 'TJ_TERMINO' => 'N',
            'reference_date' => '2026-09-01', 'cost_center_name' => null, 'equipment_name' => null,
            'description' => "Descrição própria\0 ", 'service_name' => null, 'TL_CODIGO' => '001',
            'professional_name' => 'Ana', 'identity_count' => 1, 'TL_TIPOREG' => 'M',
            'entry_description' => 'Texto do apontamento'];
        $blank = array_replace($base, ['TJ_ORDEM' => '000002', 'description' => " \0\t\r\n ",
            'entry_description' => "\0 \r\n", 'TL_TIPOREG' => 'P']);
        $rows = [$base, $blank,
            array_replace($blank, ['entry_description' => " Primeira descrição válida\0 "]),
            array_replace($blank, ['entry_description' => 'Segunda descrição, não concatenar', 'TL_TIPOREG' => 'M']),
            array_replace($base, ['TJ_ORDEM' => '000003', 'description' => null, 'entry_description' => null]),
            array_replace($base, ['TJ_ORDEM' => '000004', 'description' => '', 'entry_description' => '']),
            array_replace($blank, ['TJ_FILIAL' => '02', 'entry_description' => 'Outra filial'])];
        $orders = (new ActionPlanService())->group($rows);
        self::assertCount(5, $orders);
        self::assertSame(['Descrição própria', 'Primeira descrição válida', '', '', 'Outra filial'], array_column($orders, 'description'));
        self::assertSame(['Ana', 'Ana', 'Ana', 'Ana', ''], array_column($orders, 'mechanics'));
        foreach ($orders as $order) {
            self::assertSame('2026-09-01', $order['date']);
            self::assertSame('Aberta', $order['status']);
        }
        $stream = (new ActionPlanExcelReport($this->template()))->write($orders);
        $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
        try {
            foreach ($orders as $index => $order) {
                self::assertSame($order['description'], (string)$book->getSheet(0)->getCell('H' . ($index + 9))->getValue());
                self::assertSame($order['number'], (string)$book->getSheet(0)->getCell('K' . ($index + 9))->getValue());
            }
        } finally {
            $book->disconnectWorksheets();
            fclose($stream);
        }
    }

    public function testServiceDescriptionIsLastResortAndBinaryIsNotUsefulText(): void
    {
        $base = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000001', 'TJ_TERMINO' => 'N',
            'reference_date' => '2026-10-01', 'cost_center_name' => null, 'equipment_name' => null,
            'description' => 'Descrição específica', 'service_name' => 'PREVENTIVA MECANICA',
            'TL_TIPOREG' => null, 'identity_count' => 1, 'entry_description' => 'Apontamento'];
        $rows = [$base,
            array_replace($base, ['TJ_ORDEM' => '000002', 'description' => null]),
            array_replace($base, ['TJ_ORDEM' => '000003', 'description' => '  ', 'entry_description' => null]),
            array_replace($base, ['TJ_ORDEM' => '000004', 'description' => "\xFF\x01", 'entry_description' => "\0 \t\r\n"]),
            array_replace($base, ['TJ_ORDEM' => '000005', 'description' => "\x01binary", 'entry_description' => "\xFE", 'service_name' => null]),
            array_replace($base, ['TJ_ORDEM' => '000006', 'description' => "\u{00A0}\u{2003}", 'entry_description' => "\u{FFFD}"]),
            array_replace($base, ['TJ_ORDEM' => '000007', 'description' => '', 'entry_description' => "\x02"]),
            array_replace($base, ['TJ_ORDEM' => '000007', 'description' => '', 'entry_description' => 'Primeiro texto legível']),
            array_replace($base, ['TJ_ORDEM' => '000007', 'description' => '', 'entry_description' => 'Outro texto']),
            array_replace($base, ['TJ_ORDEM' => '000008', 'description' => "Descrição útil com um controle\x02isolado."])];
        $orders = (new ActionPlanService())->group($rows);
        self::assertCount(8, $orders);
        self::assertSame(['Descrição específica', 'Apontamento', 'PREVENTIVA MECANICA',
            'PREVENTIVA MECANICA', '', 'PREVENTIVA MECANICA', 'Primeiro texto legível',
            'Descrição útil com um controleisolado.'], array_column($orders, 'description'));
        self::assertSame(array_fill(0, 8, ''), array_column($orders, 'mechanics'));
    }

    public function testTemplatePartsAndExistingLayoutArePreservedExactly(): void
    {
        $hash = hash_file('sha256', $this->template());
        $stream = (new ActionPlanExcelReport($this->template()))->write([
            $this->order(), array_replace($this->order(), ['number' => '000008', 'status' => 'Finalizada']),
        ]);
        $path = stream_get_meta_data($stream)['uri'];
        $original = new ZipArchive();
        $output = new ZipArchive();
        self::assertTrue($original->open($this->template()) === true);
        self::assertTrue($output->open($path) === true);
        try {
            self::assertSame($original->numFiles, $output->numFiles);
            for ($i = 0; $i < $original->numFiles; $i++) {
                $part = $original->getNameIndex($i);
                if (!in_array($part, ['xl/worksheets/sheet1.xml', 'xl/styles.xml'], true)) {
                    self::assertSame($original->getFromName($part), $output->getFromName($part), $part);
                }
            }
            $originalStyles = $this->xml($original->getFromName('xl/styles.xml'));
            $outputStyles = $this->xml($output->getFromName('xl/styles.xml'));
            $styleXpath = $this->xpath($outputStyles);
            $dxfs = $styleXpath->query('/s:styleSheet/s:dxfs')->item(0);
            $differentials = $styleXpath->query('s:dxf', $dxfs);
            $originalCount = $differentials->length - 2;
            foreach (['FFC00000', 'FF008000'] as $i => $color) {
                $dxf = $differentials->item($originalCount + $i);
                self::assertSame($color, $styleXpath->query('s:fill/s:patternFill/s:fgColor', $dxf)->item(0)->getAttribute('rgb'));
                self::assertSame('solid', $styleXpath->query('s:fill/s:patternFill', $dxf)->item(0)->getAttribute('patternType'));
                self::assertSame('FFFFFFFF', $styleXpath->query('s:font/s:color', $dxf)->item(0)->getAttribute('rgb'));
                self::assertSame(0, $styleXpath->query('s:border | s:alignment | s:font/s:name | s:font/s:sz', $dxf)->length);
                $dxfs->removeChild($dxf);
            }
            $dxfs->setAttribute('count', (string)$originalCount);
            self::assertSame($originalStyles->C14N(), $outputStyles->C14N());
            $before = $this->xml($original->getFromName('xl/worksheets/sheet1.xml'));
            $after = $this->xml($output->getFromName('xl/worksheets/sheet1.xml'));
            $afterXpath = $this->xpath($after);
            $newRules = $afterXpath->query('/s:worksheet/s:conditionalFormatting')->item(0);
            self::assertSame('L9:L10', $newRules->getAttribute('sqref'));
            foreach ($afterXpath->query('s:cfRule', $newRules) as $i => $rule) {
                self::assertSame((string)($i + 1), $rule->getAttribute('priority'));
                self::assertSame('1', $rule->getAttribute('stopIfTrue'));
                self::assertSame((string)($originalCount + $i), $rule->getAttribute('dxfId'));
                self::assertSame(['"Aberta"', '"Finalizada"'][$i], $afterXpath->query('s:formula', $rule)->item(0)->textContent);
            }
            $newRules->parentNode->removeChild($newRules);
            foreach ($afterXpath->query('//s:cfRule') as $rule) {
                self::assertGreaterThan(2, (int)$rule->getAttribute('priority'));
                $rule->setAttribute('priority', (string)((int)$rule->getAttribute('priority') - 2));
            }
            // Headers, styles, merges, row/column dimensions, image anchors, print and conditional rules.
            foreach ([$before, $after] as $document) {
                $xp = $this->xpath($document);
                $data = $xp->query('/s:worksheet/s:sheetData')->item(0);
                foreach ($xp->query('s:row', $data) as $row) {
                    if ((int)$row->getAttribute('r') < 9) {
                        continue;
                    }
                    foreach ($xp->query('s:c', $row) as $cell) {
                        foreach (iterator_to_array($xp->query('s:v | s:is | s:f', $cell)) as $value) {
                            $cell->removeChild($value);
                        }
                        $cell->removeAttribute('t');
                    }
                }
                $xp->query('/s:worksheet/s:autoFilter')->item(0)->setAttribute('ref', 'B7:L10');
            }
            self::assertSame($before->C14N(), $after->C14N());
        } finally {
            $original->close();
            $output->close();
        }
        $book = IOFactory::load($path);
        try {
            $sheet = $book->getSheet(0);
            self::assertSame('000007', (string)$sheet->getCell('K9')->getValue());
            self::assertFalse(is_numeric($sheet->getCell('K9')->getValue()));
            self::assertSame('=texto & <ação>', (string)$sheet->getCell('H9')->getValue());
            self::assertFalse($sheet->getCell('H9')->isFormula());
            self::assertSame('Ana / José', (string)$sheet->getCell('D9')->getValue());
            self::assertSame('Ana / José', (string)$sheet->getCell('I9')->getValue());
            self::assertEquals(Date::stringToExcel('2026-10-01'), $sheet->getCell('E9')->getValue());
            self::assertSame('Finalizada', (string)$sheet->getCell('L10')->getValue());
            $rules = $sheet->getConditionalStyles('L9:L10');
            self::assertCount(2, $rules);
            self::assertSame('FFC00000', $rules[0]->getStyle()->getFill()->getStartColor()->getARGB());
            self::assertSame('FF008000', $rules[1]->getStyle()->getFill()->getStartColor()->getARGB());
            self::assertTrue($rules[0]->getStopIfTrue());
            self::assertTrue($rules[1]->getStopIfTrue());
            self::assertNull($sheet->getCell('K11')->getValue());
            self::assertNull($sheet->getCell('K1138')->getValue());
            self::assertCount(7, $book->getAllSheets());
        } finally {
            $book->disconnectWorksheets();
            fclose($stream);
        }
        self::assertSame($hash, hash_file('sha256', $this->template()));
    }

    public function testExpansionAndEmptyPopulation(): void
    {
        foreach ([0, 2113] as $count) {
            $stream = (new ActionPlanExcelReport($this->template()))->write(array_fill(0, $count, $this->order()));
            $path = stream_get_meta_data($stream)['uri'];
            $book = IOFactory::load($path);
            try {
                $sheet = $book->getSheet(0);
                self::assertSame('B7:L' . max(8, $count + 8), $sheet->getAutoFilter()->getRange());
                if ($count === 0) {
                    self::assertNull($sheet->getCell('K9')->getValue());
                } else {
                    self::assertSame('000007', (string)$sheet->getCell('K2121')->getValue());
                    self::assertSame($sheet->getStyle('K10')->exportArray(), $sheet->getStyle('K2121')->exportArray());
                    self::assertSame($sheet->getRowDimension(10)->getRowHeight(), $sheet->getRowDimension(2121)->getRowHeight());
                    self::assertSame('A1:S2121', $sheet->calculateWorksheetDimension());
                    $rules = array_values(array_filter($sheet->getConditionalStyles('L9:L2121'),
                        static fn($rule): bool => $rule->getPriority() <= 2));
                    self::assertCount(2, $rules);
                    self::assertSame('FFC00000', $rules[0]->getStyle()->getFill()->getStartColor()->getARGB());
                    self::assertSame('FF008000', $rules[1]->getStyle()->getFill()->getStartColor()->getARGB());
                }
            } finally {
                $book->disconnectWorksheets();
                fclose($stream);
            }
        }
    }

    private function xml(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET);

        return $dom;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $xpath;
    }
}
