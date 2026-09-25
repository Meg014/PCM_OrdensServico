<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use XMLWriter;
use ZipArchive;

/** Constant-memory XLSX writer. Source rows are consumed in bounded batches. */
final class StreamingXlsxReport
{
    public const BATCH_SIZE = 1000;
    private const EXCEL_MAX_ROW = 1048576;

    /** @return array{stream: resource, count: int, distinct_orders: int} */
    public function write(array $spec, callable $fetchBatch): array
    {
        $rowsFile = tmpfile();
        if ($rowsFile === false) throw new RuntimeException('Não foi possível preparar o relatório.');
        $count = 0;
        $distinctCount = 0;
        $lastOrder = null;
        try {
            for ($page = 1; ; ++$page) {
                $batch = $fetchBatch($page, self::BATCH_SIZE);
                if (!($batch['available'] ?? false)) throw new RuntimeException('Protheus temporariamente indisponível durante a exportação. Nenhum arquivo foi gerado.');
                foreach ($batch['rows'] as $source) {
                    ++$count;
                    if ($spec['table_row'] + $count > self::EXCEL_MAX_ROW) {
                        throw new \DomainException('O resultado excede o limite técnico de 1.048.576 linhas por planilha XLSX. Use filtros menores ou uma exportação CSV dedicada. Nenhum arquivo parcial foi gerado.');
                    }
                    if ($spec['distinct_label'] ?? null) {
                        $identity = (string)($source['TJ_FILIAL'] ?? '') . "\0" . (string)($source['TJ_ORDEM'] ?? '');
                        if ($identity !== $lastOrder) { ++$distinctCount; $lastOrder = $identity; }
                    }
                    fwrite($rowsFile, $this->rowXml($source, $spec['columns'], $spec['table_row'] + $count));
                }
                if (!($batch['has_more'] ?? false)) break;
                if ($batch['rows'] === []) throw new RuntimeException('Paginação inconsistente durante a exportação.');
            }
            rewind($rowsFile);
            $stream = $this->package($spec, $rowsFile, $count, $distinctCount);
            return ['stream' => $stream, 'count' => $count, 'distinct_orders' => $distinctCount];
        } catch (\Throwable $error) {
            fclose($rowsFile);
            throw $error;
        }
    }

