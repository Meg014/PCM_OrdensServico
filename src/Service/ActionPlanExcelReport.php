<?php
declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use DomainException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use ZipArchive;

/** Populate through PhpSpreadsheet; retain original OOXML to avoid lossy workbook round-trips. */
final class ActionPlanExcelReport
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function __construct(private readonly ?string $template = null)
    {
    }

    /** @return resource Download owns this temporary stream; no generated file is retained. */
    public function write(array $orders)
    {
        $template = $this->template ?? ROOT . '/resources/templates/PLANO DE AÇÃO 2026 ATUALIZADO.xlsx';
        if (!is_file($template)) {
            throw new DomainException('O modelo original do Plano de Ação não está disponível.');
        }
        if (count($orders) > 1048568) {
            throw new DomainException('O período excede a capacidade de linhas do Excel.');
        }
        $book = IOFactory::load($template);
        $sheet = $book->getSheetByName('PLANO DE AÇAO 2026');
        if ($sheet === null || $book->getSheetCount() !== 7 || $book->getIndex($sheet) !== 0) {
            throw new DomainException('O modelo do Plano de Ação não corresponde ao arquivo esperado.');
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
                    'H' => $order['description'], 'I' => $order['mechanics'],
                    'J' => $order['service'], 'K' => $order['number'], 'L' => $order['status']];
                foreach ($values as $column => $value) {
                    if (is_string($value) && mb_strlen($value, 'UTF-8') > 32767) {
                        throw new DomainException('Um texto excede o limite de uma célula do Excel. Nenhum arquivo foi gerado.');
                    }
                    $address = $column . $index;
                    $sheet->setCellValueExplicit($address, $value, $column === 'E' ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    $value = $sheet->getCell($address)->getValue();
                    $cell = $xpath->query('s:c[@r="' . $address . '"]', $row)->item(0);
                    if (!$cell instanceof DOMElement) {
                        $cell = $dom->createElementNS(self::NS, 'c');
                        $cell->setAttribute('r', $address);
                        $cell->setAttribute('s', (string)$sheet->getCell($column . '10')->getXfIndex());
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
            }
            // Keep the original layout; only data, range endpoints and export color rules change.
            $xml = preg_replace_callback('~<sheetData\b[^>]*>.*?</sheetData>~s', fn() => $dom->saveXML($data), $xml, 1);
            $last = max(8, count($orders) + 8);
            $xml = preg_replace('/(<autoFilter\b[^>]*\bref=")[^"]*(")/', '${1}B7:L' . $last . '$2', $xml, 1);
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
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    /** Override inherited colors only in exported K/L cells, preserving borders and base styles. */
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
        // Excel evaluates smaller priorities first. Block old rules only in the exported K/L cells.
        $xml = preg_replace_callback('/(<cfRule\b[^>]*\bpriority=")([0-9]+)(")/',
            static fn(array $match): string => $match[1] . ((int)$match[2] + 3) . $match[3], $xml);
        $rules = '<conditionalFormatting sqref="L9:L' . $last . '">'
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
