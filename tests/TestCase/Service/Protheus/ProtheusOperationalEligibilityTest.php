<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\ProtheusOperationalEligibility as Rule;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusSectorQueries;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProtheusOperationalEligibilityTest extends TestCase
{
    public function testStatusPredicatesExcludePendingAndCancelledForBothEndings(): void
    {
        // Execute the actual shared status predicates in memory. No application DB/bootstrap.
        $db = new PDO('sqlite::memory:');
        $sql = "WITH samples(TJ_SITUACA, TJ_TERMINO, quantity) AS (VALUES
            ('L','N',312), ('P','N',2), ('C','N',809), ('L','S',4254),
            ('P','S',3), ('C','S',4), ('L','',5), (NULL,'N',6))
            SELECT SUM(CASE WHEN " . Rule::OPEN . " THEN quantity ELSE 0 END) AS opened,
                SUM(CASE WHEN " . Rule::CLOSED . " THEN quantity ELSE 0 END) AS closed FROM samples";
        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(312, (int)$row['opened']);
        self::assertSame(4254, (int)$row['closed']);
    }

    public function testAllOperationalQueriesUseSharedRuleAndKeepPlannedCutoff(): void
    {
        foreach ([ProtheusQueries::MANAGEMENT, ProtheusSectorQueries::aggregates(), ProtheusSectorQueries::page()] as $sql) {
            self::assertStringContainsString(Rule::ELIGIBLE_OPEN, $sql);
            self::assertStringContainsString(Rule::CLOSED, $sql);
            self::assertSame(1, substr_count($sql, ':cutoff'));
            self::assertStringNotContainsString("TJ_SITUACA <> 'C'", $sql);
            self::assertStringNotContainsString("TJ_SITUACA IN ('L', 'P')", $sql);
            self::assertTrue(ProtheusQueries::allows($sql));
        }
        self::assertStringContainsString("TRY_CONVERT(date, NULLIF(TJ_DTMPINI, ''), 112) >= CONVERT(date, :cutoff, 112)", Rule::ELIGIBLE_OPEN);
        self::assertStringNotContainsString('cutoff', Rule::CLOSED);
    }
}
