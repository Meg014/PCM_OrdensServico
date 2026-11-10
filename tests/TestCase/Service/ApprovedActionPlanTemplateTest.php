<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ActionPlanExcelReport;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ApprovedActionPlanTemplateTest extends TestCase
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private function xml(string $xml): array
    {
        $dom = new DOMDocument(); $dom->loadXML($xml, LIBXML_NONET);
        $xp = new DOMXPath($dom); $xp->registerNamespace('s', self::NS);
        return [$dom, $xp];
    }

    public function testImportedTemplateMatchesTheApprovedVisualFingerprint(): void
    {
        $expected = json_decode(file_get_contents(ROOT . '/resources/templates/PLANO_DE_ACAO_APROVADO.layout.json'), true, flags: JSON_THROW_ON_ERROR);
        $zip = new ZipArchive(); self::assertTrue($zip->open(ROOT . '/resources/templates/PLANO_DE_ACAO_APROVADO.xlsx') === true);
        try {
            foreach ($expected['parts'] as $part => $hash) self::assertSame($hash, hash('sha256', $zip->getFromName($part)), $part);
            [$sheet, $xp] = $this->xml($zip->getFromName('xl/worksheets/sheet1.xml'));
            $layout = [];
            foreach ($xp->query('/s:worksheet/*') as $node) {
                if (in_array($node->localName, ['sheetData', 'autoFilter'], true)) continue;
                $layout[$node->localName][] = hash('sha256', $node->C14N());
            }
            self::assertSame($expected['layout'], $layout, 'Approved widths, merges, views, printing, drawing and conditional formats');
            [$strings, $sx] = $this->xml($zip->getFromName('xl/sharedStrings.xml'));
            $text = [];
            foreach ($sx->query('/s:sst/s:si') as $item) $text[] = $item->textContent;
            $headers = [];
            foreach ($xp->query('//s:row[@r<9]/s:c') as $cell) {
                $value = $xp->query('s:v | s:is', $cell)->item(0)?->textContent ?? '';
                $headers[$cell->getAttribute('r')] = ['style' => $cell->getAttribute('s'),
                    'value' => $cell->getAttribute('t') === 's' ? $text[(int)$value] : $value];
            }
            self::assertSame($expected['headers'], $headers);
            self::assertSame('Status', $headers['N7']['value']);
        } finally { $zip->close(); }
    }

    public function testTemplateContainsOneSheetAndNoHistoricalRecordsOrUnusedStrings(): void
    {
        $zip = new ZipArchive(); self::assertTrue($zip->open(ROOT . '/resources/templates/PLANO_DE_ACAO_APROVADO.xlsx') === true);
        try {
            [$book, $bx] = $this->xml($zip->getFromName('xl/workbook.xml'));
            self::assertSame(1, $bx->query('//s:sheet')->length);
            self::assertSame('PLANO DE AÇAO 2026', $bx->query('//s:sheet')->item(0)->getAttribute('name'));
            foreach ($bx->query('//s:definedName') as $name) self::assertSame('0', $name->getAttribute('localSheetId'));
            self::assertStringNotContainsString('ROTA ', $zip->getFromName('docProps/app.xml'));
            $worksheetParts = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $part = $zip->getNameIndex($i);
                if (preg_match('~^xl/worksheets/sheet[0-9]+\.xml$~', $part)) $worksheetParts++;
                self::assertDoesNotMatchRegularExpression('~/(?:sheet|printerSettings)[2-9][0-9]*\.~', $part);
            }
            self::assertSame(1, $worksheetParts);
            [$sheet, $xp] = $this->xml($zip->getFromName('xl/worksheets/sheet1.xml'));
            self::assertSame(0, $xp->query('//s:row[@r>=9]/s:c/*')->length);
            [$strings, $sx] = $this->xml($zip->getFromName('xl/sharedStrings.xml'));
            $used = [];
            foreach ($xp->query('//s:c[@t="s"]/s:v') as $value) $used[(int)$value->textContent] = true;
            self::assertCount($sx->query('//s:si')->length, $used, 'No unused legacy shared strings');
        } finally { $zip->close(); }
    }

    public function testLongCurrentDataWrapsWithoutChangingTheApprovedHeadersOrWidths(): void
    {
        $order = ['sector' => 'Teste', 'equipment' => 'Equipamento', 'mechanics' => 'Responsável',
            'date' => '2026-11-09', 'description' => str_repeat('Texto atual extenso. ', 35),
            'service' => 'Serviço', 'number' => '000042', 'tasks' => "Tarefa A\nTarefa B", 'status' => 'Finalizada'];
        $stream = (new ActionPlanExcelReport())->write([$order]);
        $base = new ZipArchive(); $zip = new ZipArchive();
        $base->open(ROOT . '/resources/templates/PLANO_DE_ACAO_APROVADO.xlsx');
        $zip->open(stream_get_meta_data($stream)['uri']);
        try {
            [$sheet, $xp] = $this->xml($zip->getFromName('xl/worksheets/sheet1.xml'));
            [$original, $ox] = $this->xml($base->getFromName('xl/worksheets/sheet1.xml'));
            foreach (['cols', 'mergeCells'] as $element) self::assertSame(
                $ox->query('/s:worksheet/s:' . $element)->item(0)->C14N(),
                $xp->query('/s:worksheet/s:' . $element)->item(0)->C14N());
            foreach ([7, 8] as $row) self::assertSame(
                $ox->query('//s:row[@r="' . $row . '"]')->item(0)->C14N(),
                $xp->query('//s:row[@r="' . $row . '"]')->item(0)->C14N());
            self::assertGreaterThan(35.1, (float)$xp->query('//s:row[@r="9"]')->item(0)->getAttribute('ht'));
            self::assertLessThanOrEqual(409, (float)$xp->query('//s:row[@r="9"]')->item(0)->getAttribute('ht'));
            self::assertSame('000042', $xp->query('//s:c[@r="K9"]/s:is/s:t')->item(0)->textContent);
            self::assertSame('Finalizada', $xp->query('//s:c[@r="N9"]/s:is/s:t')->item(0)->textContent);
            self::assertSame('Não informado', $xp->query('//s:c[@r="M9"]/s:is/s:t')->item(0)->textContent);
            $dates = $xp->query('//s:row[@r>=9]/s:c/s:is/s:t');
            self::assertLessThan(20, $dates->length, 'Only the new record is present');
        } finally { $zip->close(); $base->close(); fclose($stream); }
    }
}
