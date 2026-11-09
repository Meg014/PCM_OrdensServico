<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusSectorQueries;
use App\Service\Protheus\RouteChecklist;
use PHPUnit\Framework\TestCase;

final class RouteChecklistTest extends TestCase
{
    public function testActualAssetPredicateRejectsUnrelatedAssetsTypesNamesAndSectors(): void
    {
        $db = new \PDO('sqlite::memory:');
        $statement = $db->prepare('SELECT CASE WHEN ' . RouteChecklist::predicate() . ' THEN 1 ELSE 0 END'
            . ' FROM (SELECT :code AS TJ_CODBEM, :area AS TJ_CODAREA)');
        foreach ([['PREVELE FAB 01', 'ELETRI', 1], ['PREVLUB FAB 01', 'MECANI', 1],
            ['PREVMEC FAB 03', 'MECANI', 1], ['PREVELE FAB 01', 'INSTRU', 1],
            ['PREVLUB FAB 01', 'ELETRI', 0], ['PREVMEC FAB 01', 'ELETRI', 0],
            ['PREVELE FAB 01', 'MECANI', 0], ['PREVELE FAB 01', 'USINAG', 0],
            ['PREVELE FABRICA 01', 'ELETRI', 0], ['PREVELE USI 01', 'ELETRI', 0],
            ['BEM01', 'ELETRI', 0], ['', 'ELETRI', 0], [' PREVMEC FAB 02  ', 'MECANI', 1]] as [$code, $area, $expected]) {
            $statement->execute(['code' => $code, 'area' => $area]);
            self::assertSame($expected, (int)$statement->fetchColumn(), "$code/$area");
        }
    }

    public function testActualAgeCaseHasExclusiveBoundariesAndRetainsInvalidDateAudit(): void
    {
        $db = new \PDO('sqlite::memory:');
        $statement = $db->prepare('SELECT ' . RouteChecklist::ageCase()
            . ' FROM (SELECT CAST(:age AS INTEGER) age_days, :code AS TJ_CODBEM)');
        foreach (['PREVELE FAB 01', 'PREVLUB FAB 01', 'PREVMEC FAB 01'] as $code) {
            foreach ([null, -1, 0, 7, 8, 14, 15, 17, 18, 20, 21, 100] as $age) {
                $green = str_starts_with($code, 'PREVELE') ? 17 : 7;
                $yellow = str_starts_with($code, 'PREVELE') ? 20 : 14;
                $expected = $age === null ? 'route_unknown' : ($age < 0 ? 'route_future'
                    : ($age <= $green ? 'route_on_time' : ($age <= $yellow ? 'route_attention' : 'route_critical')));
                $statement->execute(['age' => $age, 'code' => $code]);
                self::assertSame($expected, $statement->fetchColumn(), "$code/$age");
            }
        }
    }

