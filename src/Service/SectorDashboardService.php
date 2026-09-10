<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Query\SelectQuery;

final class SectorDashboardService
{
    public const STATUSES = [WorkOrderStatusResolver::OPEN, WorkOrderStatusResolver::COMPLETED,
        WorkOrderStatusResolver::CANCELLED];
    public const SORT_FIELDS = ['source_order_number', 'equipment_code', 'equipment_name', 'service_code',
        'service_name', 'cost_center_code', 'maintenance_type', 'source_situation', 'finished_raw',
        'general_actual_start', 'treated_status'];

    /** Reuses the same current import for every query in a request. */
    public function __construct(private readonly CurrentSnapshotService $current = new CurrentSnapshotService())
    {
    }

    /** Validates only supported filter values; sorting is handled by the paginator. */
    public function filters(array $query): array
    {
        $limits = ['equipment' => 100, 'service' => 30, 'service_name' => 255, 'cost_center' => 30,
            'maintenance_type' => 30, 'area' => 30, 'q' => 100];
        $filters = [];
        if (isset($query['status']) && in_array($query['status'], self::STATUSES, true)) {
            $filters['status'] = $query['status'];
        }
        if (
            isset($query['classification']) && is_string($query['classification'])
            && isset(PcmServiceClassifier::LABELS[$query['classification']])
        ) {
            $filters['classification'] = $query['classification'];
        }
        foreach ($limits as $key => $limit) {
            if (!isset($query[$key]) || !is_string($query[$key])) {
                continue;
            }
            $value = trim($query[$key]);
            if ($value !== '' && mb_strlen($value) <= $limit && !preg_match('/[\x00-\x1F]/u', $value)) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /** Builds a scoped query; audit lists retain cancelled OS. */
    private function query(?int $areaId, array $filters = [], bool $operational = false): ?SelectQuery
    {
        $query = $this->current->query();
        if ($query === null) {
            return null;
        }
        if ($areaId !== null) {
            $query->where(['maintenance_area_id' => $areaId]);
        }
        $query->find('filtered', filters: $filters);
        if ($operational) {
            $query->where(['treated_status IN' => [WorkOrderStatusResolver::OPEN, WorkOrderStatusResolver::COMPLETED]]);
        }

        return $query;
    }

    /** Calculates every operational aggregate on the backend, excluding cancellations. */
    public function dashboard(?int $areaId, array $filters): array
    {
        $base = $this->query($areaId, $filters, true);
        $indicators = (new PcmIndicatorService($this->current))->calculate($areaId, $filters);
        $total = (int)$indicators['total'];
        $status = $this->aggregate($base, 'treated_status', 'treated_status', $total);
        $maintenanceProfile = $this->aggregate($base, 'maintenance_type', 'maintenance_type', $total);
        $equipment = $this->aggregate($base, 'equipment_code', 'equipment_name', $total);
        $services = $this->aggregate($base, 'service_code', 'service_name', $total);
        $costCenters = $this->aggregate($base, 'cost_center_code', 'cost_center_code', $total);
        $summary = $this->serviceSummary($base);
        $missingStart = $base === null ? 0 : (clone $base)->where(['general_actual_start IS' => null])->count();

        $result = compact(
            'indicators',
            'status',
            'maintenanceProfile',
            'equipment',
            'services',
            'costCenters',
            'summary',
        );

        return $result + [
            'attention' => ['cancelled' => $indicators['cancelled'], 'missingStart' => $missingStart,
                'topEquipment' => $equipment[0] ?? null, 'topService' => $services[0] ?? null],
            'options' => $this->options($areaId),
        ];
    }

    /** Returns only the fields needed by the paginated list. */
    public function detailQuery(?int $areaId, array $filters): ?SelectQuery
    {
        return $this->query($areaId, $filters)?->select([
            'id', 'work_order_id', 'source_order_number', 'equipment_code', 'equipment_name',
            'service_code', 'service_name', 'maintenance_area_code', 'cost_center_code',
            'maintenance_type', 'source_situation', 'finished_raw', 'general_actual_start', 'treated_status',
        ]);
    }

    /** Groups distinct dimensions on the database rather than loading snapshots. */
    private function aggregate(?SelectQuery $base, string $code, string $label, int $total): array
    {
        if ($base === null) {
            return [];
        }
        $query = clone $base;
        $rows = $query->select(['dimension_code' => $code, 'dimension_label' => $label,
            'quantity' => $query->func()->count('*')])->groupBy(array_unique([$code, $label]))
            ->orderBy(['quantity' => 'DESC', 'dimension_label' => 'ASC'])->limit(10)->disableHydration();
        $result = [];
        foreach ($rows as $row) {
            $key = (string)($row['dimension_code'] ?? '');
            $name = (string)($row['dimension_label'] ?? '');
            if ($code === 'equipment_code') {
                $name = ($key ?: '—') . ' — ' . ($name ?: 'Sem nome');
            }
            $result[] = ['key' => $key, 'label' => $name ?: ($key ?: 'Sem classificação'),
                'quantity' => (int)$row['quantity'],
                'percentage' => $total > 0 ? (int)$row['quantity'] / $total * 100 : 0.0];
        }

        return $result;
    }

    /** Produces non-overlapping operational service groups from aggregated rows. */
    private function serviceSummary(?SelectQuery $base): array
    {
        $labels = PcmServiceClassifier::LABELS;
        unset($labels['OUTROS']);
        $labels += ['PREVENTIVA' => 'Preventivas', 'OUTROS' => 'Outros'];
        $summary = [];
        foreach ($labels as $key => $label) {
            $summary[$key] = ['key' => $key, 'label' => $label, 'open' => 0, 'completed' => 0];
        }
        if ($base !== null) {
            $query = clone $base;
            $fields = ['service_code', 'service_name', 'maintenance_type', 'treated_status'];
            $rows = $query->select($fields + ['quantity' => $query->func()->count('*')])
                ->groupBy($fields)->disableHydration();
            $classifier = new PcmServiceClassifier();
            foreach ($rows as $row) {
                $key = $classifier->classifySnapshot(
                    $row['maintenance_type'],
                    $row['service_code'],
                    $row['service_name'],
                );
                $type = PcmIndicatorService::maintenanceTypeKey($row['maintenance_type']);
                if ($key === 'OUTROS' && $type === 'preventive') {
                    $key = 'PREVENTIVA';
                }
                $status = $row['treated_status'] === WorkOrderStatusResolver::OPEN ? 'open' : 'completed';
                $summary[$key][$status] += (int)$row['quantity'];
            }
        }

        return array_values($summary);
    }

    /** Lists distinct filter options from the latest successful import, including audit rows. */
    private function options(?int $areaId): array
    {
        $map = ['equipment' => ['equipment_code', 'equipment_name'], 'service' => ['service_code', 'service_name'],
            'service_name' => ['service_name', 'service_name'],
            'cost_center' => ['cost_center_code', 'cost_center_code'],
            'maintenance_type' => ['maintenance_type', 'maintenance_type'],
            'area' => ['maintenance_area_code', 'maintenance_area_code']];
        $options = [];
        foreach ($map as $filter => [$code, $label]) {
            $query = $this->query($areaId);
            $options[$filter] = [];
            if ($query === null) {
                continue;
            }
            $rows = $query->select(['option_value' => $code, 'option_label' => $label])
                ->where([$code . ' IS NOT' => null])->distinct([$code, $label])
                ->orderBy(['option_label' => 'ASC', 'option_value' => 'ASC'])->disableHydration();
            foreach ($rows as $row) {
                $value = (string)$row['option_value'];
                $name = (string)$row['option_label'];
                $options[$filter][] = [
                    'value' => $value, 'label' => $value === $name ? $value : $value . ' — ' . $name,
                ];
            }
        }

        return $options;
    }
}
