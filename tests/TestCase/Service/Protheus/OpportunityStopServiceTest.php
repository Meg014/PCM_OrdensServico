<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\OpportunityStopService;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusSectorQueries;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpportunityStopServiceTest extends TestCase
{
    public function testUsesTheExactCardRuleAndItsOpenCount(): void
    {
        $received = [];
        $loader = static function (string $area, array $filters, bool $export, ?int $page) use (&$received): array {
            $received = compact('area', 'filters', 'export', 'page');

            return [
                'available' => true,
                'orders' => [['TJ_CODAREA' => 'MECANI']],
                'breakdown' => ['opportunity' => ['open' => 14, 'closed' => 3]],
            ];
        };
        $result = (new OpportunityStopService(loader: $loader))->load(
            ['area' => 'MECANI', 'unit' => 'factory', 'cost_center' => '3101005', 'card' => 'preventive', 'status' => 'FECHADA'],
            true,
            2,
        );

        self::assertSame('MECANI', $received['area']);
        self::assertSame('opportunity', $received['filters']['card']);
        self::assertSame('EM ABERTO', $received['filters']['card_status']);
        self::assertSame('EM ABERTO', $received['filters']['status']);
        self::assertSame('3101005', $received['filters']['cost_center']);
        self::assertSame('factory', $received['filters']['opportunity_unit']);
        self::assertTrue($received['export']);
        self::assertSame(2, $received['page']);
        self::assertSame(14, $result['total']);
        self::assertSame('MECANI', $result['orders'][0]['TJ_CODAREA']);
        self::assertSame('MECÂNICA', $result['area_name']);
        self::assertSame('FÁBRICA', $result['unit_name']);
        self::assertSame(
            ['MECANI' => 'MECÂNICA', 'ELETRI' => 'ELÉTRICA'],
            (new OpportunityStopService())->workshops(),
        );
    }

    public function testClassifiesAndFormatsCostCentersWithoutMixingWorkshop(): void
    {
        $service = new OpportunityStopService();
        $centers = ['3101005' => '3101005 — EXTRAÇÃO', '4101005' => '4101005 — CALDEIRAS', '9901' => '9901 — APOIO'];

        self::assertSame('factory', OpportunityStopService::unitForCostCenter('3101005'));
        self::assertSame('mill', OpportunityStopService::unitForCostCenter('4101005'));
        self::assertSame('other', OpportunityStopService::unitForCostCenter('9901'));
        self::assertSame(['factory' => 'FÁBRICA', 'mill' => 'USINA', 'other' => 'OUTROS'], $service->units($centers));
        self::assertSame(['3101005' => '3101005 — EXTRAÇÃO'], $service->costCentersForUnit($centers, 'factory'));
        self::assertSame('3101005 — EXTRAÇÃO', OpportunityStopService::costCenterLabel([
            'TJ_CCUSTO' => '3101005', 'cost_center_name' => 'EXTRAÇÃO',
        ]));
    }

    public function testRejectsUnsafeAreaBeforeLoading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OpportunityStopService(loader: static fn(): array => []))->load(['area' => "MECANI' OR 1=1"]);
    }

    public function testRejectsUnknownUnitBeforeLoading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OpportunityStopService(loader: static fn(): array => []))->load(['unit' => 'workshop-name']);
    }

    public function testCostCenterOptionsSqlIsFixedBoundAndAllowlisted(): void
    {
        foreach ([false, true] as $withWorkshop) {
            $sql = ProtheusSectorQueries::opportunityCostCenters($withWorkshop);
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertStringContainsString('TJ_CCUSTO', $sql);
            self::assertStringContainsString('dbo.CTT010', $sql);
            self::assertStringContainsString('c.CTT_FILIAL = j.TJ_FILIAL', $sql);
            self::assertStringContainsString('c.CTT_CUSTO = j.TJ_CCUSTO', $sql);
            self::assertStringContainsString("c.D_E_L_E_T_ <> '*'", $sql);
            self::assertStringContainsString('ORDER BY name, code', $sql);
            self::assertStringContainsString(':service1', $sql);
            self::assertStringContainsString(':service2', $sql);
            self::assertSame($withWorkshop, str_contains($sql, ':area'));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
        }
    }

    public function testOpportunityPageResolvesOneCostCenterNameWithCodeFallback(): void
    {
        $sql = ProtheusSectorQueries::page(false, true, true);

        self::assertTrue(ProtheusQueries::allows($sql));
        self::assertStringContainsString('OUTER APPLY', $sql);
        self::assertStringContainsString('MAX(NULLIF(LTRIM(RTRIM(c.CTT_DESC01))', $sql);
        self::assertStringContainsString('c.CTT_FILIAL = filtered.TJ_FILIAL', $sql);
        self::assertStringContainsString('c.CTT_CUSTO = filtered.TJ_CCUSTO', $sql);
        self::assertStringContainsString("c.D_E_L_E_T_ <> '*'", $sql);
        self::assertStringContainsString('AS cost_center_name', $sql);
        self::assertStringContainsString('f.cost_center = \'\' OR n.TJ_CCUSTO = f.cost_center', $sql);
        self::assertStringContainsString("f.unit = 'factory'", $sql);
        self::assertStringContainsString("LIKE '31%'", $sql);
        self::assertStringContainsString("f.unit = 'mill'", $sql);
        self::assertStringContainsString("LIKE '41%'", $sql);
        self::assertStringContainsString("f.unit = 'other'", $sql);
    }
}
