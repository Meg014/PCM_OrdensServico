<?php
declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use DomainException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use ZipArchive;

/** Update the original OOXML directly, avoiding full workbook hydration and lossy round-trips. */
final class ActionPlanExcelReport
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function __construct(private readonly ?string $template = null)
    {
    }

    /** @return resource Download owns this temporary stream; no generated file is retained. */
    public function write(array $orders)
    {
        $template = $this->template ?? ROOT . '/resources/templates/PLANO_DE_ACAO_APROVADO.xlsx';
        if (!is_file($template)) {
            throw new DomainException('O modelo original do Plano de Ação não está disponível.');
        }
        if (count($orders) > 1048568) {
            throw new DomainException('O período excede a capacidade de linhas do Excel.');
        }
        $path = tempnam(sys_get_temp_dir(), 'pcm_action_');
        if ($path === false) {
            throw new DomainException('Não foi possível criar o arquivo temporário.');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if (!copy($template, $path) || $zip->open($path) !== true) {
                throw new DomainException('Não foi possível abrir o modelo original.');
            }
            $opened = true;
            $workbook = new DOMDocument();
            if (!$workbook->loadXML((string)$zip->getFromName('xl/workbook.xml'), LIBXML_NONET)) throw new DomainException('Modelo inválido.');
            $workbookXpath = new DOMXPath($workbook); $workbookXpath->registerNamespace('s', self::NS);
            $sheets = $workbookXpath->query('/s:workbook/s:sheets/s:sheet');
            if ($sheets->length !== 1 || $sheets->item(0)->getAttribute('name') !== 'PLANO DE AÇAO 2026') throw new DomainException('O modelo do Plano de Ação não corresponde ao arquivo esperado.');
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($xml === false) {
                throw new DomainException('A aba principal não está disponível no modelo.');
            }
            $dom = new DOMDocument();
            $dom->loadXML($xml, LIBXML_NONET);
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('s', self::NS);
            $data = $xpath->query('/s:worksheet/s:sheetData')->item(0);
            $prototype = $xpath->query('/s:worksheet/s:sheetData/s:row[@r="10"]')->item(0)->cloneNode(true);
            $columnStyles = [];
            foreach ($xpath->query('s:c', $prototype) as $cell) $columnStyles[preg_replace('/[0-9]+$/', '', $cell->getAttribute('r'))] = $cell->getAttribute('s');
            $widths = [];
            foreach ($xpath->query('/s:worksheet/s:cols/s:col') as $column) {
                for ($i = (int)$column->getAttribute('min'); $i <= min(14, (int)$column->getAttribute('max')); $i++) {
                    $widths[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i)] = (float)$column->getAttribute('width');
                }
            }
            $rows = [];
            foreach ($xpath->query('s:row', $data) as $row) {
                $index = (int)$row->getAttribute('r');
                $rows[$index] = $row;
                if ($index < 9) {
                    continue;
                }
                // Clear every old value, including hidden columns, without changing styles or heights.
                foreach ($xpath->query('s:c', $row) as $cell) {
                    foreach (iterator_to_array($xpath->query('s:v | s:is | s:f', $cell)) as $value) {
                        $cell->removeChild($value);
                    }
                    $cell->removeAttribute('t');
                }
            }
            foreach ($orders as $offset => $order) {
                $index = 9 + $offset;
                $row = $rows[$index] ?? null;
                if ($row === null) {
                    $row = $prototype->cloneNode(true);
                    $row->setAttribute('r', (string)$index);
                    foreach ($xpath->query('s:c', $row) as $cell) {
                        $cell->setAttribute('r', preg_replace('/[0-9]+$/', (string)$index, $cell->getAttribute('r')));
                        while ($cell->firstChild) {
                            $cell->removeChild($cell->firstChild);
                        }
                        $cell->removeAttribute('t');
                    }
                    $data->appendChild($row);
                }
                $values = ['B' => $order['sector'], 'C' => $order['equipment'],
                    'D' => $order['mechanics'], 'E' => Date::stringToExcel($order['date']),
                    'H' => \App\Service\Protheus\OrderDescriptions::display($order['description']), 'I' => $order['mechanics'],
                    'J' => $order['service'], 'K' => $order['number'], 'N' => $order['status']];
                $values['L'] = $order['tasks'] ?? '';
                // Never infer execution from 'description' (also used for opening notes).
                // Producers must supply explicitly separated, confirmed execution text.
                $values['M'] = ActionPlanObservation::forExcel($order['executed_service'] ?? null);
                foreach ($values as $column => $value) {
                    if (is_string($value) && mb_strlen($value, 'UTF-8') > 32767) {
                        throw new DomainException('Um texto excede o limite de uma célula do Excel. Nenhum arquivo foi gerado.');
                    }
                    $address = $column . $index;
                    $cell = $xpath->query('s:c[@r="' . $address . '"]', $row)->item(0);
                    if (!$cell instanceof DOMElement) {
                        $cell = $dom->createElementNS(self::NS, 'c');
                        $cell->setAttribute('r', $address);
                        $cell->setAttribute('s', (string)($columnStyles[$column] ?? 0));
                        $before = null;
                        foreach ($xpath->query('s:c', $row) as $existing) {
                            $existingColumn = preg_replace('/[0-9]+$/', '', $existing->getAttribute('r'));
                            if (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($existingColumn)
                                > \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column)) {
                                $before = $existing;
                                break;
                            }
                        }
                        $row->insertBefore($cell, $before);
                    }
                    if ($column === 'E') {
                        $cell->appendChild($dom->createElementNS(self::NS, 'v', (string)$value));
                    } else {
                        $cell->setAttribute('t', 'inlineStr');
                        $inline = $cell->appendChild($dom->createElementNS(self::NS, 'is'));
                        $text = $inline->appendChild($dom->createElementNS(self::NS, 't'));
                        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                        $text->appendChild($dom->createTextNode(\PhpOffice\PhpSpreadsheet\Shared\StringHelper::controlCharacterPHP2OOXML((string)$value)));
                    }
                }
                $row->setAttribute('ht', (string)$this->rowHeight($values, $widths, (float)$row->getAttribute('ht')));
                $row->setAttribute('customHeight', '1');
            }
            $xml = preg_replace_callback('~<sheetData\b[^>]*>.*?</sheetData>~s', fn() => $dom->saveXML($data), $xml, 1);
            $last = max(8, count($orders) + 8);
            $xml = preg_replace('/(<autoFilter\b[^>]*\bref=")[^"]*(")/', '${1}B7:N' . $last . '$2', $xml, 1);
            foreach ($workbookXpath->query('/s:workbook/s:definedNames/s:definedName[@name="_xlnm._FilterDatabase"][@localSheetId="0"]') as $name) {
                $name->nodeValue = "'PLANO DE AÇAO 2026'!\$B\$7:\$N\$" . $last;
            }
            if (!$zip->addFromString('xl/workbook.xml', $workbook->saveXML())) {
                throw new DomainException('Não foi possível atualizar o intervalo de filtros.');
            }
            if ($last > 2120) {
                $xml = preg_replace('/(<dimension\b[^>]*\bref=")[^"]*(")/', '${1}A1:S' . $last . '$2', $xml, 1);
                $xml = preg_replace_callback('/(<conditionalFormatting\b[^>]*\bsqref=")([^"]+)(")/',
                    static function (array $match) use ($last): string {
                        $range = preg_replace_callback('/(:[A-Z]+)([0-9]+)/',
                            static fn(array $end): string => $end[1] . ((int)$end[2] >= 1000 && (int)$end[2] < $last ? $last : $end[2]), $match[2]);
                        return $match[1] . $range . $match[3];
                    }, $xml);
            }
            if ($orders !== []) {
                $xml = $this->statusColors($zip, $xml, $last);
            }
            if (!$zip->addFromString('xl/worksheets/sheet1.xml', $xml)) {
                throw new DomainException('Não foi possível concluir o Excel.');
            }
            $closed = $zip->close();
            $opened = false;
            if (!$closed) {
                throw new DomainException('Não foi possível concluir o Excel.');
            }
            $stream = tmpfile();
            $input = fopen($path, 'rb');
            if ($stream === false || $input === false) {
                throw new DomainException('Não foi possível disponibilizar o Excel.');
            }
            try {
                stream_copy_to_stream($input, $stream);
            } finally {
                fclose($input);
            }
            rewind($stream);

            return $stream;
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($path);
        }
    }

    /** Estimate wrapped text height using the approved widths, capped at Excel's row limit. */
    private function rowHeight(array $values, array $widths, float $minimum): float
    {
        $lines = 1;
        foreach ($values as $column => $value) {
            if (!is_string($value)) continue;
            $capacity = max(1, (int)floor(($widths[$column] ?? 20) - 2));
            $wrapped = 0;
            foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
                $wrapped += max(1, (int)ceil(mb_strwidth($line, 'UTF-8') / $capacity));
            }
            $lines = max($lines, $wrapped);
        }
        return min(409, max($minimum, $lines * 16));
    }

    /** Override inherited colors only in exported K/N cells, preserving borders and base styles. */
    private function statusColors(ZipArchive $zip, string $xml, int $last): string
    {
        $styles = $zip->getFromName('xl/styles.xml');
        if ($styles === false) {
            throw new DomainException('Os estilos do modelo não estão disponíveis.');
        }
        $dom = new DOMDocument();
        $dom->loadXML($styles, LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', self::NS);
        $dxfs = $xpath->query('/s:styleSheet/s:dxfs')->item(0);
        if (!$dxfs instanceof DOMElement) {
            throw new DomainException('As formatações condicionais do modelo não estão disponíveis.');
        }
        $firstId = $xpath->query('s:dxf', $dxfs)->length;
        foreach (['FFC00000', 'FF008000', 'FFFFFFFF'] as $offset => $color) {
            $dxf = $dxfs->appendChild($dom->createElementNS(self::NS, 'dxf'));
            $font = $dxf->appendChild($dom->createElementNS(self::NS, 'font'));
            $font->appendChild($dom->createElementNS(self::NS, 'color'))->setAttribute('rgb', $offset === 2 ? 'FF000000' : 'FFFFFFFF');
            $fill = $dxf->appendChild($dom->createElementNS(self::NS, 'fill'));
            $pattern = $fill->appendChild($dom->createElementNS(self::NS, 'patternFill'));
            $pattern->setAttribute('patternType', 'solid');
            $pattern->appendChild($dom->createElementNS(self::NS, 'fgColor'))->setAttribute('rgb', $color);
            $pattern->appendChild($dom->createElementNS(self::NS, 'bgColor'))->setAttribute('rgb', $color);
        }
        $dxfs->setAttribute('count', (string)($firstId + 3));
        $styles = preg_replace_callback('~<dxfs\b[^>]*>.*?</dxfs>~s', fn() => $dom->saveXML($dxfs), $styles, 1);
        if (!$zip->addFromString('xl/styles.xml', $styles)) {
            throw new DomainException('Não foi possível aplicar as cores dos status.');
        }
        // Excel evaluates smaller priorities first. Block old rules only in the exported K/N cells.
        $xml = preg_replace_callback('/(<cfRule\b[^>]*\bpriority=")([0-9]+)(")/',
            static fn(array $match): string => $match[1] . ((int)$match[2] + 3) . $match[3], $xml);
        $rules = '<conditionalFormatting sqref="N9:N' . $last . '">'
            . '<cfRule type="cellIs" dxfId="' . $firstId . '" priority="1" operator="equal" stopIfTrue="1">'
            . '<formula>"Aberta"</formula></cfRule>'
            . '<cfRule type="cellIs" dxfId="' . ($firstId + 1) . '" priority="2" operator="equal" stopIfTrue="1">'
            . '<formula>"Finalizada"</formula></cfRule></conditionalFormatting>'
            . '<conditionalFormatting sqref="K9:K' . $last . '">'
            . '<cfRule type="expression" dxfId="' . ($firstId + 2) . '" priority="3" stopIfTrue="1">'
            . '<formula>TRUE</formula></cfRule></conditionalFormatting>';

        return preg_replace_callback('/<conditionalFormatting\b/', static fn(): string => $rules . '<conditionalFormatting', $xml, 1);
    }
}
