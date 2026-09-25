<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\CurrentSnapshotService;
use App\Service\DataQualityService;
use App\Service\PcmHistoryService;
use App\Service\PcmIndicatorService;
use App\Service\PcmPresentationService;
use App\Service\PcmTimeFormatter;
use App\Service\Protheus\OrderProtheusService;
use App\Service\Protheus\OrderListingService;
use App\Service\Protheus\ProtheusDashboardService;
use Cake\Http\Exception\BadRequestException;
use App\Service\SectorDashboardService;
use Cake\Datasource\FactoryLocator;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use DateTimeInterface;
use InvalidArgumentException;

final class PcmController extends AppController
{
    public function exportSector(string $code): Response
    {
        return $this->exportExcel('sector', strtoupper(trim($code)));
    }

    public function exportOrders(): Response
    {
        return $this->exportExcel('orders');
    }

    public function exportEquipment(): Response
    {
        return $this->exportExcel('equipment');
    }

    public function exportSectorEntries(string $code): Response
    {
        return $this->exportEntries(strtoupper(trim($code)));
    }

    public function exportOrderEntries(): Response
    {
        return $this->exportEntries(null);
    }

    private function exportEntries(?string $code): Response
    {
        $this->request->allowMethod(['get']);
        $this->request->getSession()->close();
        $report = new \App\Service\OrderStreamingExport();
        $generated = $report->generatedAt();
        try {
            $result = $report->entries($this->request->getQueryParams(), $code, $generated);
        } catch (InvalidArgumentException) {
            throw new BadRequestException('Filtros ou contexto inválidos.');
        } catch (\DomainException $error) {
            return $this->exportError($error->getMessage(), 422);
        } catch (\Throwable) {
            return $this->exportError('Não foi possível gerar o arquivo Excel neste momento. Tente novamente.', 503);
        }
        \Cake\Log\Log::info((string)json_encode([
            'event' => 'order_entry_excel_generated', 'user' => $this->request->getAttribute('identity')?->getIdentifier(),
            'generated_at' => $generated->format(DATE_ATOM), 'sector' => $code, 'count' => $result['count'],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $this->response->withType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff')
            ->withDownload($report->filename('APONTAMENTOS_OS_' . ($code ?? 'GERAL'), $generated))
            ->withBody(new \Laminas\Diactoros\Stream($result['stream']));
    }

    private function exportExcel(string $context, ?string $code = null): Response
    {
        $this->request->allowMethod(['get']);
        $this->request->getSession()->close();
        $report = new \App\Service\OrderStreamingExport();
        $generated = $report->generatedAt();
        try {
            $result = $report->orders($context, $this->request->getQueryParams(), $code, $generated);
        } catch (InvalidArgumentException) {
            throw new BadRequestException('Filtros ou contexto inválidos.');
        } catch (\OutOfBoundsException $error) {
            throw new NotFoundException($error->getMessage());
        } catch (\DomainException $error) {
            return $this->exportError($error->getMessage(), 422);
        } catch (\Throwable) {
            return $this->exportError('Não foi possível gerar o arquivo Excel neste momento. Tente novamente.', 503);
        }
        \Cake\Log\Log::info((string)json_encode([
            'event' => 'order_excel_generated', 'user' => $this->request->getAttribute('identity')?->getIdentifier(),
            'generated_at' => $generated->format(DATE_ATOM), 'context' => $context, 'sector' => $code,
            'count' => $result['count'],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $prefix = $context === 'equipment' ? 'HISTORICO_EQUIPAMENTO' : 'OS_' . ($code ?? 'GERAL');
        return $this->response->withType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff')
            ->withDownload($report->filename($prefix, $generated))->withBody(new \Laminas\Diactoros\Stream($result['stream']));
    }

    private function exportError(string $message, int $status): Response
    {
        return $this->response->withStatus($status)->withType('text/plain')->withCharset('UTF-8')
            ->withHeader('Cache-Control', 'no-store')->withStringBody($message);
    }

    /** Displays the company-wide current PCM snapshot. */
    public function index(): void
    {
        $this->directDashboard(false);
    }

    public function indexLegacy(): void
    {
        $snapshot = new CurrentSnapshotService();
        $indicators = (new PcmIndicatorService($snapshot))->calculate();

        $this->set(['indicators' => $indicators] + $this->currentContext($snapshot));
        $this->viewBuilder()->setTemplate('index_legacy');
    }

    public function equipment(): void
    {
        $this->request->allowMethod(['get']);
        $this->request->getSession()->close();
        try {
            $equipment = (new \App\Service\Protheus\EquipmentHistoryService())->load($this->request->getQueryParams());
        } catch (InvalidArgumentException) {
            throw new BadRequestException('Bem, filial ou filtros inválidos.');
        }
        if ($equipment['not_found']) throw new NotFoundException('Equipamento não encontrado no Protheus.');
        if (!$equipment['available']) $this->response = $this->response->withStatus(503);
        $this->set(compact('equipment'));
    }

    public function orders(): void
    {
        $this->request->getSession()->close();
        try {
            $service = new OrderListingService();
            $listing = $service->load($this->request->getQueryParams());
            $areas = $listing['available'] ? $service->areas() : [];
        } catch (InvalidArgumentException) {
            throw new BadRequestException('Filtros ou paginação inválidos.');
        }
        $this->set(['listing' => $listing, 'areas' => $areas, 'navigationAreas' => []]);
        $this->response = $this->response->withHeader('Cache-Control', 'no-store');
    }

    /** Legacy report and dashboard drilldowns retain their original snapshot rules. */
    public function ordersLegacy(): void
    {
        $snapshot = new CurrentSnapshotService();
        $service = new SectorDashboardService($snapshot);
        $filters = $service->filters($this->request->getQueryParams());
        $dashboard = $service->dashboard(null, $filters);
        $query = $service->detailQuery(null, $filters);
        $orders = $query === null ? [] : $this->paginate($query, [
            'limit' => 20, 'maxLimit' => 100, 'order' => ['maintenance_planned_start' => 'DESC', 'id' => 'DESC'],
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
        $this->directDashboard(true);
    }

    public function presentationLegacy(): void
    {
        $snapshot = new CurrentSnapshotService();
        $payload = (new PcmPresentationService($snapshot))->payload();
        $lastUpdatedAt = $snapshot->lastSuccessfulImportAt();
        $navigationAreas = [];

        $this->set(compact('payload', 'lastUpdatedAt', 'navigationAreas'));
        $this->viewBuilder()->setTemplate('presentation_legacy');
    }

    /** Returns only the current presentation counters and snapshot identity. */
    public function presentationData(): Response
    {
        return $this->dashboardData();
    }

    public function presentationLegacyData(): Response
    {
        $payload = (new PcmPresentationService())->payload();

        return $this->response
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function dashboardData(): Response
    {
        $this->request->allowMethod(['get']);
        $this->request->getSession()->close();
        try {
            $payload = (new ProtheusDashboardService())->load($this->request->getQueryParams());
        } catch (InvalidArgumentException) {
            throw new BadRequestException('Filtros inválidos.');
        }

        return $this->response->withType('application/json')->withHeader('Cache-Control', 'no-store')
            ->withStatus($payload['available'] ? 200 : 503)
            ->withStringBody((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function directDashboard(bool $presentation): void
    {
        $this->request->getSession()->close();
        try {
            $payload = (new ProtheusDashboardService())->load($this->request->getQueryParams());
        } catch (InvalidArgumentException) {
            throw new BadRequestException('Filtros inválidos.');
        }
        $this->set(compact('payload', 'presentation') + ['navigationAreas' => []]);
        $this->viewBuilder()->setTemplate('protheus_dashboard');
        $this->response = $this->response->withHeader('Cache-Control', 'no-store');
    }

    /** Displays company-wide operational and sector comparison analysis. */
    public function analyses(): void
    {
        $snapshot = new CurrentSnapshotService();
        $historyService = new PcmHistoryService($snapshot);
        $dashboard = $historyService->operationalReport();
        $indicators = $dashboard['indicators'];
        $sectorComparison = $historyService->sectorComparison(
            (string)$this->request->getQuery('sector_sort', 'area'),
            (string)$this->request->getQuery('sector_direction', 'asc'),
        );
        $dataQuality = (new DataQualityService($snapshot))->summary();

        $this->set(compact('dashboard', 'indicators', 'sectorComparison', 'dataQuality') + $this->currentContext($snapshot));
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
            'order' => ['maintenance_planned_start' => 'DESC', 'id' => 'DESC'],
            'sortableFields' => ['maintenance_planned_start', 'id', 'source_order_number', 'maintenance_area_code', 'equipment_code', 'service_name', 'cost_center_code'],
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
        $result = $this->sectorPayload($code);
        $this->set(['sector' => $result, 'navigationAreas' => array_map(static fn ($code, $name) =>
            (object)['source_code' => $code, 'display_name' => $name],
            array_keys(\App\Model\Table\MaintenanceAreasTable::FRIENDLY_NAMES),
            array_values(\App\Model\Table\MaintenanceAreasTable::FRIENDLY_NAMES))]);
        $this->viewBuilder()->setTemplate('sector_protheus');
    }

    public function sectorData(string $code): Response
    {
        $this->request->allowMethod(['get']);
        $sector = $this->sectorPayload($code);
        $this->set(compact('sector'));
        $this->viewBuilder()->setLayout(false);
        $html = $sector['available'] ? $this->createView()->element('protheus_sector_content', compact('sector')) : null;

        return $this->response->withType('application/json')->withHeader('Cache-Control', 'no-store')
            ->withStatus($sector['available'] ? 200 : 503)
            ->withStringBody((string)json_encode(['available' => $sector['available'], 'html' => $html,
                'charts' => $sector['charts'], 'queried_at' => $sector['queried_at'],
                'queried_at_display' => (new PcmTimeFormatter())->format(
                    $sector['queried_at'] ? new \DateTimeImmutable($sector['queried_at']) : null, 'd/m/Y, H:i:s',
                )], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
    }

    public function sectorOptions(): Response
    {
        $this->request->allowMethod(['get']);
        $this->request->getSession()->close();
        try {
            $areas = (new \App\Service\Protheus\ProtheusRepository(budgetSeconds: 5))->findAreas();
            $items = [];
            foreach ($areas as $area) {
                $code = $area['code'];
                if (!is_string($code) || !preg_match('/^[A-Za-z0-9_-]{1,30}$/D', $code)) continue;
                $items[] = ['code' => $code,
                    'name' => \App\Model\Table\MaintenanceAreasTable::FRIENDLY_NAMES[$code] ?? $code,
                    'url' => \Cake\Routing\Router::url(['_name' => 'pcm-sector', 'code' => $code])];
            }
            $payload = ['available' => true, 'areas' => $items];
        } catch (\Throwable) {
            $payload = ['available' => false, 'areas' => []];
        }

        return $this->response->withType('application/json')->withHeader('Cache-Control', 'no-store')
            ->withStatus($payload['available'] ? 200 : 503)
            ->withStringBody((string)json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
    }

    private function sectorPayload(string $code): array
    {
        $this->request->getSession()->close();
        try {
            return (new \App\Service\Protheus\ProtheusSectorService())->load(strtoupper(trim($code)), $this->request->getQueryParams());
        } catch (InvalidArgumentException) {
            throw new BadRequestException('Filtros, área ou paginação inválidos.');
        }
    }

    public function sectorLegacy(string $code): void
    {
        $this->set('isLegacySector', true);
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
            'order' => ['maintenance_planned_start' => 'DESC', 'id' => 'DESC'],
            'sortableFields' => SectorDashboardService::SORT_FIELDS,
        ]);
        $currentImport = $snapshot->currentImport();
        $lastUpdatedAt = $snapshot->lastSuccessfulImportAt();
        $navigationAreas = $snapshot->areas();
        $this->set(compact('area', 'indicators', 'currentImport', 'lastUpdatedAt', 'navigationAreas', 'dashboard', 'filters', 'orders'));
        $this->viewBuilder()->setTemplate('sector');
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
        $lastUpdatedAt = $snapshotService->lastSuccessfulImportAt();
        $navigationAreas = $snapshotService->areas();

        $this->set(compact('snapshot', 'history', 'changes', 'currentImport', 'lastUpdatedAt', 'navigationAreas'));
    }

    /** Optional JSON supplement, protected by the same login/TV rules and snapshot scope. */
    public function orderProtheus(int $id): Response
    {
        $this->request->allowMethod(['get']);
        $snapshot = (new CurrentSnapshotService())->order($id);
        if ($snapshot === null) {
            throw new NotFoundException('Ordem de Serviço não encontrada no snapshot atual.');
        }
        return $this->protheusPayload($snapshot->toArray());
    }

    public function protheusOrder(string $number): void
    {
        $identity = $this->protheusIdentity($number);
        $this->set(['identity' => $identity, 'navigationAreas' => []]);
        $this->response = $this->response->withHeader('Cache-Control', 'no-store');
    }

    public function protheusOrderData(string $number): Response
    {
        $this->request->allowMethod(['get']);

        return $this->protheusPayload($this->protheusIdentity($number));
    }

    private function protheusIdentity(string $number): array
    {
        $branch = $this->request->getQuery('filial');
        if (!preg_match('/^[A-Za-z0-9]{1,50}$/D', $number) || !is_string($branch) || strlen($branch) > 100) {
            throw new BadRequestException('Informe número da OS e filial válidos.');
        }

        return ['source_order_number' => $number, 'branch_code' => rtrim($branch, ' ')];
    }

    private function protheusPayload(array $identity): Response
    {
        $part = $this->request->getQuery('part', 'all');
        $selected = $this->request->getQuery('os');
        $page = filter_var($this->request->getQuery('page', '1'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if (!is_string($part) || !in_array($part, ['all', 'history', 'detail'], true)
            || ($selected !== null && !is_string($selected)) || $page === false) {
            return $this->response->withStatus(400)->withType('application/json')
                ->withStringBody('{"message":"Parâmetros inválidos."}');
        }
        // Do not hold the user's session lock while waiting for the complementary server.
        $this->request->getSession()->close();
        $payload = (new OrderProtheusService())->load($identity, $part, $page, $selected);

        return $this->response->withType('application/json')->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
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
        $service = new PcmHistoryService($snapshot);
        $query = $service->operationalMovementQuery($type, $area ? (int)$area->id : null);
        if ($query === null) {
            throw new NotFoundException('Comparativo histórico ainda indisponível.');
        }
        $orders = $this->paginate($query, ['limit' => 30, 'maxLimit' => 100, 'order' => ['maintenance_planned_start' => 'DESC', 'id' => 'DESC']]);
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