    public function testRealAggregationAndDrilldownPartitionTheGeneralPopulationWithoutDuplication(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE bucketed (TJ_CODBEM TEXT, TJ_CODAREA TEXT, age_days INTEGER,
            age_bucket TEXT, identity_count INTEGER, equipment_matches INTEGER, service_matches INTEGER)');
        $insert = $db->prepare('INSERT INTO bucketed VALUES (?, ?, ?, ?, 1, 1, 1)');
        foreach ([['PREVELE FAB 01', 'ELETRI', 15, '8_15'], ['PREVLUB FAB 01', 'MECANI', 8, '8_15'],
            ['PREVMEC FAB 01', 'MECANI', 14, '8_15'], ['PREVMEC FAB 02', 'MECANI', 15, '8_15'],
            ['PREVELE FAB 02', 'ELETRI', 18, '16_30'], ['PREVELE FAB 03', 'ELETRI', 20, '16_30'],
            ['PREVELE FAB 04', 'ELETRI', 21, '16_30'],
            ['BEM01', 'ELETRI', 15, '8_15'], ['BEM02', 'USINAG', 7, '0_7'],
            ['PREVELE USI 01', 'ELETRI', 61, 'over_60']] as $row) $insert->execute($row);
        $sql = ProtheusSectorQueries::backlog(false);
        $tail = 'WITH ' . substr($sql, strpos($sql, ', classified AS') + 2);
        $tail = str_replace(['COUNT_BIG(*)', 'OPTION (RECOMPILE)'], ['COUNT(*)', ''], $tail);
        $rows = $db->query($tail)->fetchAll(\PDO::FETCH_ASSOC);
        $totals = [];
        foreach ($rows as $row) $totals[$row['dimension']][$row['age_bucket'] ?? 'total'] = (int)$row['quantity'];
        self::assertSame(3, $totals['total']['total']);
        self::assertSame(7, $totals['checklist_total']['total']);
        self::assertSame(10, $totals['total']['total'] + $totals['checklist_total']['total']);
        self::assertSame(['route_on_time' => 1, 'route_attention' => 4, 'route_critical' => 2],
            array_replace(array_fill_keys(['route_on_time', 'route_attention', 'route_critical'], 0), $totals['checklist_age']));
        // Execute the exact page filter against the same classified CTE, without SQL Server pagination.
        $page = ProtheusSectorQueries::page(true, false);
        preg_match('/ AND \(CASE WHEN is_checklist = 1.*?:backlog_bucket\)/s', $page, $filter);
        $cte = substr($tail, 0, strpos($tail, '\n, grouped AS') ?: strpos($tail, ', grouped AS'));
        $list = $db->prepare($cte . ' SELECT TJ_CODBEM FROM classified WHERE 1=1 ' . $filter[0]);
        foreach (['all' => 3, '0_7' => 1, '8_15' => 1, 'route_all' => 7, 'route_on_time' => 1,
            'route_attention' => 4, 'route_critical' => 2] as $age => $expected) {
            $list->execute(['backlog_age' => $age, 'backlog_bucket' => $age]);
            self::assertCount($expected, $list->fetchAll(), $age);
        }
        $db->exec('DELETE FROM bucketed'); // Offline fixture only, never the TOTVS connection.
        $empty = $db->query($tail)->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $empty);
        self::assertSame([0, 0], array_map(static fn ($row) => (int)$row['quantity'], $empty));
    }

    public function testFactoryScopeIsLocalToBacklogAndAllPageVariantsStayReadOnly(): void
    {
        $sql = ProtheusSectorQueries::backlog();
        self::assertStringContainsString("AND f.unit IN ('', 'factory')", $sql);
        self::assertStringNotContainsString('PREVELE FAB', ProtheusSectorQueries::aggregates());
        self::assertStringNotContainsString('PREVELE FAB', ProtheusSectorQueries::page(false));
        foreach ([ProtheusSectorQueries::backlog(false), $sql, ProtheusSectorQueries::page(true),
            ProtheusSectorQueries::page(true, false), ProtheusSectorQueries::entriesPage(true)] as $query) {
            self::assertTrue(ProtheusQueries::allows($query));
            self::assertFalse(ProtheusQueries::allows($query . '; UPDATE dbo.STJ010 SET TJ_TERMINO=1'));
            self::assertStringContainsString('TJ_CODBEM', $query);
            self::assertStringContainsString("TJ_SITUACA = 'L' AND TJ_TERMINO = 'N'", $query);
        }
    }

    public function testCardRangesAndSelectionDescribeTheSectorAndGeneralRules(): void
    {
        self::assertSame(['route_on_time' => '0 a 7 dias', 'route_attention' => '8 a 14 dias',
            'route_critical' => 'Mais de 14 dias'], RouteChecklist::ranges('MECANI'));
        self::assertSame(['route_on_time' => '0 a 17 dias', 'route_attention' => '18 a 20 dias',
            'route_critical' => 'Mais de 20 dias'], RouteChecklist::ranges('ELETRI'));
        self::assertSame(RouteChecklist::ranges('ELETRI'), RouteChecklist::ranges('INSTRU'));
        self::assertSame('Mecânica: 8 a 14 dias · Elétrica: 18 a 20 dias', RouteChecklist::ranges('')['route_attention']);
        $checklists = ['area' => 'ELETRI', 'total' => 9, 'ages' => ['route_on_time' => 6, 'route_attention' => 2, 'route_critical' => 1]];
        $selection = RouteChecklist::selection('route_attention', [], $checklists);
        self::assertSame('Checklists das rotas · ATENÇÃO · 18 a 20 dias', $selection['label']);
        self::assertSame(2, $selection['total']);
        self::assertSame(9, RouteChecklist::selection('route_all', [], $checklists)['total']);
    }

    public function testRouteFactoryOverrideRespectsSelectedUnitAndLeavesNormalAssetUnitUnchanged(): void
    {
        $db = new \PDO('sqlite::memory:');
        // Replace only the already-tested official unit catalog lookup with a fixture lookup.
        $predicate = str_replace(\App\Service\Protheus\ProtheusUnit::predicate('n.TJ_CCUSTO', 'f.unit'),
            "(f.unit = '' OR f.unit = n.mapped_unit)", RouteChecklist::unitPredicate());
        $statement = $db->prepare('SELECT CASE WHEN ' . $predicate . ' THEN 1 ELSE 0 END'
            . " FROM (SELECT :code AS TJ_CODBEM, 'MECANI' AS TJ_CODAREA, 'admin' AS mapped_unit) n"
            . ' CROSS JOIN (SELECT :unit AS unit) f');
        foreach ([['PREVLUB FAB 01', '', 1], ['PREVMEC FAB 01', 'factory', 1],
            ['PREVLUB FAB 01', 'admin', 0], ['PREVMEC FAB 01', 'mill', 0],
            ['PREVMEC FAB 01', 'other', 0], ['BEM01', 'admin', 1], ['BEM01', 'factory', 0]] as [$code, $unit, $expected]) {
            $statement->execute(['code' => $code, 'unit' => $unit]);
            self::assertSame($expected, (int)$statement->fetchColumn());
        }
    }
}
