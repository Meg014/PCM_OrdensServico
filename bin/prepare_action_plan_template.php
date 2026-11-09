<?php
declare(strict_types=1);

// One-time, local OOXML import. Never executed during a report request.
require dirname(__DIR__) . '/vendor/autoload.php';
$source = $argv[1] ?? '';
$target = dirname(__DIR__) . '/resources/templates/PLANO_DE_ACAO_APROVADO.xlsx';
if (!is_file($source) || realpath($source) === realpath($target)) {
    throw new RuntimeException('Informe o arquivo aprovado de origem; ele não será alterado.');
}
$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$read = static function (string $xml, string $namespace) {
    $dom = new DOMDocument();
    if (!$dom->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('XML inválido.');
    $xp = new DOMXPath($dom); $xp->registerNamespace('s', $namespace);
    return [$dom, $xp];
};
$zip = new ZipArchive();
if (!copy($source, $target) || $zip->open($target) !== true) throw new RuntimeException('Falha ao copiar o modelo.');
try {
    [$book, $bx] = $read($zip->getFromName('xl/workbook.xml'), $ns);
    $sheets = $bx->query('/s:workbook/s:sheets/s:sheet');
    if ($sheets->item(0)?->getAttribute('name') !== 'PLANO DE AÇAO 2026') throw new RuntimeException('Aba aprovada não encontrada.');
    $removedIds = [];
    foreach (iterator_to_array($sheets) as $i => $sheet) {
        if ($i === 0) continue;
        $removedIds[] = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $sheet->parentNode->removeChild($sheet);
    }
    foreach (iterator_to_array($bx->query('//s:definedName')) as $name) {
        if ($name->getAttribute('localSheetId') !== '0') $name->parentNode->removeChild($name);
        else if ($name->getAttribute('name') === '_xlnm._FilterDatabase') $name->nodeValue = "'PLANO DE AÇAO 2026'!\$B\$7:\$N\$8";
    }
    foreach ($bx->query('//s:workbookView') as $view) {
        $view->setAttribute('activeTab', '0'); $view->setAttribute('firstSheet', '0');
    }
    $zip->addFromString('xl/workbook.xml', $book->saveXML());
    $relsNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
    [$rels, $rx] = $read($zip->getFromName('xl/_rels/workbook.xml.rels'), $relsNs);
    $removedParts = [];
    foreach (iterator_to_array($rx->query('//s:Relationship')) as $rel) {
        if (!in_array($rel->getAttribute('Id'), $removedIds, true)) continue;
        $part = 'xl/' . $rel->getAttribute('Target');
        $removedParts[] = $part; $zip->deleteName($part);
        $sheetRels = dirname($part) . '/_rels/' . basename($part) . '.rels';
        if ($zip->locateName($sheetRels) !== false) {
            [$detail, $dx] = $read($zip->getFromName($sheetRels), $relsNs);
            foreach ($dx->query('//s:Relationship') as $link) {
                if (str_ends_with($link->getAttribute('Type'), '/printerSettings')) {
                    $printer = 'xl/printerSettings/' . basename($link->getAttribute('Target'));
                    if ($printer !== 'xl/printerSettings/printerSettings1.bin') {
                        $removedParts[] = $printer; $zip->deleteName($printer);
                    }
                }
            }
            $zip->deleteName($sheetRels);
        }
        $rel->parentNode->removeChild($rel);
    }
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels->saveXML());
    [$types, $tx] = $read($zip->getFromName('[Content_Types].xml'), 'http://schemas.openxmlformats.org/package/2006/content-types');
    foreach (iterator_to_array($tx->query('//s:Override')) as $type) {
        if (in_array(ltrim($type->getAttribute('PartName'), '/'), $removedParts, true)) $type->parentNode->removeChild($type);
    }
    $zip->addFromString('[Content_Types].xml', $types->saveXML());
    [$sheet, $sx] = $read($zip->getFromName('xl/worksheets/sheet1.xml'), $ns);
    foreach ($sx->query('//s:row') as $row) {
        if ((int)$row->getAttribute('r') < 9) continue;
        foreach ($sx->query('s:c', $row) as $cell) {
            while ($cell->firstChild) $cell->removeChild($cell->firstChild);
            $cell->removeAttribute('t');
        }
    }
    $sx->query('//s:autoFilter')->item(0)->setAttribute('ref', 'B7:N8');
    // Remove legacy OS texts even from unused shared strings in the ZIP package.
    [$strings, $ssx] = $read($zip->getFromName('xl/sharedStrings.xml'), $ns);
    $originalStrings = iterator_to_array($ssx->query('/s:sst/s:si'));
    $used = []; $total = 0;
    foreach ($sx->query('//s:c[@t="s"]/s:v') as $value) {
        $old = (int)$value->textContent;
        if (!isset($used[$old])) $used[$old] = count($used);
        $value->nodeValue = (string)$used[$old]; $total++;
    }
    foreach ($originalStrings as $item) $strings->documentElement->removeChild($item);
    foreach ($used as $old => $new) $strings->documentElement->appendChild($originalStrings[$old]);
    $strings->documentElement->setAttribute('count', (string)$total);
    $strings->documentElement->setAttribute('uniqueCount', (string)count($used));
    $zip->addFromString('xl/sharedStrings.xml', $strings->saveXML());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet->saveXML());
    [$app, $ax] = $read($zip->getFromName('docProps/app.xml'), 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties');
    $ax->registerNamespace('v', 'http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes');
    foreach ($ax->query('//s:HeadingPairs/v:vector/v:variant/v:i4') as $count) $count->nodeValue = '1';
    $titles = $ax->query('//s:TitlesOfParts/v:vector')->item(0);
    while ($titles->firstChild) $titles->removeChild($titles->firstChild);
    $titles->setAttribute('size', '1');
    $titles->appendChild($app->createElementNS('http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes', 'vt:lpstr', 'PLANO DE AÇAO 2026'));
    $zip->addFromString('docProps/app.xml', $app->saveXML());
} finally {
    $zip->close();
}
echo json_encode(['template' => $target, 'source_sha256' => hash_file('sha256', $source)], JSON_UNESCAPED_UNICODE), PHP_EOL;
