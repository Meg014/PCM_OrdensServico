<?php
declare(strict_types=1);
namespace App\Service;
use App\Service\Protheus\ExecutedServicePresentation;
use ZipArchive;

/** Adds provenance as cell notes without changing the approved sheet, columns or cell values. */
final class ExcelAuditComments
{
    public static function attach(ZipArchive $zip, string $sheet, array $orders): string
    {
        if ($orders === []) return $sheet;
        $escape = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $comments = '<comments xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><authors><author>PCM — Rastreabilidade</author></authors><commentList>';
        $vml = '<xml xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><v:shapetype id="_x0000_t202" coordsize="21600,21600" o:spt="202" path="m,l,21600r21600,l21600,xe"/>';
        foreach ($orders as $i => $order) {
            $summary = ExecutedServicePresentation::resolve($order['executed_service'] ?? null, $order);
            $note = 'Origem: ' . $summary['source_label'] . "\nFilial: " . ($order['branch'] ?? '') . "\nO.S.: " . $order['number'];
            foreach ($order['closure_metadata'] ?? [] as $field => $value) {
                if (trim((string)$value) !== '') $note .= "\n" . $field . ': ' . $value;
            }
            if ($summary['status'] === 'CANCELADA') $note .= "\n" . ($order['cancellation']['reason'] ?? 'Motivo do cancelamento não informado no TOTVS.');
            $comments .= '<comment ref="M' . ($i + 9) . '" authorId="0"><text><t xml:space="preserve">' . $escape($note) . '</t></text></comment>';
            $vml .= '<v:shape id="_x0000_s' . (1025 + $i) . '" type="#_x0000_t202" style="position:absolute;width:360pt;height:120pt;visibility:hidden"><v:textbox/><x:ClientData ObjectType="Note"><x:MoveWithCells/><x:SizeWithCells/><x:AutoFill>False</x:AutoFill><x:Row>' . ($i + 8) . '</x:Row><x:Column>12</x:Column></x:ClientData></v:shape>';
        }
        $zip->addFromString('xl/comments_pcm.xml', $comments . '</commentList></comments>');
        $zip->addFromString('xl/drawings/comments_pcm.vml', $vml . '</xml>');
        $relations = (string)$zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        $relations = str_replace('</Relationships>', '<Relationship Id="pcmAuditComments" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="../comments_pcm.xml"/><Relationship Id="pcmAuditVml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/vmlDrawing" Target="../drawings/comments_pcm.vml"/></Relationships>', $relations);
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $relations);
        $types = (string)$zip->getFromName('[Content_Types].xml');
        $extra = '<Override PartName="/xl/comments_pcm.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.comments+xml"/>';
        if (!str_contains($types, 'Extension="vml"')) $extra .= '<Default Extension="vml" ContentType="application/vnd.openxmlformats-officedocument.vmlDrawing"/>';
        $zip->addFromString('[Content_Types].xml', str_replace('</Types>', $extra . '</Types>', $types));
        $drawing = '<legacyDrawing xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="pcmAuditVml"/>';
        if (preg_match('/<(?:legacyDrawingHF|picture|oleObjects|controls|webPublishItems|tableParts|extLst)\b/', $sheet, $match, PREG_OFFSET_CAPTURE)) {
            return substr_replace($sheet, $drawing, $match[0][1], 0);
        }
        return str_replace('</worksheet>', $drawing . '</worksheet>', $sheet);
    }
}
