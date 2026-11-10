<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ActionPlanExcelReport;
use App\Service\ActionPlanService;
use App\Service\Protheus\ActionPlanQuery;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusUnit;
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
        return dirname(__DIR__, 3) . '/resources/templates/PLANO_DE_ACAO_APROVADO.xlsx';
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
        self::assertTrue(ProtheusQueries::allows(ActionPlanQuery::sql()));
        self::assertFalse(ProtheusQueries::allows(ActionPlanQuery::sql() . '; DELETE FROM STJ010'));
        self::assertStringContainsString('CASE WHEN a.entry_count > 0 THEN a.first_entry', ActionPlanQuery::sql());
        self::assertStringNotContainsString('EXISTS (SELECT 1 FROM dbo.STL010', ActionPlanQuery::sql());
        self::assertStringContainsString(ProtheusUnit::predicate('j.TJ_CCUSTO', 'f.unidade'), ActionPlanQuery::sql());
        self::assertStringContainsString('CAST(:unidade AS varchar(10))', ActionPlanQuery::sql());
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

    public function testRecordedTasksAreDistinctAndKeptSeparateFromServiceAndDescription(): void
    {
        $base = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000042', 'TJ_TERMINO' => 'S', 'reference_date' => '2026-09-01',
            'cost_center_name' => 'Fábrica', 'equipment_name' => 'Rota', 'description' => 'Planejado', 'service_name' => 'Serviço',
            'TL_TAREFA' => '000117', 'task_description' => 'CHECKLIST CONFORME DOCUMENTO', 'task_matches' => 1];
        $orders = (new ActionPlanService())->group([$base, $base, array_replace($base, ['TL_TAREFA' => '000118', 'task_description' => 'Outra tarefa'])]);
        self::assertCount(1, $orders); self::assertSame('Planejado', $orders[0]['description']); self::assertSame('Serviço', $orders[0]['service']);
        self::assertSame("000117 — CHECKLIST CONFORME DOCUMENTO\n000118 — Outra tarefa", $orders[0]['tasks']);
        $stream = (new ActionPlanExcelReport($this->template()))->write($orders);
        $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
        try {
            $sheet = $book->getSheet(0); self::assertSame('TAREFAS APONTADAS', (string)$sheet->getCell('L7')->getValue());
            self::assertSame($orders[0]['tasks'], (string)$sheet->getCell('L9')->getValue()); self::assertSame('000042', (string)$sheet->getCell('K9')->getValue());
            self::assertGreaterThan(40, $sheet->getColumnDimension('L')->getWidth());
            self::assertSame('B7:N9', $sheet->getAutoFilter()->getRange());
            self::assertSame('SERVIÇO EXECUTADO', (string)$sheet->getCell('M7')->getValue());
            self::assertSame('Não informado', (string)$sheet->getCell('M9')->getValue());
            self::assertTrue($sheet->getStyle('L9')->getAlignment()->getWrapText());
            self::assertSame('Finalizada', (string)$sheet->getCell('N9')->getValue());
        } finally { $book->disconnectWorksheets(); fclose($stream); }
        $this->expectException(\DomainException::class);
        (new ActionPlanService())->group([array_replace($base, ['task_matches' => 2])]);
    }

    public function testRouteOverridesUseOnlyExactAuditedTuplesAndDoNotChangeSharedClassification(): void
    {
        $routes = require ROOT . '/resources/data/action-plan-factory-routes.php';
        self::assertCount(25, $routes);
        foreach ($routes as $route) {
            self::assertSame('01', $route['branch']); self::assertContains($route['cost_center'], ['4101012', '5101006']);
            self::assertNotSame('factory', ProtheusUnit::classify($route['cost_center']));
        }
        $sql = ActionPlanQuery::sql();
        self::assertStringContainsString('route.equipment COLLATE DATABASE_DEFAULT = j.TJ_CODBEM', $sql);
        self::assertStringContainsString('route.service COLLATE DATABASE_DEFAULT = j.TJ_SERVICO', $sql);
        self::assertStringContainsString('t.TT9_TAREFA = l.TL_TAREFA', $sql);
        self::assertStringNotContainsString('FROM dbo.ST5010', $sql);
        self::assertTrue(ProtheusQueries::allows(ActionPlanQuery::AUDIT));
        self::assertFalse(ProtheusQueries::allows(ActionPlanQuery::AUDIT . '; UPDATE dbo.STJ010 SET TJ_TERMINO = 1'));
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
        self::assertSame(['Descrição própria', 'Primeira descrição válida', \App\Service\Protheus\OrderDescriptions::MISSING, \App\Service\Protheus\OrderDescriptions::MISSING, 'Outra filial'], array_column($orders, 'description'));
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
            'PREVENTIVA MECANICA', \App\Service\Protheus\OrderDescriptions::MISSING, 'PREVENTIVA MECANICA', 'Primeiro texto legível',
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
                if (!in_array($part, ['xl/worksheets/sheet1.xml', 'xl/styles.xml', 'xl/workbook.xml'], true)) {
                    self::assertSame($original->getFromName($part), $output->getFromName($part), $part);
                }
            }
            $originalStyles = $this->xml($original->getFromName('xl/styles.xml'));
            $outputStyles = $this->xml($output->getFromName('xl/styles.xml'));
            $styleXpath = $this->xpath($outputStyles);
            $dxfs = $styleXpath->query('/s:styleSheet/s:dxfs')->item(0);
            $differentials = $styleXpath->query('s:dxf', $dxfs);
            $originalCount = $differentials->length - 3;
            foreach (['FFC00000', 'FF008000', 'FFFFFFFF'] as $i => $color) {
                $dxf = $differentials->item($originalCount + $i);
                self::assertSame($color, $styleXpath->query('s:fill/s:patternFill/s:fgColor', $dxf)->item(0)->getAttribute('rgb'));
                self::assertSame('solid', $styleXpath->query('s:fill/s:patternFill', $dxf)->item(0)->getAttribute('patternType'));
                self::assertSame($i === 2 ? 'FF000000' : 'FFFFFFFF', $styleXpath->query('s:font/s:color', $dxf)->item(0)->getAttribute('rgb'));
                self::assertSame(0, $styleXpath->query('s:border | s:alignment | s:font/s:name | s:font/s:sz', $dxf)->length);
                $dxfs->removeChild($dxf);
            }
            $dxfs->setAttribute('count', (string)$originalCount);
            self::assertSame($originalStyles->C14N(), $outputStyles->C14N());
            $before = $this->xml($original->getFromName('xl/worksheets/sheet1.xml'));
            $after = $this->xml($output->getFromName('xl/worksheets/sheet1.xml'));
            $afterXpath = $this->xpath($after);
            $newRules = $afterXpath->query('/s:worksheet/s:conditionalFormatting')->item(0);
            self::assertSame('N9:N10', $newRules->getAttribute('sqref'));
            foreach ($afterXpath->query('s:cfRule', $newRules) as $i => $rule) {
                self::assertSame((string)($i + 1), $rule->getAttribute('priority'));
                self::assertSame('1', $rule->getAttribute('stopIfTrue'));
                self::assertSame((string)($originalCount + $i), $rule->getAttribute('dxfId'));
                self::assertSame(['"Aberta"', '"Finalizada"'][$i], $afterXpath->query('s:formula', $rule)->item(0)->textContent);
            }
            $newRules->parentNode->removeChild($newRules);
            $numberRules = $afterXpath->query('/s:worksheet/s:conditionalFormatting')->item(0);
            self::assertSame('K9:K10', $numberRules->getAttribute('sqref'));
            $numberRule = $afterXpath->query('s:cfRule', $numberRules)->item(0);
            self::assertSame('3', $numberRule->getAttribute('priority'));
            self::assertSame('1', $numberRule->getAttribute('stopIfTrue'));
            self::assertSame((string)($originalCount + 2), $numberRule->getAttribute('dxfId'));
            self::assertSame('TRUE', $afterXpath->query('s:formula', $numberRule)->item(0)->textContent);
            $numberRules->parentNode->removeChild($numberRules);
            foreach ($afterXpath->query('//s:cfRule') as $rule) {
                self::assertGreaterThan(3, (int)$rule->getAttribute('priority'));
                $rule->setAttribute('priority', (string)((int)$rule->getAttribute('priority') - 3));
            }
            // Metadata outside the changed layout remains identical.
            foreach (['sheetPr', 'sheetViews', 'sheetFormatPr', 'pageMargins', 'pageSetup', 'drawing'] as $name) {
                $beforeNode = $this->xpath($before)->query('/s:worksheet/s:' . $name)->item(0);
                $afterNode = $afterXpath->query('/s:worksheet/s:' . $name)->item(0);
                self::assertSame($beforeNode?->C14N(), $afterNode?->C14N(), $name);
            }
            foreach ([$before, $after] as $document) {
                $xp = $this->xpath($document);
                foreach (iterator_to_array($xp->query('/s:worksheet/*[not(self::s:sheetData)]')) as $node) $node->parentNode->removeChild($node);
                foreach (iterator_to_array($xp->query('//s:c')) as $cell) {
                    $column = preg_replace('/[0-9]+$/', '', $cell->getAttribute('r'));
                    if (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column) >= 12) {
                        $cell->parentNode->removeChild($cell); continue;
                    }
                    if ((int)preg_replace('/^[A-Z]+/', '', $cell->getAttribute('r')) < 9) continue;
                    foreach (iterator_to_array($xp->query('s:v | s:is | s:f', $cell)) as $value) $cell->removeChild($value);
                    $cell->removeAttribute('t');
                }
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
            self::assertSame('Finalizada', (string)$sheet->getCell('N10')->getValue());
            $rules = $sheet->getConditionalStyles('N9:N10');
            self::assertCount(2, $rules);
            self::assertSame('FFC00000', $rules[0]->getStyle()->getFill()->getStartColor()->getARGB());
            self::assertSame('FF008000', $rules[1]->getStyle()->getFill()->getStartColor()->getARGB());
            self::assertTrue($rules[0]->getStopIfTrue());
            self::assertTrue($rules[1]->getStopIfTrue());
            self::assertNull($sheet->getCell('K11')->getValue());
            self::assertNull($sheet->getCell('K1138')->getValue());
            self::assertCount(1, $book->getAllSheets());
        } finally {
            $book->disconnectWorksheets();
            fclose($stream);
        }
        self::assertSame($hash, hash_file('sha256', $this->template()));
    }

    public function testReportedNumbersOverrideInheritedRedFontAndKeepStatusColors(): void
    {
        $numbers = ['003074', '003077', '003078', '003079', '003080', '003082', '003086'];
        // Place the examples on the template rows whose base font is red.
        $orders = array_fill(0, 1130, $this->order());
        foreach ($numbers as $i => $number) {
            $orders[] = array_replace($this->order(), ['number' => $number,
                'status' => $i % 2 === 0 ? 'Aberta' : 'Finalizada']);
        }
        $stream = (new ActionPlanExcelReport($this->template()))->write($orders);
        $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
        $template = IOFactory::load($this->template());
        try {
            $sheet = $book->getSheet(0);
            $rules = $sheet->getConditionalStyles('K9:K1145');
            self::assertCount(1, $rules);
            $rule = $rules[0];
            self::assertSame([true], $rule->getConditions());
            self::assertTrue($rule->getStopIfTrue());
            self::assertSame('FF000000', $rule->getStyle()->getFont()->getColor()->getARGB());
            self::assertSame('FFFFFFFF', $rule->getStyle()->getFill()->getStartColor()->getARGB());
            self::assertSame('solid', $rule->getStyle()->getFill()->getFillType());
            foreach ($numbers as $i => $number) {
                $address = 'K' . (1139 + $i);
                self::assertSame('FFFF0000', $template->getSheet(0)->getStyle($address)->getFont()->getColor()->getARGB());
                self::assertSame($number, (string)$sheet->getCell($address)->getValue());
                self::assertSame('inlineStr', $sheet->getCell($address)->getDataType());
                self::assertSame($template->getSheet(0)->getStyle($address)->getBorders()->exportArray(),
                    $sheet->getStyle($address)->getBorders()->exportArray());
                self::assertSame($orders[1130 + $i]['status'], (string)$sheet->getCell('N' . (1139 + $i))->getValue());
            }
            $statusRules = $sheet->getConditionalStyles('N9:N1145');
            self::assertSame('FFC00000', $statusRules[0]->getStyle()->getFill()->getStartColor()->getARGB());
            self::assertSame('FF008000', $statusRules[1]->getStyle()->getFill()->getStartColor()->getARGB());
            self::assertTrue($statusRules[0]->getStopIfTrue());
            self::assertTrue($statusRules[1]->getStopIfTrue());
        } finally {
            $book->disconnectWorksheets();
            $template->disconnectWorksheets();
            fclose($stream);
        }
    }

    public function testExpansionAndEmptyPopulation(): void
    {
        foreach ([0, 2113] as $count) {
            $stream = (new ActionPlanExcelReport($this->template()))->write(array_fill(0, $count, $this->order()));
            $path = stream_get_meta_data($stream)['uri'];
            $book = IOFactory::load($path);
            try {
                $sheet = $book->getSheet(0);
                self::assertSame('B7:N' . max(8, $count + 8), $sheet->getAutoFilter()->getRange());
                self::assertSame('TAREFAS APONTADAS', (string)$sheet->getCell('L7')->getValue());
                self::assertSame('SERVIÇO EXECUTADO', (string)$sheet->getCell('M7')->getValue());
                self::assertSame('Status', (string)$sheet->getCell('N7')->getValue());
                self::assertEquals(65, $sheet->getColumnDimension('L')->getWidth());
                self::assertEquals(65, $sheet->getColumnDimension('M')->getWidth());
                self::assertLessThan(25, $sheet->getColumnDimension('N')->getWidth());
                self::assertContains('L7:L8', $sheet->getMergeCells());
                self::assertContains('M7:M8', $sheet->getMergeCells());
                self::assertContains('N7:N8', $sheet->getMergeCells());
                $archive = new ZipArchive(); self::assertTrue($archive->open($path) === true);
                $workbookXml = $this->xml($archive->getFromName('xl/workbook.xml'));
                $filterName = $this->xpath($workbookXml)->query('//s:definedName[@name="_xlnm._FilterDatabase"][@localSheetId="0"]')->item(0);
                self::assertSame("'PLANO DE AÇAO 2026'!\$B\$7:\$N\$" . max(8, $count + 8), $filterName->textContent);
                $sheetXml = $this->xml($archive->getFromName('xl/worksheets/sheet1.xml'));
                foreach ($this->xpath($sheetXml)->query('//s:conditionalFormatting') as $rule) {
                    self::assertDoesNotMatchRegularExpression('/\b[LM][0-9]/', $rule->getAttribute('sqref'));
                }
                $archive->close();
                if ($count === 0) {
                    self::assertNull($sheet->getCell('K9')->getValue());
                } else {
                    self::assertSame('000007', (string)$sheet->getCell('K2121')->getValue());
                    self::assertSame('Aberta', (string)$sheet->getCell('N2121')->getValue());
                    self::assertSame('', (string)$sheet->getCell('L2121')->getValue());
                    self::assertSame('Não informado', (string)$sheet->getCell('M2121')->getValue());
                    self::assertTrue($sheet->getStyle('L2121')->getAlignment()->getWrapText());
                    self::assertTrue($sheet->getStyle('M2121')->getAlignment()->getWrapText());
                    self::assertSame($sheet->getStyle('K10')->exportArray(), $sheet->getStyle('K2121')->exportArray());
                    self::assertSame($sheet->getRowDimension(10)->getRowHeight(), $sheet->getRowDimension(2121)->getRowHeight());
                    self::assertSame('A1:S2121', $sheet->calculateWorksheetDimension());
                    $rules = array_values(array_filter($sheet->getConditionalStyles('N9:N2121'),
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
