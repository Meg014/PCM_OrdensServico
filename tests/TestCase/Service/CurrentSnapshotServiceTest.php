<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\CurrentSnapshotService;
use App\Service\PcmIndicatorService;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\TestCase;

final class CurrentSnapshotServiceTest extends TestCase
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

    public function testLatestImportUsesImportId(): void
    {
        $import = (new CurrentSnapshotService())->currentImport();
        $this->assertNotNull($import);
        $this->assertSame(self::$ids['currentImportId'], (int)$import->id);
        $this->assertSame('2026-08-21', $import->report_date->format('Y-m-d'));
    }

    public function testLaterSuccessfulImportWinsWhenReportDateIsTheSame(): void
    {
        $connection = self::connection();
        $connection->insert('report_imports', [
            'file_name' => 'same-date-reexport.csv',
            'file_path' => 'test/same-date-reexport.csv',
            'report_date' => '2026-08-21',
            'file_hash' => str_repeat('f', 64),
            'file_size' => 1,
            'sheet_name' => 'sclxd280',
            'status' => 'success',
            'started_at' => '2026-08-21 10:00:00',
            'finished_at' => '2026-08-21 10:01:00',
            'created' => '2026-08-21 10:00:00',
            'updated' => '2026-08-21 10:01:00',
        ]);
        $newImportId = (int)$connection->getDriver()->lastInsertId();

        try {
            $service = new CurrentSnapshotService();
            $this->assertGreaterThan(self::$ids['currentImportId'], $newImportId);
            $this->assertSame($newImportId, (int)$service->currentImport()?->id);
            $this->assertSame($newImportId, $service->currentVersion()['import_id']);
        } finally {
            $connection->delete('report_imports', ['id' => $newImportId]);
        }
    }

    public function testCurrentVersionIdentifiesCurrentPortfolio(): void
    {
        $version = (new CurrentSnapshotService())->currentVersion();
        $this->assertNotNull($version);
        $this->assertSame(self::$ids['currentImportId'], $version['import_id']);
        $this->assertSame('2026-08-21', $version['report_date']);
        $this->assertSame('2026-08-21T09:37:00+00:00', $version['updated_at']);
    }

    public function testCurrentPortfolioDoesNotAddOlderReports(): void
    {
        $service = new CurrentSnapshotService();
        $this->assertSame(575, $service->query()?->count());
        $this->assertSame(576, (int)self::connection()->execute(
            'SELECT COUNT(*) FROM work_order_snapshots',
        )->fetchColumn(0));
    }

    public function testLastSuccessfulImportAtUsesFinishedAt(): void
    {
        $updatedAt = (new CurrentSnapshotService())->lastSuccessfulImportAt();
        $this->assertNotNull($updatedAt);
        $this->assertSame('2026-08-21 09:37', $updatedAt->format('Y-m-d H:i'));
    }

    public function testGeneralIndicatorsMatchValidatedReport(): void
    {
        $indicators = (new PcmIndicatorService())->calculate();
        $this->assertSame(529, $indicators['total']);
        $this->assertSame(158, $indicators['open']);
        $this->assertSame(371, $indicators['completed']);
        $this->assertSame(46, $indicators['cancelled']);
        $this->assertSame(40, $indicators['preventive']);
        $this->assertSame(40, $indicators['corrective']);
        $this->assertSame(39, $indicators['improvement']);
        $this->assertSame(39, $indicators['blank_maintenance_type']);
        $this->assertEqualsWithDelta(70.13, $indicators['efficiency'], 0.01);
    }

    public function testAreaFilterReusesIndicators(): void
    {
        $indicators = (new PcmIndicatorService())->calculate(self::$ids['mechanicalId']);
        $this->assertSame(375, $indicators['total']);
        $this->assertSame(371, $indicators['completed']);
        $this->assertSame(4, $indicators['open']);
        $this->assertSame(0, $indicators['cancelled']);
        $this->assertSame(1, $indicators['preventive']);
        $this->assertSame(1, $indicators['corrective']);
        $this->assertSame(1, $indicators['improvement']);
        $this->assertSame(1, $indicators['blank_maintenance_type']);
    }

    public function testUnknownAreaReturnsNull(): void
    {
        $this->assertNull((new CurrentSnapshotService())->area('INVALIDA'));
    }

    public function testZeroDenominatorReturnsZero(): void
    {
        $indicators = (new PcmIndicatorService())->fromStatusCounts(['CANCELADA' => 5]);
        $this->assertSame(0.0, $indicators['efficiency']);
    }

    public function testMaintenanceTypesUseOnlyExactImportedCodes(): void
    {
        $indicators = (new PcmIndicatorService())->fromMaintenanceTypeCounts([
            'PRE' => 7, 'COR' => 5, 'MEL' => 3, '' => 2, 'OUTRO' => 99,
        ]);

        $this->assertSame([
            'preventive' => 7,
            'corrective' => 5,
            'improvement' => 3,
            'blank_maintenance_type' => 2,
        ], $indicators);
    }
}
