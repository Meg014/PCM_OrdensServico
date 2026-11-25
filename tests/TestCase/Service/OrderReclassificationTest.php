<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\OrderReclassificationService as Service;
use App\Service\Protheus\ActionPlanQuery;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusSectorQueries;
use App\Service\Protheus\ReclassificationQueries;
use PHPUnit\Framework\TestCase;

final class OrderReclassificationTest extends TestCase
{
    public function testExcelInputAndOfficialDestinations(): void
    {
        self::assertSame(['00012', '12', '003', '004', '005'], Service::numbers("00012\r\n12\t003,004;005 00012"));
        self::assertCount(12, Service::areas());
        self::assertSame('Moenda', Service::areas()['MOEND']);
        self::assertArrayNotHasKey('INSTRU', Service::areas());
        $this->expectException(\DomainException::class);
        Service::numbers('00012,1E+6');
    }

    public function testEffectiveAreaUsesOnlyExactReadOnlyTemplates(): void
    {
        foreach ([ProtheusQueries::ORDER_BRANCH, ProtheusQueries::orders(true, true, false, true),
            ProtheusQueries::dashboardAnalysis(), ProtheusQueries::management(true),
            ProtheusSectorQueries::backlog(), ProtheusSectorQueries::entriesPage(), ActionPlanQuery::sql()] as $sql) {
            $effective = ReclassificationQueries::apply($sql);
            self::assertSame($sql === ProtheusQueries::dashboardAnalysis(), str_contains($effective, 'LEFT HASH JOIN OPENJSON'));
            self::assertSame($sql, ReclassificationQueries::original($effective));
            self::assertTrue(ProtheusQueries::allows($effective));
            self::assertFalse(ProtheusQueries::allows($effective . '; DELETE FROM order_reclassifications'));
            self::assertFalse(ProtheusQueries::allows(str_replace('COALESCE(m.destination, o.TJ_CODAREA)', 'm.destination', $effective)));
        }
        self::assertFalse(ReclassificationQueries::applicable(ReclassificationQueries::PREVIEW));
    }
}
