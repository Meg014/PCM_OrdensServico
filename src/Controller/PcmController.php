<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\CurrentSnapshotService;
use App\Service\DataQualityService;
use App\Service\PcmHistoryService;
use App\Service\PcmIndicatorService;
use App\Service\PcmPresentationService;
use App\Service\PcmTimeFormatter;
use App\Service\SectorDashboardService;
use Cake\Datasource\FactoryLocator;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use DateTimeInterface;
use InvalidArgumentException;

final class PcmController extends AppController
{
    /** Displays the company-wide current PCM snapshot. */
    public function index(): void
    {
        $snapshot = new CurrentSnapshotService();
        $indicators = (new PcmIndicatorService($snapshot))->calculate();

        $this->set(['indicators' => $indicators] + $this->currentContext($snapshot));
    }

    /** Reuses the sector analytical view for a paginated company-wide current report. */
    public function orders(): void
    {
        $snapshot = new CurrentSnapshotService();
        $service = new SectorDashboardService($snapshot);
        $filters = $service->filters($this->request->getQueryParams());
        $dashboard = $service->dashboard(null, $filters);
        $query = $service->detailQuery(null, $filters);
        $orders = $query === null ? [] : $this->paginate($query, [
            'limit' => 20, 'maxLimit' => 100, 'order' => ['source_order_number' => 'ASC'],
            'sortableFields' => SectorDashboardService::SORT_FIELDS,
        ]);
        $area = (object)['source_code' => null, 'display_name' => 'Todas as áreas'];
        $indicators = $dashboard['indicators'];
        $this->set(compact('area', 'filters', 'dashboard', 'orders', 'indicators') + $this->currentContext($snapshot));
        $this->viewBuilder()->setTemplate('sector');
    }

    /** Returns the lightweight identity of the current successful import. */
    public function currentVersion(): Response
    {
        $version = (new CurrentSnapshotService())->currentVersion();
        $payload = $version ?? ['report_date' => null, 'import_id' => null, 'updated_at' => null];

        return $this->response
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** Displays the lightweight TV presentation without detailed sector content. */
    public function presentation(): void
    {
        $snapshot = new CurrentSnapshotService();
        $payload = (new PcmPresentationService($snapshot))->payload();
        $lastUpdatedAt = $snapshot->lastSuccessfulImportAt();
        $navigationAreas = [];

        $this->set(compact('payload', 'lastUpdatedAt', 'navigationAreas'));
    }

    /** Returns only the current presentation counters and snapshot identity. */
    public function presentationData(): Response
    {
        $payload = (new PcmPresentationService())->payload();

        return $this->response
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** Displays company-wide historical and comparative analysis. */
    public function analyses(): void
    {
        $snapshot = new CurrentSnapshotService();
        $historyService = new PcmHistoryService();
        $history = $historyService->history(null, $historyService->period($this->request->getQueryParams()));
        $sectorComparison = $historyService->sectorComparison(
            (string)$this->request->getQuery('sector_sort', 'area'),
            (string)$this->request->getQuery('sector_direction', 'asc'),
        );
        $dataQuality = (new DataQualityService($snapshot))->summary();

        $this->set(compact('history', 'sectorComparison', 'dataQuality') + $this->currentContext($snapshot));
    }

    /** Lists current-snapshot OS affected by one objective data-quality rule. */
    public function quality(string $type): void
    {
        $snapshot = new CurrentSnapshotService();
        $service = new DataQualityService($snapshot);
        try {
            $definition = $service->definition($type);
            $query = $service->detailQuery($type);
        } catch (InvalidArgumentException) {
            throw new NotFoundException('Tipo de inconsistência não encontrado.');
        }
        $orders = $query === null ? [] : $this->paginate($query, [
            'limit' => 30,
            'maxLimit' => 100,
            'order' => ['source_order_number' => 'ASC'],
            'sortableFields' => ['source_order_number', 'maintenance_area_code', 'equipment_code', 'service_name', 'cost_center_code'],
        ]);
        $values = [];
        foreach ($orders as $order) {
            $values[(int)$order->id] = $service->valueFor($order, $type);
        }
        $currentImport = $snapshot->currentImport();
        $lastUpdatedAt = $snapshot->lastSuccessfulImportAt();
        $navigationAreas = $snapshot->areas();

        $this->set(compact('type', 'definition', 'orders', 'values', 'currentImport', 'lastUpdatedAt', 'navigationAreas'));
    }

    /** Validates reusable indicator filtering for one maintenance area. */
    public function sector(string $code): void
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9_-]{1,30}$/', $code)) {
            throw new NotFoundException('Código de área inválido.');
        }
        $snapshot = new CurrentSnapshotService();
        $area = $snapshot->area($code);
        if ($area === null) {
            throw new NotFoundException('Área de manutenção não encontrada.');
        }
        $dashboardService = new SectorDashboardService($snapshot);
        $filters = $dashboardService->filters($this->request->getQueryParams());
        $dashboard = $dashboardService->dashboard((int)$area->id, $filters);
        $indicators = $dashboard['indicators'];
        $query = $dashboardService->detailQuery((int)$area->id, $filters);
        $orders = $query === null ? [] : $this->paginate($query, [
            'limit' => 20,
            'maxLimit' => 100,
            'order' => ['source_order_number' => 'ASC'],
            'sortableFields' => SectorDashboardService::SORT_FIELDS,
        ]);
        $currentImport = $snapshot->currentImport();
        $lastUpdatedAt = $snapshot->lastSuccessfulImportAt();
        $navigationAreas = $snapshot->areas();
        $historyService = new PcmHistoryService();
        $history = $historyService->history((int)$area->id, $historyService->period($this->request->getQueryParams()));
        $comparison = $history['comparison'];