    private function package(array $spec, mixed $rowsFile, int $count, int $distinct): mixed
    {
        $sheetPath = tempnam(sys_get_temp_dir(), 'pcm-sheet-');
        $xlsxPath = tempnam(sys_get_temp_dir(), 'pcm-xlsx-');
        if ($sheetPath === false || $xlsxPath === false) throw new RuntimeException('Não foi possível preparar o arquivo Excel.');
        $xml = new XMLWriter();
        $xml->openUri($sheetPath);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->writeAttribute('xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $last = Coordinate::stringFromColumnIndex(count($spec['columns']));
        $finalRow = max($spec['table_row'], $spec['table_row'] + $count);
        $xml->writeRaw('<sheetPr filterMode="1"/>');
        $xml->writeRaw('<dimension ref="A1:' . $last . $finalRow . '"/>');
        $xml->writeRaw('<sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="' . ($spec['table_row']) . '" topLeftCell="A' . ($spec['table_row'] + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
        $xml->writeRaw('<sheetFormatPr defaultRowHeight="15"/>');
        $xml->writeRaw('<cols>');
        foreach (array_values($spec['columns']) as $index => $column) $xml->writeRaw('<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . ($column[2] ?? 24) . '" customWidth="1"/>');
        $xml->writeRaw('</cols><sheetData>');
        $this->writeInlineRow($xml, 1, [['A1', $spec['title'], 1]]);
        $this->writeInlineRow($xml, 2, [['A2', $spec['scope'], 0]]);
        $this->writeInlineRow($xml, 3, [['A3', 'Gerado em:', 0], ['C3', Date::PHPToExcel($spec['generated']->setTimezone(new DateTimeZone('America/Sao_Paulo'))), 5, 'n']]);
        $this->writeInlineRow($xml, 4, [['A4', $spec['total_label'] . ':', 0], ['C4', $count, 0, 'n']]);
        $metadataEnd = 4;
        if ($spec['distinct_label'] ?? null) {
            $metadataEnd = 5;
            $this->writeInlineRow($xml, 5, [['A5', $spec['distinct_label'] . ':', 0], ['C5', $distinct, 0, 'n']]);
        }
        if (($spec['filter_text'] ?? '') !== '') {
            ++$metadataEnd;
            $this->writeInlineRow($xml, $metadataEnd, [['A' . $metadataEnd, $spec['filter_text'], 0]]);
        }
        $headers = [];
        $column = 1;
        foreach ($spec['columns'] as $definition) $headers[] = [Coordinate::stringFromColumnIndex($column++) . $spec['table_row'], $definition[0], 2];
        $this->writeInlineRow($xml, $spec['table_row'], $headers);
        while (!feof($rowsFile)) {
            $chunk = fread($rowsFile, 1024 * 1024);
            if ($chunk === false) throw new RuntimeException('Falha ao montar o arquivo Excel.');
            $xml->writeRaw($chunk);
        }
        $xml->writeRaw('</sheetData>');
        $xml->writeRaw('<autoFilter ref="A' . $spec['table_row'] . ':' . $last . $finalRow . '"/>');
        $merges = ['A1:' . $last . '1'];
        if (($spec['filter_text'] ?? '') !== '') $merges[] = 'A' . ($spec['table_row'] - 1) . ':' . $last . ($spec['table_row'] - 1);
        $xml->writeRaw('<mergeCells count="' . count($merges) . '">');
        foreach ($merges as $merge) $xml->writeRaw('<mergeCell ref="' . $merge . '"/>');
        $xml->writeRaw('</mergeCells>');
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
        unset($xml);

        $zip = new ZipArchive();
        if ($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sheetPath); @unlink($xlsxPath);
            throw new RuntimeException('Não foi possível criar o XLSX.');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($spec['sheet_name']));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        if (!$zip->close()) {
            @unlink($sheetPath); @unlink($xlsxPath);
            throw new RuntimeException('Não foi possível finalizar o XLSX.');
        }
        $source = fopen($xlsxPath, 'rb');
        $xlsxFile = tmpfile();
        if ($source === false || $xlsxFile === false || stream_copy_to_stream($source, $xlsxFile) === false) {
            if (is_resource($source)) fclose($source);
            if (is_resource($xlsxFile)) fclose($xlsxFile);
            @unlink($sheetPath); @unlink($xlsxPath);
            throw new RuntimeException('Não foi possível abrir o XLSX.');
        }
        fclose($source);
        unlink($sheetPath);
        unlink($xlsxPath);
        rewind($xlsxFile);
        fclose($rowsFile);
        return $xlsxFile;
    }

    private function rowXml(array $source, array $columns, int $row): string
    {
        $cells = '';
        $index = 1;
        foreach ($columns as $key => $definition) {
            $coordinate = Coordinate::stringFromColumnIndex($index++) . $row;
            $value = $source[$key] ?? '';
            if (isset($definition[3])) $value = $definition[3]($source);
            $type = $definition[1];
            if ($type === 'date') {
                $value = $this->dateSerial($value);
                if ($value !== null) $cells .= '<c r="' . $coordinate . '" s="3"><v>' . $value . '</v></c>';
            } elseif ($type === 'time') {
                $value = $this->timeSerial($value);
                if ($value !== null) $cells .= '<c r="' . $coordinate . '" s="4"><v>' . $value . '</v></c>';
            } elseif ($type === 'datetime') {
                [$date, $time] = is_array($value) ? array_pad($value, 2, '') : [$value, ''];
                $date = $this->dateSerial($date);
                $time = $this->timeSerial($time);
                if ($date !== null && $time !== null) {
                    $cells .= '<c r="' . $coordinate . '" s="5"><v>' . ($date + $time) . '</v></c>';
                } elseif ($date !== null) {
                    // A missing time remains a date-only value; never imply midnight.
                    $cells .= '<c r="' . $coordinate . '" s="3"><v>' . $date . '</v></c>';
                }
            } else {
                $cells .= $this->inlineCell($coordinate, is_scalar($value) ? rtrim((string)$value) : '', 0);
            }
        }
        return '<row r="' . $row . '">' . $cells . '</row>';
    }

    private function writeInlineRow(XMLWriter $xml, int $row, array $cells): void
    {
        $xml->writeRaw('<row r="' . $row . '">');
        foreach ($cells as $cell) {
            [$coordinate, $value, $style] = $cell;
            $xml->writeRaw(($cell[3] ?? 's') === 'n' ? '<c r="' . $coordinate . '" s="' . $style . '"><v>' . $value . '</v></c>' : $this->inlineCell($coordinate, (string)$value, $style));
        }
        $xml->writeRaw('</row>');
    }

    private function inlineCell(string $coordinate, string $value, int $style): string
    {
        $value = $this->sanitizeXmlText($value);
        if (mb_strlen($value, 'UTF-8') > 32767) throw new \DomainException('Uma célula excede 32.767 caracteres. Nenhum arquivo foi gerado.');
        return '<c r="' . $coordinate . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
    }

    private function sanitizeXmlText(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        return (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    }

    private function dateSerial(mixed $value): ?float
    {
        if (!is_scalar($value) || !preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/D', rtrim((string)$value), $p) || !checkdate((int)$p[2], (int)$p[3], (int)$p[1])) return null;
        return Date::PHPToExcel(new DateTimeImmutable($p[1] . '-' . $p[2] . '-' . $p[3]));
    }

    private function timeSerial(mixed $value): ?float
    {
        if (!is_scalar($value) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/D', rtrim((string)$value), $p)) return null;
        return ((int)$p[1] * 60 + (int)$p[2]) / 1440;
    }

    private function contentTypes(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>'; }
    private function rootRelationships(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'; }
    private function workbookXml(string $name): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets></workbook>'; }
    private function workbookRelationships(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'; }
    private function stylesXml(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="3"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="hh:mm"/><numFmt numFmtId="166" formatCode="dd/mm/yyyy hh:mm"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF285780"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'; }
}
