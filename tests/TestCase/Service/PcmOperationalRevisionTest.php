<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\PcmIndicatorService;
use App\Service\PcmPresentationService;
use App\Service\PcmServiceClassifier;
use App\Service\SectorDashboardService;
use App\Service\WorkOrderStatusResolver;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\TestCase;

final class PcmOperationalRevisionTest extends TestCase
{
    use PcmSnapshotFixture;

    private int $areaId;
    private int $currentId;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = self::connection();
        $connection->begin();
        self::clearPcmData();
        $areas = [];
        foreach (['MECANI', 'FUTURO'] as $code) {
            $connection->insert('maintenance_areas', ['source_code' => $code, 'display_name' => $code,
                'slug' => strtolower($code), 'active' => 1, 'created' => '2026-09-10', 'updated' => '2026-09-10']);
            $areas[] = (int)$connection->getDriver()->lastInsertId();
        }
        $this->areaId = $areas[0];
        $old = self::insertImport('2026-09-09', str_repeat('1', 64));
        $this->currentId = self::insertImport('2026-09-10', str_repeat('2', 64));
        $failed = self::insertImport('2026-09-11', str_repeat('3', 64));
        $connection->update('report_imports', ['status' => 'failed'], ['id' => $failed]);
        $rows = [
            ['Liberada', 'Não', 'PRE', 'PREVEN', 'Preventiva'],
            ['Liberada', 'Não', 'COR', 'COREME', 'Nome antigo'],
            ['Liberada', 'Não', 'COR', 'CORPRO', 'Nome antigo'],
            ['Liberada', 'Não', 'MEL', '2627ZZ', 'Revisão de entressafra'],
            ['Liberada', 'Sim', 'COR', 'FUT', 'ENTRESSAFRA'],
            ['Cancelada', 'Sim', 'PRE', 'COREME', 'Emergencial'],
            ['Cancelado', 'Não', 'COR', 'CORPRO', 'Programada'],
            ['Cancelada', 'Sim', 'MEL', 'X', 'ENTRESSAFRA'],
            ['Liberada', 'Não', 'COR', 'LEGADO', 'Manutenção corrétiva emergengial'],
            ['Liberada', 'Não', 'COR', 'NOVO', 'MANUTENÇÃO CORRETIVA PROGRAMADA'],
            ['Liberada', 'Não', 'MEL', 'OUTRO', 'Melhoria'],
            ['Liberada', 'Sim', 'PRE', 'PREVEN', 'Preventiva'],
            ['Liberada', 'Não', 'COR', 'COREME', 'Emergencial'],
        ];
        foreach ($rows as $index => [$situation, $finished, $type, $code, $name]) {
            $this->insertRow(
                $this->currentId,
                $index === 12 ? $areas[1] : $areas[0],
                $index + 1,
                $situation,
                $finished,
                $type,
                $code,
                $name,
            );
        }
        $this->insertRow($old, $areas[0], 90, 'Liberada', 'Não', 'COR', 'COREME', 'Antigo');
        $this->insertRow($failed, $areas[0], 91, 'Liberada', 'Não', 'COR', 'COREME', 'Falhou');
    }

    protected function tearDown(): void
    {
        self::connection()->rollback();
        parent::tearDown();
    }

    public function testOperationalCardsExcludeCancelledClosedAndNonCurrentClassifications(): void
    {
        $result = (new PcmIndicatorService())->calculate();
        foreach (
            ['total' => 10, 'open' => 8, 'completed' => 2, 'cancelled' => 3,
            'preventive' => 1, 'corrective' => 5, 'improvement' => 2,
            'emergency' => 3, 'scheduled' => 2, 'offseason' => 1] as $key => $expected
        ) {
            $this->assertSame($expected, $result[$key], $key);
        }
        $sector = (new PcmIndicatorService())->calculate($this->areaId);
        $this->assertSame(7, $sector['open']);
        $this->assertSame(2, $sector['emergency']);
    }

    public function testSectorSummaryFiltersAndAuditUseTheSameClassification(): void
    {
        $service = new SectorDashboardService();
        $dashboard = $service->dashboard($this->areaId, []);
        $summary = array_column($dashboard['summary'], null, 'key');
        $this->assertSame(7, array_sum(array_column($summary, 'open')));
        $this->assertSame(2, array_sum(array_column($summary, 'completed')));
        $this->assertSame(1, $summary['ENTRESSAFRA']['open']);
        $this->assertSame(1, $summary['ENTRESSAFRA']['completed']);
        $this->assertSame(9, array_sum(array_column($dashboard['status'], 'quantity')));
        $filters = $service->filters(['classification' => 'EMERGENCIAL', 'status' => 'EM ABERTO']);
        $this->assertSame(2, $service->detailQuery($this->areaId, $filters)?->count());
        $this->assertSame(2, $service->dashboard($this->areaId, $filters)['indicators']['emergency']);
        $this->assertSame(3, $service->detailQuery(null, $filters)?->count());
        $this->assertSame(3, $service->detailQuery($this->areaId, ['status' => 'CANCELADA'])?->count());
        $this->assertSame(0, $service->dashboard($this->areaId, ['status' => 'CANCELADA'])['indicators']['total']);
        $this->assertSame(1, $service->detailQuery(null, ['area' => 'FUTURO', 'cost_center' => 'CC-13'])?->count());
        $this->assertSame(1, $service->detailQuery($this->areaId, ['service_name' => 'Revisão de entressafra'])?->count());
    }

    public function testPresentationIncludesFutureAreasAndMatchesTheGeneralCounters(): void
    {
        $payload = (new PcmPresentationService())->payload();
        $this->assertSame($this->currentId, $payload['import_id']);
        $this->assertContains('FUTURO', array_column($payload['screens'], 'key'));
        $general = (new PcmIndicatorService())->calculate();
        foreach (['open', 'completed', 'preventive', 'corrective', 'improvement', 'emergency', 'scheduled', 'offseason'] as $key) {
            $this->assertSame($general[$key], $payload['screens'][0][$key]);
        }
    }

    public function testServiceNormalizationAndStableCodePrecedence(): void
    {
        $classifier = new PcmServiceClassifier();
        $this->assertSame('EMERGENCIAL', $classifier->classify(' coreme ', 'ENTRESSAFRA'));
        $this->assertSame('PROGRAMADA', $classifier->classify('corpro', 'Corretiva Emergencial'));
        $this->assertSame('EMERGENCIAL', $classifier->classify(null, 'manutenção CORRÉTIVA  EMERGENCIAL'));
        $this->assertSame('ENTRESSAFRA', $classifier->classify('9999XX', 'Plano de éntrêssafra 2027'));
        $this->assertSame('OUTROS', $classifier->classify('2425ME', 'Serviço comum'));
        $this->assertSame('OUTROS', $classifier->classify(null, 'Inspeção emergencial'));
        $this->assertSame('OUTROS', $classifier->classify(null, null));
    }

    public function testCorrectiveSubclassesCannotOverrideTheTotvsMaintenanceType(): void
    {
        self::connection()->update('work_order_snapshots', ['maintenance_type' => 'PRE'], [
            'report_import_id' => $this->currentId, 'source_order_number' => '2',
        ]);
        $counts = (new PcmIndicatorService())->calculate($this->areaId);
        $this->assertSame(2, $counts['preventive']);
        $this->assertSame(1, $counts['emergency']);
        $service = new SectorDashboardService();
        $this->assertSame(1, $service->detailQuery($this->areaId, [
            'classification' => 'EMERGENCIAL', 'status' => 'EM ABERTO',
        ])?->count());
        $this->assertSame('OUTROS', (new PcmServiceClassifier())->classifySnapshot('MEL', 'CORPRO', 'Programada'));
        $this->assertSame('ENTRESSAFRA', (new PcmServiceClassifier())->classifySnapshot('PRE', 'FUTURO', 'ENTRESSAFRA'));
    }

    private function insertRow(
        int $import,
        int $area,
        int $number,
        string $situation,
        string $finished,
        string $type,
        string $code,
        string $name,
    ): void {
        $connection = self::connection();
        $connection->insert('work_orders', ['branch_code' => '1', 'source_order_number' => (string)$number,
            'first_seen_report_date' => '2026-09-10', 'last_seen_report_date' => '2026-09-10',
            'created' => '2026-09-10', 'updated' => '2026-09-10']);
        $workOrder = (int)$connection->getDriver()->lastInsertId();
        $connection->insert('work_order_snapshots', ['work_order_id' => $workOrder, 'report_import_id' => $import,
            'report_date' => '2026-09-10', 'maintenance_area_id' => $area, 'branch_code' => '1',
            'maintenance_area_code' => $number === 13 ? 'FUTURO' : 'MECANI', 'cost_center_code' => 'CC-' . $number,
            'source_order_number' => (string)$number, 'source_situation' => $situation, 'finished_raw' => $finished,
            'maintenance_type' => $type, 'service_code' => $code, 'service_name' => $name,
            'treated_status' => (new WorkOrderStatusResolver())->resolve($situation, $finished),
            'status_rule_version' => 4, 'source_row_number' => $number, 'raw_payload' => '{}',
            'row_hash' => hash('sha256', (string)$number), 'created' => '2026-09-10', 'updated' => '2026-09-10',
            'general_actual_start' => '2026-09-10 12:00:00',
        ]);
    }
}