        $this->set(compact('area', 'indicators', 'currentImport', 'lastUpdatedAt', 'navigationAreas', 'dashboard', 'filters', 'orders', 'history', 'comparison'));
    }

    /** Displays one current snapshot and the existing history of its OS identity. */
    public function order(int $id): void
    {
        $snapshotService = new CurrentSnapshotService();
        $table = FactoryLocator::get('Table')->get('WorkOrderSnapshots');
        $snapshot = $snapshotService->order($id);
        if ($snapshot === null) {
            throw new NotFoundException('Ordem de Serviço não encontrada no snapshot atual.');
        }
        $history = $table->find()->contain(['ReportImports', 'MaintenanceAreas'])
            ->where(['WorkOrderSnapshots.work_order_id' => (int)$snapshot->work_order_id])
            ->orderBy(['ReportImports.report_date' => 'ASC', 'WorkOrderSnapshots.id' => 'ASC'])->all()->toList();
        $changes = $this->historyChanges($history);
        $currentImport = $snapshotService->currentImport();
        $navigationAreas = $snapshotService->areas();

        $this->set(compact('snapshot', 'history', 'changes', 'currentImport', 'navigationAreas'));
    }

    /** Lists OS identities involved in the latest real snapshot movement. */
    public function movement(string $type): void
    {
        $types = ['new', 'absent', 'completed', 'open', 'cancelled'];
        if (!in_array($type, $types, true)) {
            throw new NotFoundException('Tipo de movimentação inválido.');
        }
        $snapshot = new CurrentSnapshotService();
        $area = null;
        $areaCode = strtoupper(trim((string)$this->request->getQuery('area', '')));
        if ($areaCode !== '') {
            if (!preg_match('/^[A-Z0-9_-]{1,30}$/', $areaCode)) {
                throw new NotFoundException('Código de área inválido.');
            }
            $area = $snapshot->area($areaCode);
            if ($area === null) {
                throw new NotFoundException('Área de manutenção não encontrada.');
            }
        }
        $service = new PcmHistoryService();
        $query = $service->movementQuery($type, $area ? (int)$area->id : null);
        if ($query === null) {
            throw new NotFoundException('Comparativo histórico ainda indisponível.');
        }
        $orders = $this->paginate($query, ['limit' => 30, 'maxLimit' => 100, 'order' => ['source_order_number' => 'ASC']]);
        $currentImport = $snapshot->currentImport();
        $lastUpdatedAt = $snapshot->lastSuccessfulImportAt();
        $navigationAreas = $snapshot->areas();

        $this->set(compact('type', 'area', 'orders', 'currentImport', 'lastUpdatedAt', 'navigationAreas'));
    }

    /** @return array<int, list<string>> */
    private function historyChanges(array $history): array
    {
        $fields = ['treated_status' => 'STATUS', 'maintenance_area_code' => 'Área', 'equipment_code' => 'Bem',
            'service_code' => 'Serviço', 'source_situation' => 'Situação', 'finished_raw' => 'Término',
            'general_actual_start' => 'Real. Início', 'general_actual_end' => 'Real. Fim'];
        $result = [];
        $previous = null;
        $timeFormatter = new PcmTimeFormatter();
        $format = static function (mixed $value) use ($timeFormatter): string {
            if ($value instanceof DateTimeInterface) {
                return $timeFormatter->format($value);
            }

            return $value === null || $value === '' ? '—' : (string)$value;
        };
        foreach ($history as $item) {
            $result[(int)$item->id] = [];
            if ($previous !== null) {
                foreach ($fields as $field => $label) {
                    if ((string)$previous->{$field} !== (string)$item->{$field}) {
                        $result[(int)$item->id][] = sprintf('%s: %s → %s', $label, $format($previous->{$field}), $format($item->{$field}));
                    }
                }
            }
            $previous = $item;
        }

        return $result;
    }

    /** @return array{currentImport:?object,lastUpdatedAt:?\DateTimeInterface,navigationAreas:array} */
    private function currentContext(CurrentSnapshotService $snapshot): array
    {
        return [
            'currentImport' => $snapshot->currentImport(),
            'lastUpdatedAt' => $snapshot->lastSuccessfulImportAt(),
            'navigationAreas' => $snapshot->areas(),
        ];
    }
}
