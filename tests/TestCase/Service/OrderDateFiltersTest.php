<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Entity\MaintenanceArea;
use App\Service\SectorDashboardService;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\Http\Exception\BadRequestException;
use Cake\TestSuite\TestCase;

class OrderDateFiltersTest extends TestCase
{
    use PcmSnapshotFixture;

    protected function setUp(): void
    {
        parent::setUp();
        self::connection()->begin();
        $ids = self::seedValidatedSnapshot();
        foreach (
            ['4001' => '2026-09-01 00:00:00', '4002' => '2026-09-30 23:59:59',
            '4003' => '2026-10-01 00:00:00', '4004' => null, '4005' => '2025-12-31 12:00:00',
            '4372' => '2025-12-31 12:00:00', '4530' => '2026-09-15 12:00:00'] as $number => $date
        ) {
            self::connection()->update(
                'work_order_snapshots',
                ['maintenance_planned_start' => $date],
                ['report_import_id' => $ids['currentImportId'], 'source_order_number' => $number],
            );
        }
    }

    protected function tearDown(): void
    {
        self::connection()->rollback();
        parent::tearDown();
    }

    private function numbers(array $filters): array
    {
        $service = new SectorDashboardService();

        return $service->detailQuery(null, $service->filters($filters))->all()->extract('source_order_number')->toList();
    }

    public function testNewestCreationFirstRegardlessOfOrderNumber(): void
    {
        $numbers = $this->numbers([]);
        $this->assertSame(['4003', '4002', '4001'], array_slice($numbers, 0, 3));
        $this->assertSame('4004', end($numbers));
        $this->assertSame($numbers, $this->numbers([]));
    }

    public function testStartDate(): void
    {
        $this->assertSame(['4003', '4002', '4001'], $this->numbers(['date_start' => '2026-09-01']));
    }

    public function testEndDateIncludesWholeDay(): void
    {
        $numbers = $this->numbers(['date_end' => '2026-09-30']);
        $this->assertContains('4002', $numbers);
        $this->assertNotContains('4003', $numbers);
        $this->assertNotContains('4004', $numbers);
    }

    public function testIntervalAndCombinedFilters(): void
    {
        $dates = ['date_start' => '2026-09-01', 'date_end' => '2026-09-30'];
        $this->assertSame(['4002', '4001'], $this->numbers($dates));
        $this->assertSame(['4001'], $this->numbers($dates + ['q' => '4001', 'status' => 'FECHADA', 'area' => 'MECANI', 'equipment' => 'EQ-M1']));
        $this->assertSame([], $this->numbers($dates + ['status' => 'EM ABERTO']));
    }

    public function testExistingOperationalRulesAndIndicatorsUnchanged(): void
    {
        $numbers = $this->numbers([]);
        $this->assertContains('4005', $numbers); // Closed in 2025 remains eligible.
        $this->assertContains('4004', $numbers); // Closed without date remains eligible.
        $this->assertNotContains('4372', $numbers); // Open in 2025 remains excluded.
        $this->assertNotContains('4530', $numbers); // Cancelled remains excluded.
        $service = new SectorDashboardService();
        $this->assertSame(
            $service->dashboard(null, [])['indicators'],
            $service->dashboard(null, ['date_start' => '2026-09-01', 'date_end' => '2026-09-30'])['indicators'],
        );
    }

    public function testInvalidDateRejected(): void
    {
        $this->expectException(BadRequestException::class);
        (new SectorDashboardService())->filters(['date_start' => '2026-02-30']);
    }

    public function testReversedIntervalRejected(): void
    {
        $this->expectException(BadRequestException::class);
        (new SectorDashboardService())->filters(['date_start' => '2026-10-01', 'date_end' => '2026-09-01']);
    }

    public function testFriendlyNamesPreserveCodesAndCustomNames(): void
    {
        foreach (
            ['MECANI' => 'Mecânica', 'ELETRI' => 'Elétrica', 'CALDEI' => 'Caldeiraria',
            'INSTRU' => 'Instrumentação', 'OPERAC' => 'Operação', 'TERCEI' => 'Terceiros', 'USINAG' => 'Usinagem'] as $code => $name
        ) {
            $area = new MaintenanceArea(['source_code' => $code, 'display_name' => $code]);
            $this->assertSame($name, $area->display_name);
            $this->assertSame($code, $area->source_code);
        }
        $area = new MaintenanceArea(['source_code' => 'MECANI', 'display_name' => 'Mecânica Industrial']);
        $this->assertSame('Mecânica Industrial', $area->display_name);
        $options = (new SectorDashboardService())->dashboard(null, [])['options']['area'];
        $this->assertSame('Mecânica', array_column($options, 'label', 'value')['MECANI']);
    }
}
