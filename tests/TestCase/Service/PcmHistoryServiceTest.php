<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\PcmHistoryService;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\TestCase;

final class PcmHistoryServiceTest extends TestCase
{
    use PcmSnapshotFixture;
    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::connection()->begin();
        self::$ids = self::seedHistoricalComparison();
    }

    public static function tearDownAfterClass(): void
    {
        self::connection()->rollback();
        parent::tearDownAfterClass();
    }

    public function testSeriesUsesReportDateOrderAndOneSnapshotPerDate(): void
    {
        $history = (new PcmHistoryService())->history(null, ['mode' => '30', 'from' => null, 'to' => null]);
        $this->assertSame(['2026-08-20', '2026-08-21', '2026-08-22'], array_column($history['series'], 'date'));
        $this->assertSame([1, 529, 528], array_column($history['series'], 'total'));
    }

    public function testComparisonFindsTransitionsNewAndAbsentOrders(): void
    {
        $comparison = (new PcmHistoryService())->history(null, ['mode' => '30', 'from' => null, 'to' => null])['comparison'];
        $this->assertSame(-1, $comparison['delta']['total']);
        $this->assertSame(1, $comparison['movement']['new']);
        $this->assertSame(1, $comparison['movement']['absent']);
        $this->assertSame(1, $comparison['movement']['toCompleted']);
        $this->assertSame(1, $comparison['movement']['toOpen']);
        $this->assertSame(1, $comparison['movement']['toCancelled']);
        $this->assertContains(['from' => 'FECHADA', 'to' => 'EM ABERTO', 'quantity' => 1], $comparison['movement']['transitions']);
    }

    public function testSectorHistoryAndCustomPeriodAreApplied(): void
    {
        $service = new PcmHistoryService();
        $period = $service->period(['history_period' => 'custom', 'history_from' => '2026-08-21', 'history_to' => '2026-08-22']);
        $history = $service->history(self::$ids['mechanicalId'], $period);
        $this->assertSame(['2026-08-21', '2026-08-22'], array_column($history['series'], 'date'));
        $this->assertSame([375, 375], array_column($history['series'], 'total'));
    }

    public function testSectorComparisonSupportsWhitelistedSorting(): void
    {
        $areas = (new PcmHistoryService())->sectorComparison('total', 'desc');
        $this->assertSame('MECANI', $areas[0]['area']);
        $this->assertSame(375, $areas[0]['total']);
    }

    public function testMovementQueriesReturnTheInvolvedOrderIdentities(): void
    {
        $service = new PcmHistoryService();
        $this->assertSame(1, $service->movementQuery('new')?->count());
        $this->assertSame(1, $service->movementQuery('absent')?->count());
        $this->assertSame(1, $service->movementQuery('completed')?->count());
        $this->assertSame(1, $service->movementQuery('open')?->count());
        $this->assertSame(1, $service->movementQuery('cancelled')?->count());
        $this->assertNull($service->movementQuery('invalid'));
    }
}
