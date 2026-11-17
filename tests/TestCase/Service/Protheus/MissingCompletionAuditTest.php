<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\MissingCompletionAuditQueries;
use App\Service\Protheus\ProtheusQueries;
use PHPUnit\Framework\TestCase;

final class MissingCompletionAuditTest extends TestCase
{
    public function testOnlyFixedSelectQueriesAreAllowed(): void
    {
        foreach ([MissingCompletionAuditQueries::ORDERS, MissingCompletionAuditQueries::COLUMNS,
            MissingCompletionAuditQueries::COMPARISON] as $sql) {
            self::assertTrue(ProtheusQueries::allows($sql));
            foreach (['; UPDATE STJ010 SET TJ_TERMINO=1', '; DELETE FROM STJ010', '; EXEC sp_help'] as $suffix) {
                self::assertFalse(ProtheusQueries::allows($sql . $suffix));
            }
        }
    }

    public function testAuditKeepsClosedStatusAndAggregatesEntriesWithoutMultiplyingOrders(): void
    {
        $sql = MissingCompletionAuditQueries::ORDERS;
        self::assertStringContainsString("j.TJ_SITUACA = 'L' AND j.TJ_TERMINO = 'S'", $sql);
        self::assertStringContainsString("TRY_CONVERT(date, NULLIF(j.TJ_DTPRFIM, ''), 112) IS NULL", $sql);
        self::assertStringContainsString('DATALENGTH(j.TJ_DTPRFIM)', $sql);
        self::assertStringContainsString('AS completion_hex', $sql);
        self::assertStringContainsString('COUNT_BIG(*) AS entry_count', $sql);
        self::assertStringContainsString('l.TL_FILIAL = j.TJ_FILIAL AND l.TL_ORDEM = j.TJ_ORDEM', $sql);
        self::assertStringNotContainsString('JOIN dbo.STL010', $sql);
        self::assertStringNotContainsString('JOIN dbo.STQ010', $sql);
        self::assertStringNotContainsString('COALESCE(j.TJ_DTPRFIM', $sql);
        foreach (['TJ_ORDEM', 'TJ_FILIAL', 'TJ_CODBEM', 'TJ_SERVICO', 'TJ_CODAREA',
            'TJ_SITUACA', 'TJ_TERMINO', 'TJ_DTPRFIM'] as $field) {
            self::assertStringContainsString('j.' . $field, $sql);
        }
    }
}
