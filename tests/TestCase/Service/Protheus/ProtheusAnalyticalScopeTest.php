<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\ProtheusAnalyticalScope;
use App\Service\Protheus\ProtheusGenericEquipment;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusSectorQueries;
use Cake\TestSuite\TestCase;

final class ProtheusAnalyticalScopeTest extends TestCase
{
    public function testAnalyticalQueriesShareCutoffButOrdinaryOrdersRemainUnbounded(): void
    {
        self::assertSame('2025-01-01', ProtheusAnalyticalScope::START_DATE);
        foreach ([ProtheusQueries::dashboardAnalysis(), ProtheusSectorQueries::aggregates(),
            ProtheusSectorQueries::equipmentRanking(), ProtheusSectorQueries::historicalRankings(),
            ProtheusSectorQueries::backlog()] as $sql) {
            self::assertStringContainsString("CONVERT(date, '20250101', 112)", $sql);
        }
        self::assertStringNotContainsString("CONVERT(date, '20250101', 112)", ProtheusQueries::orders(false, false, false));
        self::assertStringNotContainsString("CONVERT(date, '20250101', 112)", ProtheusQueries::management());
    }

    public function testGenericEquipmentIsExplicitByBranchAndCode(): void
    {
        self::assertSame([
            ['branch' => '01', 'code' => 'FAB 80 020', 'name' => 'FABRICA'],
            ['branch' => '01', 'code' => 'SET 50 002', 'name' => 'CALDEIRA'],
            ['branch' => '01', 'code' => 'SET 50 001', 'name' => 'PREDIO CALDEIRA'],
            ['branch' => '01', 'code' => 'SET 80 004', 'name' => 'CALDEIRA ALBORG'],
        ], ProtheusGenericEquipment::ITEMS);
        self::assertStringNotContainsString('NOME', ProtheusGenericEquipment::STJ_PREDICATE);
        self::assertStringContainsString("j.TJ_FILIAL = '01'", ProtheusGenericEquipment::STJ_PREDICATE);
    }
}
