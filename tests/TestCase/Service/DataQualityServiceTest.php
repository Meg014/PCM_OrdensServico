<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DataQualityService;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\TestCase;

final class DataQualityServiceTest extends TestCase
{
    use PcmSnapshotFixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::connection()->begin();
    }

    public static function tearDownAfterClass(): void
    {
        static::connection()->rollback();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::seedValidatedSnapshot();
    }

    public function testDetectsMissingArea(): void
    {
        $this->updateOrder('4001', ['maintenance_area_code' => null, 'maintenance_area_id' => null]);
        $this->assertSame(1, $this->countFor('missing_area'));
    }

    public function testDetectsMissingServiceName(): void
    {
        $this->updateOrder('4001', ['service_name' => null]);
        $this->assertSame(1, $this->countFor('missing_service'));
    }

    public function testDetectsMissingCostCenter(): void
    {
        $this->updateOrder('4001', ['cost_center_code' => null, 'cost_center_id' => null]);
        $this->assertSame(1, $this->countFor('missing_cost_center'));
    }

    public function testDetectsCompletedWithoutActualStart(): void
    {
        $this->updateOrder('4001', ['maintenance_actual_start' => null]);
        $this->assertSame(1, $this->countFor('completed_without_start'));
    }

    public function testDetectsActualStartBeforeOriginDate(): void
    {
        $this->updateOrder('4001', ['origin_date' => '2026-08-22']);
        $this->assertSame(1, $this->countFor('start_before_origin'));
    }

    public function testDetectsPossibleDateInconsistency(): void
    {
        $this->updateOrder('4372', ['maintenance_actual_start' => null, 'maintenance_actual_end' => '2026-08-21 10:00:00']);
        $this->assertSame(1, $this->countFor('possible_date_inconsistency'));
    }

    public function testSnapshotWithoutInconsistenciesReturnsZeros(): void
    {
        $summary = (new DataQualityService())->summary();
        $this->assertSame(575, $summary['total']);
        foreach ($summary['indicators'] as $indicator) {
            $this->assertSame(0, $indicator['count']);
            $this->assertSame(0.0, $indicator['percentage']);
        }
    }

    public function testEmptySnapshotReturnsZeros(): void
    {
        static::clearPcmData();
        $service = new DataQualityService();
        $summary = $service->summary();
        $this->assertNull($summary['reportDate']);
        $this->assertSame(0, $summary['total']);
        $this->assertNull($service->detailQuery('missing_area'));
        foreach ($summary['indicators'] as $indicator) {
            $this->assertSame(0, $indicator['count']);
        }
    }

    private function countFor(string $type): int
    {
        return (new DataQualityService())->summary()['indicators'][$type]['count'];
    }

    private function updateOrder(string $number, array $fields): void
    {
        static::connection()->update('work_order_snapshots', $fields, [
            'source_order_number' => $number,
            'report_date' => '2026-08-21',
        ]);
    }
}
