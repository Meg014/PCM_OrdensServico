<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\CancellationPresentation as Cancellation;
use App\Service\OrderStreamingExport;
use PHPUnit\Framework\TestCase;

final class CancellationPresentationTest extends TestCase
{
    public function testDefaultNeverBecomesOriginalReasonAndPreservesMetadata(): void
    {
        foreach ([null, '', " \r\n ", "\0"] as $reason) {
            $record = ['reason' => $reason, 'user' => 'Usuario', 'date' => '2026-11-23', 'time' => '10:30'];
            $before = $record;
            $presentation = Cancellation::present($record);
            self::assertNull($presentation['reason']);
            self::assertSame(Cancellation::DEFAULT_MESSAGE, $presentation['message']);
            self::assertSame(Cancellation::DEFAULT_SOURCE, $presentation['source_label']);
            self::assertTrue($presentation['derived']);
            self::assertSame('presentation_default', $presentation['source']);
            self::assertSame($record['user'], $presentation['user']);
            self::assertSame($record['date'], $presentation['date']);
            self::assertSame($record['time'], $presentation['time']);
            self::assertSame($presentation, Cancellation::present($presentation));
            self::assertSame($before, $record);
        }
    }

    public function testOriginalReasonIsPreservedEvenIfItsTextMatchesDefault(): void
    {
        foreach ([" Motivo original\nLinha 2  ", Cancellation::DEFAULT_MESSAGE] as $reason) {
            $presentation = Cancellation::present(['reason' => $reason, 'source' => 'confirmed_source']);
            self::assertSame($reason, $presentation['reason']);
            self::assertSame($reason, $presentation['message']);
            self::assertSame('confirmed_source', $presentation['source']);
            self::assertFalse($presentation['derived']);
        }
    }

    public function testEveryExportSchemaSeparatesOriginalDefaultAndOrigin(): void
    {
        $export = new OrderStreamingExport();
        $orderColumns = new \ReflectionMethod($export, 'orderColumns');
        $schemas = array_map(fn($context) => $orderColumns->invoke($export, $context), ['orders', 'sector', 'equipment']);
        $schemas[] = (new \ReflectionMethod($export, 'entryColumns'))->invoke($export);
        foreach ($schemas as $columns) {
            foreach ([[], ['reason' => 'Motivo original']] as $cancellation) {
                $row = ['TJ_SITUACA' => 'C', 'cancellation' => $cancellation];
                $before = $row;
                self::assertSame($cancellation['reason'] ?? '', $columns['cancellation_reason'][3]($row));
                self::assertSame($cancellation['reason'] ?? Cancellation::DEFAULT_MESSAGE, $columns['cancellation_display'][3]($row));
                self::assertSame(isset($cancellation['reason']) ? 'Motivo original registrado no TOTVS' : Cancellation::DEFAULT_SOURCE, $columns['cancellation_source'][3]($row));
                self::assertSame($before, $row);
            }
            foreach (['L', 'P'] as $situation) {
                $row = ['TJ_SITUACA' => $situation, 'cancellation' => ['reason' => 'Não exibir']];
                foreach (['cancellation_reason', 'cancellation_display', 'cancellation_source'] as $field) self::assertSame('', $columns[$field][3]($row));
            }
        }
    }
}
