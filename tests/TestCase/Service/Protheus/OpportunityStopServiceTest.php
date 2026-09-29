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
            ['area' => 'MECANI', 'cost_center' => '3101005', 'card' => 'preventive', 'status' => 'FECHADA'],
            true,
            2,
        );

        self::assertSame('MECANI', $received['area']);
        self::assertSame('opportunity', $received['filters']['card']);
        self::assertSame('EM ABERTO', $received['filters']['card_status']);
        self::assertSame('EM ABERTO', $received['filters']['status']);
        self::assertSame('3101005', $received['filters']['cost_center']);
        self::assertTrue($received['export']);
        self::assertSame(2, $received['page']);
        self::assertSame(14, $result['total']);
        self::assertSame('MECANI', $result['orders'][0]['TJ_CODAREA']);
        self::assertSame('MECÂNICA', $result['area_name']);
        self::assertSame(
            ['MECANI' => 'MECÂNICA', 'ELETRI' => 'ELÉTRICA'],
            (new OpportunityStopService())->workshops(),
        );
    }

    public function testRejectsUnsafeAreaBeforeLoading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OpportunityStopService(loader: static fn(): array => []))->load(['area' => "MECANI' OR 1=1"]);
    }

    public function testCostCenterOptionsSqlIsFixedBoundAndAllowlisted(): void
    {
        foreach ([false, true] as $withWorkshop) {
            $sql = ProtheusSectorQueries::opportunityCostCenters($withWorkshop);
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertStringContainsString('TJ_CCUSTO', $sql);
            self::assertStringContainsString(':service1', $sql);
            self::assertStringContainsString(':service2', $sql);
            self::assertSame($withWorkshop, str_contains($sql, ':area'));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
        }
    }
}
