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
    use \Cake\TestSuite\IntegrationTestTrait;

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
            ['total' => 10, 'open' => 8, 'completed' => 2, 'cancelled' => 0,
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
        $this->assertSame(0, $service->detailQuery($this->areaId, ['status' => 'CANCELADA'])?->count());
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

    public function testOpenCutoffSeasonsAndEveryDrilldown(): void
    {
        $connection = self::connection();
        $before = (new PcmIndicatorService())->calculate();
        foreach (['2024-12-31', '2025-12-31', null] as $index => $date) {
            foreach (['PRE', 'COR', 'MEL'] as $offset => $type) {
                $number = 100 + $index * 10 + $offset;
                $this->insertRow($this->currentId, $this->areaId, $number, 'Liberada', 'Não', $type, 'COREME', 'ENTRESSAFRA');
                $connection->update('work_order_snapshots', ['maintenance_planned_start' => $date], ['source_order_number' => (string)$number]);
            }
        }
        $this->assertSame($before, (new PcmIndicatorService())->calculate());
        $this->assertSame(7, $before['safra_open']);
        $this->assertSame(1, $before['safra_completed']);
        $this->assertSame(1, $before['offseason_open']);
        $this->assertSame(1, $before['offseason_completed']);
        $this->assertSame($before['total'], $before['safra_open'] + $before['safra_completed'] + $before['offseason_open'] + $before['offseason_completed']);
        $service = new SectorDashboardService();
        foreach ([[], ['status' => 'FECHADA'], ['season' => 'offseason'], ['maintenance_type' => 'PRE']] as $filters) {
            $counts = (new PcmIndicatorService())->calculate(null, $filters);
            foreach (PcmIndicatorService::DRILLDOWNS as $key => $definition) {
                $this->assertSame($counts[$key], $service->detailQuery(null, $filters + ['indicator' => $key])->count(), $key);
            }
        }
        $this->insertRow($this->currentId, $this->areaId, 200, 'Liberada', 'Sim', 'MEL', 'FUT', 'Entressafra');
        $connection->update('work_order_snapshots', ['maintenance_planned_start' => '2027-01-01'], ['source_order_number' => '200']);
        $this->assertSame(2, (new PcmIndicatorService())->calculate()['offseason_completed']);
        $this->assertSame(25, $connection->execute('SELECT COUNT(*) FROM work_order_snapshots')->fetchColumn(0));
    }

    public function testOperationalRoutesAndOldOrderCannotBypassScope(): void
    {
        $connection = self::connection();
        $connection->update('work_order_snapshots', ['maintenance_planned_start' => '2025-12-31',
            'equipment_name' => 'EXCLUDED_2025', 'equipment_code' => 'EXCLUDED_2025'],
            ['report_import_id' => $this->currentId, 'source_order_number' => '1']);
        foreach (['/pcm', '/pcm/ordens', '/pcm/setor/MECANI', '/pcm/setor/FUTURO', '/pcm/analises',
            '/pcm/apresentacao', '/pcm/analises/qualidade/missing_service'] as $url) {
            $this->get($url);
            $this->assertResponseOk();
            $this->assertResponseNotContains('DADOS REFERENTES ÀS O.S. CRIADAS A PARTIR DE 2026');
            $this->assertResponseNotContains('EXCLUDED_2025');
        }
        $old = $connection->execute('SELECT id FROM work_order_snapshots WHERE report_import_id = :import AND source_order_number = :number',
            ['import' => $this->currentId, 'number' => '1'])->fetchColumn(0);
        $this->get('/pcm/os/' . $old);
        $this->assertResponseCode(404);
        $this->get('/pcm/ordens?season=offseason&indicator=safra_open');
        $this->assertResponseOk();
        $this->assertResponseContains('Nenhuma O.S. encontrada');
        $filters = ['season' => 'offseason', 'within' => ['safra_open'], 'indicator' => 'offseason_open'];
        $this->assertSame(0, (new SectorDashboardService())->detailQuery(null, $filters)->count());
        $dashboard = (new SectorDashboardService())->dashboard(null, []);
        $this->assertSame(9, array_sum(array_column($dashboard['status'], 'quantity')));
        $this->assertSame(9, (new \App\Service\DataQualityService())->summary()['total']);
        $this->assertSame(9, array_sum(array_column((new \App\Service\PcmHistoryService())->sectorComparison(), 'total')));
    }

    public function testDistinctTemporalScopesAcrossCardsListsAndDashboards(): void
    {
        $connection = self::connection();
        $before = (new PcmIndicatorService())->calculate();
        $expectedOpen = $expectedClosed = [];
        $number = 300;
        foreach (['2024-01-01', '2025-12-31', '2026-01-01', '2027-01-01', null] as $date) {
            foreach (['Safra', 'Entressafra'] as $season) {
                foreach (['EM ABERTO', 'FECHADA', 'CANCELADA'] as $status) {
                    $number++;
                    $this->insertRow($this->currentId, $this->areaId, $number,
                        $status === 'CANCELADA' ? 'Cancelada' : 'Liberada',
                        $status === 'EM ABERTO' ? 'Não' : 'Sim', 'PRE', 'TEMP', $season);
                    $connection->update('work_order_snapshots', [
                        'maintenance_planned_start' => $date,
                        'equipment_code' => 'TEMP-' . $number,
                        'equipment_name' => 'TEMP-' . $number,
                    ], ['source_order_number' => (string)$number]);
                    if ($status === 'FECHADA') {
                        $expectedClosed[] = (string)$number;
                    } elseif ($status === 'EM ABERTO' && $date !== null && $date >= '2026-01-01') {
                        $expectedOpen[] = (string)$number;
                    }
                }
            }
        }
        // Neither a previous successful import nor a newer failed import contributes closed rows.
        $connection->update('work_order_snapshots', [
            'treated_status' => 'FECHADA', 'finished_raw' => 'Sim', 'maintenance_planned_start' => '2024-01-01',
        ], ['source_order_number IN' => ['90', '91']]);
        $current = new \App\Service\CurrentSnapshotService();
        foreach (['EM ABERTO' => $expectedOpen, 'FECHADA' => $expectedClosed] as $status => $expected) {
            $actual = $current->query(status: $status)->where(['source_order_number IN' => array_map('strval', range(301, 330))])
                ->all()->extract('source_order_number')->toList();
            $this->assertEqualsCanonicalizing($expected, $actual);
        }
        $counts = (new PcmIndicatorService())->calculate();
        foreach (['safra_open' => 2, 'offseason_open' => 2, 'safra_completed' => 5,
            'offseason_completed' => 5, 'open' => 4, 'completed' => 10, 'preventive' => 4,
            'corrective' => 0, 'improvement' => 0, 'emergency' => 0, 'scheduled' => 0] as $key => $delta) {
            $this->assertSame($before[$key] + $delta, $counts[$key], $key);
        }
        $this->assertSame(0, $counts['cancelled']);
        $service = new SectorDashboardService();
        foreach ([null, $this->areaId] as $areaId) {
            foreach ([[], ['status' => 'FECHADA'], ['season' => 'offseason'],
                ['maintenance_type' => 'PRE'], ['within' => ['safra_completed']]] as $filters) {
                $filtered = (new PcmIndicatorService())->calculate($areaId, $filters);
                foreach (PcmIndicatorService::DRILLDOWNS as $key => $definition) {
                    $this->assertSame($filtered[$key], $service->detailQuery($areaId,
                        $filters + ['indicator' => $key])->count(), $key);
                }
            }
        }
        $dashboard = $service->dashboard(null, []);
        $this->assertSame($counts['total'], array_sum(array_column($dashboard['status'], 'quantity')));
        $this->assertSame($counts['completed'], array_sum(array_column($dashboard['summary'], 'completed')));
        $this->assertSame($counts['total'], array_sum(array_column(
            (new \App\Service\PcmHistoryService())->sectorComparison(), 'total')));
        $this->assertSame($counts['total'], (new \App\Service\DataQualityService())->summary()['total']);
        $payload = (new PcmPresentationService())->payload();
        foreach ($payload['screens'] as $screen) {
            $areaId = $screen['key'] === 'general' ? null : (int)$current->area($screen['key'])->id;
            $indicators = (new PcmIndicatorService())->calculate($areaId);
            foreach (['safra_open', 'safra_completed', 'offseason_open', 'offseason_completed'] as $key) {
                $this->assertSame($indicators[$key], $screen[$key]);
            }
        }
        // The first old closed Safra order is visible, while the adjacent old open/cancelled ones are not.
        foreach (['/pcm/ordens', '/pcm/setor/MECANI'] as $route) {
            $this->get($route . '?indicator=safra_completed&limit=1&sort=source_order_number&direction=asc&page=2');
            $this->assertResponseOk();
            $this->assertResponseContains('TEMP-302');
            $this->assertResponseNotContains('TEMP-301');
            $this->assertResponseNotContains('TEMP-303');
            $this->get($route . '?indicator=safra_open');
            $this->assertResponseOk();
            $this->assertResponseNotContains('TEMP-301</td>');
        }
        $oldClosed = $current->query(status: 'FECHADA')->where(['source_order_number' => '302'])->first();
        $this->get('/pcm/os/' . $oldClosed->id);
        $this->assertResponseOk();
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
            'general_actual_start' => '2026-09-10 12:00:00', 'maintenance_planned_start' => '2026-01-01 00:00:00',
        ]);
    }
}
