<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\SectorDashboardService;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\TestCase;

final class SectorDashboardServiceTest extends TestCase
{
    use PcmSnapshotFixture;
    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::connection()->begin();
        self::$ids = self::seedValidatedSnapshot();
    }

    public static function tearDownAfterClass(): void
    {
        self::connection()->rollback();
        parent::tearDownAfterClass();
    }

    public function testAggregatesStatusAndRankingsForSector(): void
    {
        $result = (new SectorDashboardService())->dashboard(self::$ids['mechanicalId'], []);
        $this->assertSame(375, $result['indicators']['total']);
        $this->assertSame('EQ-M1', $result['equipment'][0]['key']);
        $this->assertSame(250, $result['equipment'][0]['quantity']);
        $this->assertSame('CORMEC', $result['services'][0]['key']);
        $this->assertSame(220, $result['services'][0]['quantity']);
        $this->assertSame(188, $result['costCenters'][0]['quantity']);
    }

    public function testValidatedFiltersAffectEveryAggregateAndDetailQuery(): void
    {
        $service = new SectorDashboardService();
        $filters = $service->filters(['status' => 'FECHADA', 'equipment' => 'EQ-M1', 'sort' => 'raw_payload']);
        $result = $service->dashboard(self::$ids['mechanicalId'], $filters);
        $this->assertSame(250, $result['indicators']['total']);
        $this->assertArrayNotHasKey('sort', $filters);
        $this->assertSame(250, $service->detailQuery(self::$ids['mechanicalId'], $filters)?->count());
    }
}
