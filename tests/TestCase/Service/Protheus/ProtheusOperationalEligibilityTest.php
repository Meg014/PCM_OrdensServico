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
    public function testClosedMeansEndingSUnlessCanceledWhileOpenRuleStaysUnchanged(): void
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
        self::assertSame(4257, (int)$row['closed']);

        $kept = $db->query('WITH samples(TJ_SITUACA, TJ_TERMINO) AS (VALUES '
            . "('L','N'), ('L','S'), ('P','N'), ('C','N'), ('C','S'), (NULL,'N')) "
            . 'SELECT COUNT(*) FROM samples WHERE ' . Rule::NOT_CANCELED)->fetchColumn();
        self::assertSame(4, (int)$kept, 'Only situation C is canceled, independently of TJ_TERMINO.');
    }

    public function testAllOperationalQueriesUseSharedRuleAndKeepPlannedCutoff(): void
    {
        foreach ([ProtheusQueries::MANAGEMENT, ProtheusSectorQueries::aggregates(), ProtheusSectorQueries::page()] as $sql) {
            self::assertStringContainsString($sql === ProtheusQueries::MANAGEMENT ? Rule::ELIGIBLE_OPEN_COUNTS : Rule::ELIGIBLE_OPEN, $sql);
            self::assertStringContainsString(Rule::CLOSED, $sql);
            self::assertSame(1, substr_count($sql, ':cutoff'));
            self::assertStringContainsString(Rule::NOT_CANCELED, $sql);
            self::assertStringNotContainsString("TJ_SITUACA IN ('L', 'P')", $sql);
            self::assertTrue(ProtheusQueries::allows($sql));
        }
        self::assertStringContainsString("TRY_CONVERT(date, NULLIF(TJ_DTMPINI, ''), 112) >= CONVERT(date, :cutoff, 112)", Rule::ELIGIBLE_OPEN);
        self::assertStringNotContainsString('cutoff', Rule::CLOSED);
    }

    public function testOpenOffseasonIgnoresYearAndCodeButNeverIncludesCanceledOrders(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("ATTACH DATABASE ':memory:' AS dbo");
        $db->sqliteCreateCollation('Latin1_General_100_CI_AI', 'strcasecmp');
        $db->exec('CREATE TABLE dbo.ST4010 (T4_FILIAL TEXT, T4_SERVICO TEXT, T4_NOME TEXT, D_E_L_E_T_ TEXT)');
        $db->exec("INSERT INTO dbo.ST4010 VALUES ('01','2425EL','ENTRESSAFRA 24-25 ELETRICA',''),
            ('01','ENTRES','TESTE',''), ('','X','CORRETIVA NAO PROGRAMADA ENTRESSAFRA',''),
            ('01','NORMAL','PREVENTIVA','')");
        // Only date syntax is adapted; the correlated service/name/status rules run unchanged.
        $rule = str_replace([
            "TRY_CONVERT(date, NULLIF(TJ_DTMPINI, ''), 112)", 'CONVERT(date, :cutoff, 112)',
        ], ['TJ_DTMPINI', ':cutoff'], Rule::ELIGIBLE_OPEN);
        $query = $db->prepare("WITH samples(TJ_ORDEM,TJ_FILIAL,TJ_SERVICO,TJ_SITUACA,TJ_TERMINO,TJ_DTMPINI) AS (VALUES
            ('000001','01','2425EL','L','N','20240101'),
            ('000002','01','2425EL','C','N','20240101'),
            ('000003','01','ENTRES','L','N','20240101'),
            ('000004','01','X','L','N',NULL),
            ('000005','01','NORMAL','L','N','20260101'),
            ('000006','01','NORMAL','L','N','20240101'),
            ('000007','01','2425EL','L','S','20240101'))
            SELECT TJ_ORDEM FROM samples WHERE {$rule} ORDER BY TJ_ORDEM");
        $query->execute(['cutoff' => '20260101']);
        self::assertSame(['000001', '000004', '000005'], $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testEveryNonOperationalOrderReadUsesTheCentralCancellationPredicate(): void
    {
        $queries = [ProtheusQueries::AREAS, ProtheusQueries::dashboardAnalysis(), ProtheusQueries::MANAGEMENT,
            ProtheusQueries::orders(false, false, false), ProtheusQueries::orders(true, true, true, true),
            ProtheusQueries::generalEntries(), ProtheusQueries::equipmentHistory(false),
            ProtheusQueries::equipmentPortfolioPage(),
            \App\Service\Protheus\ProtheusEquipmentQueries::summary(),
            ProtheusSectorQueries::equipmentRanking(), ProtheusSectorQueries::historicalRankings()];
        foreach ($queries as $sql) {
            self::assertTrue(
                str_contains($sql, Rule::NOT_CANCELED) || str_contains($sql, Rule::notCanceled('j')),
                "Missing central canceled-order exclusion in SQL:\n{$sql}",
            );
            self::assertTrue(ProtheusQueries::allows($sql));
        }
        foreach ([ProtheusQueries::ORDER, ProtheusQueries::ORDER_IDENTITY, ProtheusQueries::ENTRIES] as $sql) {
            self::assertStringNotContainsString(Rule::NOT_CANCELED, $sql);
            self::assertStringContainsString("j.D_E_L_E_T_ <> '*'", $sql);
            self::assertTrue(ProtheusQueries::allows($sql));
        }
    }

    public function testExplicitCanceledHistoryNeverChangesOperationalQueries(): void
    {
        foreach ([ProtheusQueries::orders(false, false, false, true),
            ProtheusQueries::orders(false, false, false, true, false, false, true),
            ProtheusQueries::generalEntries()] as $sql) {
            $history = ProtheusQueries::canceledHistory($sql);
            self::assertTrue(ProtheusQueries::allows($history));
            self::assertStringContainsString("j.TJ_SITUACA = 'C' /* historical consultation */", $history);
            self::assertFalse(ProtheusQueries::allows($history . '; DELETE FROM STJ010'));
        }
        self::assertStringContainsString(Rule::NOT_CANCELED, ProtheusQueries::MANAGEMENT);
    }
}
