<?php
declare(strict_types=1);

namespace App\Service;

final class PcmIndicatorService
{
    public const DRILLDOWNS = [
        'open' => ['status' => 'EM ABERTO'],
        'safra_open' => ['season' => 'safra', 'status' => 'EM ABERTO'],
        'safra_completed' => ['season' => 'safra', 'status' => 'FECHADA'],
        'offseason_open' => ['season' => 'offseason', 'status' => 'EM ABERTO'],
        'offseason_completed' => ['season' => 'offseason', 'status' => 'FECHADA'],
        'preventive' => ['maintenance_type' => 'PRE', 'status' => 'EM ABERTO'],
        'corrective' => ['maintenance_type' => 'COR', 'status' => 'EM ABERTO'],
        'improvement' => ['maintenance_type' => 'MEL', 'status' => 'EM ABERTO'],
        'emergency' => ['classification' => 'EMERGENCIAL', 'status' => 'EM ABERTO'],
        'scheduled' => ['classification' => 'PROGRAMADA', 'status' => 'EM ABERTO'],
        'offseason' => ['classification' => 'ENTRESSAFRA', 'status' => 'EM ABERTO'],
    ];

    public const TYPE_KEYS = ['PRE' => 'preventive', 'COR' => 'corrective', 'MEL' => 'improvement',
        '' => 'blank_maintenance_type'];

    /** Adds a card restriction without replacing any active restriction. */
    public static function drilldownFilters(array $filters, string $indicator): array
    {
        if (isset($filters['indicator'])) {
            $filters['within'] = array_values(array_unique(array_merge(
                $filters['within'] ?? [],
                [$filters['indicator']],
            )));
        }
        $filters['indicator'] = $indicator;

        return $filters;
    }

    /** Shares the latest successful import across every operational counter. */
    public function __construct(private readonly CurrentSnapshotService $currentSnapshot = new CurrentSnapshotService())
    {
    }

    /**
     * Calculates all cards from persisted treated statuses.
     *
     * @return array<string, int|float>
     */
    public function calculate(?int $maintenanceAreaId = null, array $filters = []): array
    {
        $query = $this->currentSnapshot->query();
        $statusCounts = $typeCounts = $serviceCounts = [];
        $seasons = ['safra_open' => 0, 'safra_completed' => 0, 'offseason_open' => 0, 'offseason_completed' => 0];
        if ($query !== null) {
            if ($maintenanceAreaId !== null) {
                $query->where(['maintenance_area_id' => $maintenanceAreaId]);
            }
            $query->find('filtered', filters: $filters);
            $fields = ['treated_status', 'maintenance_type', 'service_code', 'service_name'];
            $rows = $query->select($fields + ['quantity' => $query->func()->count('*')])
                ->groupBy($fields)->disableHydration();
            $classifier = new PcmServiceClassifier();
            foreach ($rows as $row) {
                $status = $row['treated_status'];
                $quantity = (int)$row['quantity'];
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + $quantity;
                $classification = $classifier->classifySnapshot($row['maintenance_type'], $row['service_code'], $row['service_name']);
                $season = $classification === 'ENTRESSAFRA' ? 'offseason' : 'safra';
                $seasons[$season . ($status === WorkOrderStatusResolver::OPEN ? '_open' : '_completed')] += $quantity;
                if ($status === WorkOrderStatusResolver::OPEN) {
                    $type = strtoupper(trim((string)$row['maintenance_type']));
                    $typeCounts[$type] = ($typeCounts[$type] ?? 0) + $quantity;
                    $class = $classifier->classifySnapshot(
                        $row['maintenance_type'],
                        $row['service_code'],
                        $row['service_name'],
                    );
                    $serviceCounts[$class] = ($serviceCounts[$class] ?? 0) + $quantity;
                }
            }
        }

        return $this->fromStatusCounts($statusCounts) + $this->fromMaintenanceTypeCounts($typeCounts)
            + $this->fromServiceCounts($serviceCounts) + $seasons;
    }

    /** Maps grouped open service counts to the shared dashboard and TV counters. */
    public function fromServiceCounts(array $counts): array
    {
        $result = [];
        foreach (PcmServiceClassifier::CARD_CLASSES as $key => $classification) {
            $result[$key] = $counts[$classification] ?? 0;
        }

        return $result;
    }

    /**
     * Applies the KPI formula to a grouped query result.
     *
     * @param array<string, int> $counts
     * @return array{total:int,open:int,completed:int,cancelled:int,efficiency:float}
     */
    public function fromStatusCounts(array $counts): array
    {
        $completed = $counts[WorkOrderStatusResolver::COMPLETED] ?? 0;
        $open = $counts[WorkOrderStatusResolver::OPEN] ?? 0;
        $cancelled = $counts[WorkOrderStatusResolver::CANCELLED] ?? 0;
        $total = $open + $completed;
        $activeTotal = $open + $completed;

        return [
            'total' => $total,
            'open' => $open,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'efficiency' => $activeTotal > 0 ? $completed / $activeTotal * 100 : 0.0,
        ];
    }

    /**
     * Maps only exact TOTVS Tipo Manut. codes for open work orders.
     *
     * @param array<string, int> $counts
     * @return array{preventive:int,corrective:int,improvement:int,blank_maintenance_type:int}
     */
    public function fromMaintenanceTypeCounts(array $counts): array
    {
        $result = [];
        foreach (self::TYPE_KEYS as $code => $key) {
            $result[$key] = $counts[$code] ?? 0;
        }

        return $result;
    }

    /** Maps only the normalized TOTVS maintenance type, independently of Serviço. */
    public static function maintenanceTypeKey(?string $type): ?string
    {
        return self::TYPE_KEYS[strtoupper(trim($type ?? ''))] ?? null;
    }
}
