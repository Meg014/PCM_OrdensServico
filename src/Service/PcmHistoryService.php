<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\FactoryLocator;
use Cake\I18n\Date;
use Cake\ORM\Table;
use Cake\ORM\Query\SelectQuery;
use DateTimeInterface;

final class PcmHistoryService
{
    private Table $imports;
    private Table $snapshots;

    public function __construct()
    {
        $locator = FactoryLocator::get('Table');
        $this->imports = $locator->get('ReportImports');
        $this->snapshots = $locator->get('WorkOrderSnapshots');
    }

    /** @return array{mode:string,from:?string,to:?string} */
    public function period(array $query): array
    {
        $mode = in_array(($query['history_period'] ?? ''), ['7', '30', 'custom'], true)
            ? (string)$query['history_period'] : '30';
        $from = $this->validDate($query['history_from'] ?? null);
        $to = $this->validDate($query['history_to'] ?? null);
        if ($mode !== 'custom' || $from === null || $to === null || $from > $to) {
            $from = $to = null;
            if ($mode === 'custom') {
                $mode = '30';
            }
        }

        return compact('mode', 'from', 'to');
    }

    /** @return array<string, mixed> */
    public function history(?int $areaId, array $period): array
    {
        $allImports = $this->dailyImports();
        $selected = $this->filterPeriod($allImports, $period);
        $series = $this->series($selected, $areaId);

        return [
            'available' => count($allImports) >= 2,
            'snapshotCount' => count($allImports),
            'series' => $series,
            'comparison' => $this->comparison($allImports, $areaId),
            'equipmentOccurrences' => $this->equipmentOccurrences($selected, $areaId),
            'period' => $period,
        ];
    }

    /** @return list<array<string, int|float|string>> */
    public function sectorComparison(string $sort = 'area', string $direction = 'asc'): array
    {
        $imports = $this->dailyImports();
        $current = $imports === [] ? null : $imports[array_key_last($imports)];
        if ($current === null) {
            return [];
        }
        $sql = "SELECT a.source_code, a.display_name, s.treated_status, COUNT(*) quantity
            FROM work_order_snapshots s JOIN maintenance_areas a ON a.id = s.maintenance_area_id
            WHERE s.report_import_id = :import GROUP BY a.id, a.source_code, a.display_name, s.treated_status";
        $rows = $this->snapshots->getConnection()->execute($sql, ['import' => $current['id']])->fetchAll('assoc');
        $areas = [];
        foreach ($rows as $row) {
            $code = (string)$row['source_code'];
            $areas[$code] ??= ['area' => $code, 'name' => (string)$row['display_name'], 'total' => 0,
                'open' => 0, 'completed' => 0, 'cancelled' => 0, 'efficiency' => 0.0];
            $key = $this->statusKey((string)$row['treated_status']);
            $quantity = (int)$row['quantity'];
            $areas[$code][$key] = $quantity;
            if ($key !== 'cancelled') {
                $areas[$code]['total'] += $quantity;
            }
        }
        foreach ($areas as &$area) {
            $area['efficiency'] = $area['total'] > 0 ? ($area['completed'] / $area['total']) * 100 : 0.0;
        }
        unset($area);
        $allowed = ['area', 'name', 'total', 'open', 'completed', 'cancelled', 'efficiency'];
        $sort = in_array($sort, $allowed, true) ? $sort : 'area';
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        uasort($areas, static function (array $a, array $b) use ($sort, $direction): int {
            $result = $a[$sort] <=> $b[$sort];
            return $direction === 'desc' ? -$result : $result;
        });

        return array_values($areas);
    }

    public function movementQuery(string $type, ?int $areaId = null): ?SelectQuery
    {
        $imports = $this->dailyImports();
        if (count($imports) < 2) {
            return null;
        }
        $previous = $imports[count($imports) - 2];
        $current = $imports[count($imports) - 1];
        $fields = ['id', 'work_order_id', 'source_order_number', 'equipment_code', 'equipment_name',
            'service_name', 'maintenance_area_code', 'treated_status'];
        $query = $this->snapshots->find()->select($fields);
        if ($type === 'absent') {
            $query->select(['previous_status' => 'WorkOrderSnapshots.treated_status'])
                ->leftJoin(['CurrentSnapshot' => 'work_order_snapshots'], [
                    'CurrentSnapshot.work_order_id = WorkOrderSnapshots.work_order_id',
                    'CurrentSnapshot.report_import_id' => $current['id'],
                ])->where(['WorkOrderSnapshots.report_import_id' => $previous['id'], 'CurrentSnapshot.id IS' => null]);
        } else {
            $query->select(['previous_status' => 'PreviousSnapshot.treated_status'])
                ->leftJoin(['PreviousSnapshot' => 'work_order_snapshots'], [
                    'PreviousSnapshot.work_order_id = WorkOrderSnapshots.work_order_id',
                    'PreviousSnapshot.report_import_id' => $previous['id'],
                ])->where(['WorkOrderSnapshots.report_import_id' => $current['id']]);
            if ($type === 'new') {
                $query->where(['PreviousSnapshot.id IS' => null]);
            } else {
                $targets = ['completed' => WorkOrderStatusResolver::COMPLETED,
                    'open' => WorkOrderStatusResolver::OPEN, 'cancelled' => WorkOrderStatusResolver::CANCELLED];
                if (!isset($targets[$type])) {
                    return null;
                }
                $query->where(['WorkOrderSnapshots.treated_status' => $targets[$type],
                    'PreviousSnapshot.id IS NOT' => null,
                    'PreviousSnapshot.treated_status != WorkOrderSnapshots.treated_status']);
            }
        }
        if ($areaId !== null) {
            $query->where(['WorkOrderSnapshots.maintenance_area_id' => $areaId]);
        }

        return $query;
    }

    /** @return list<array{id:int,date:string}> */
    private function dailyImports(): array
    {
        $sql = "SELECT r.id, r.report_date FROM report_imports r WHERE r.status = 'success'
            ORDER BY r.id ASC";
        $rows = $this->imports->getConnection()->execute($sql)->fetchAll('assoc');

        return array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'date' => (string)$row['report_date']], $rows);
    }

    private function filterPeriod(array $imports, array $period): array
    {
        if ($imports === []) {
            return [];
        }
        $to = $period['to'] ?? $imports[array_key_last($imports)]['date'];
        $from = $period['from'] ?? (new Date($to))->subDays((int)$period['mode'] - 1)->format('Y-m-d');

        return array_values(array_filter($imports, static fn(array $item): bool => $item['date'] >= $from && $item['date'] <= $to));
    }

    private function series(array $imports, ?int $areaId): array
    {
        if ($imports === []) {
            return [];
        }
        [$in, $params] = $this->idParams($imports);
        $where = "s.report_import_id IN ({$in})";
        if ($areaId !== null) {
            $where .= ' AND s.maintenance_area_id = :area';
            $params['area'] = $areaId;
        }
        $sql = "SELECT s.report_import_id, s.treated_status, COUNT(*) quantity FROM work_order_snapshots s
            WHERE {$where} GROUP BY s.report_import_id, s.treated_status";
        $rows = $this->snapshots->getConnection()->execute($sql, $params)->fetchAll('assoc');
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['report_import_id']][(string)$row['treated_status']] = (int)$row['quantity'];
        }
        $indicatorService = new PcmIndicatorService();
        $series = [];
        foreach ($imports as $import) {
            $series[] = ['date' => $import['date']] + $indicatorService->fromStatusCounts($counts[$import['id']] ?? []);
        }

        return $series;
    }

    private function comparison(array $imports, ?int $areaId): ?array
    {
        if (count($imports) < 2) {
            return null;
        }
        $previous = $imports[count($imports) - 2];
        $current = $imports[count($imports) - 1];
        $series = $this->series([$previous, $current], $areaId);
        $delta = [];
        foreach (['total', 'open', 'completed', 'cancelled', 'efficiency'] as $key) {
            $delta[$key] = $series[1][$key] - $series[0][$key];
        }

        return [
            'previous' => $previous, 'current' => $current, 'delta' => $delta,
            'movement' => $this->movement($previous['id'], $current['id'], $areaId),
        ];
    }

    private function movement(int $previousId, int $currentId, ?int $areaId): array
    {
        $params = ['previous' => $previousId, 'current' => $currentId];
        $currentArea = $previousArea = '';
        if ($areaId !== null) {
            $params['area'] = $areaId;
            $currentArea = ' AND c.maintenance_area_id = :area';
            $previousArea = ' AND p.maintenance_area_id = :area';
        }
        $new = $this->scalar("SELECT COUNT(*) FROM work_order_snapshots c LEFT JOIN work_order_snapshots p
            ON p.work_order_id = c.work_order_id AND p.report_import_id = :previous{$previousArea}
            WHERE c.report_import_id = :current{$currentArea} AND p.id IS NULL", $params);
        $absent = $this->scalar("SELECT COUNT(*) FROM work_order_snapshots p LEFT JOIN work_order_snapshots c
            ON c.work_order_id = p.work_order_id AND c.report_import_id = :current{$currentArea}
            WHERE p.report_import_id = :previous{$previousArea} AND c.id IS NULL", $params);
        $sql = "SELECT p.treated_status previous_status, c.treated_status current_status, COUNT(*) quantity
            FROM work_order_snapshots p JOIN work_order_snapshots c ON c.work_order_id = p.work_order_id
            WHERE p.report_import_id = :previous AND c.report_import_id = :current{$previousArea}{$currentArea}
            GROUP BY p.treated_status, c.treated_status";
        $rows = $this->snapshots->getConnection()->execute($sql, $params)->fetchAll('assoc');
        $transitions = [];
        $toCompleted = $toOpen = $toCancelled = 0;
        foreach ($rows as $row) {
            $item = ['from' => (string)$row['previous_status'], 'to' => (string)$row['current_status'], 'quantity' => (int)$row['quantity']];
            $transitions[] = $item;
            if ($item['from'] !== $item['to']) {
                $toCompleted += $item['to'] === WorkOrderStatusResolver::COMPLETED ? $item['quantity'] : 0;
                $toOpen += $item['to'] === WorkOrderStatusResolver::OPEN ? $item['quantity'] : 0;
                $toCancelled += $item['to'] === WorkOrderStatusResolver::CANCELLED ? $item['quantity'] : 0;
            }
        }

        return compact('new', 'absent', 'toCompleted', 'toOpen', 'toCancelled', 'transitions');
    }

    private function equipmentOccurrences(array $imports, ?int $areaId): array
    {
        if ($imports === []) {
            return [];
        }
        [$in, $params] = $this->idParams($imports);
        $where = "s.report_import_id IN ({$in}) AND s.equipment_code IS NOT NULL";
        if ($areaId !== null) {
            $where .= ' AND s.maintenance_area_id = :area';
            $params['area'] = $areaId;
        }
        $sql = "SELECT s.equipment_code, s.equipment_name, COUNT(DISTINCT s.work_order_id) quantity
            FROM work_order_snapshots s WHERE {$where} GROUP BY s.equipment_code, s.equipment_name
            ORDER BY quantity DESC, s.equipment_name ASC LIMIT 10";
        $rows = $this->snapshots->getConnection()->execute($sql, $params)->fetchAll('assoc');

        return array_map(static fn(array $row): array => ['key' => (string)$row['equipment_code'],
            'label' => (string)$row['equipment_code'] . ' — ' . (string)$row['equipment_name'], 'quantity' => (int)$row['quantity']], $rows);
    }

    private function idParams(array $imports): array
    {
        $tokens = $params = [];
        foreach ($imports as $index => $import) {
            $tokens[] = ':import' . $index;
            $params['import' . $index] = $import['id'];
        }
        return [implode(',', $tokens), $params];
    }

    private function scalar(string $sql, array $params): int
    {
        return (int)$this->snapshots->getConnection()->execute($sql, $params)->fetchColumn(0);
    }

    private function statusKey(string $status): string
    {
        return match ($status) {
            WorkOrderStatusResolver::OPEN => 'open', WorkOrderStatusResolver::COMPLETED => 'completed',
            WorkOrderStatusResolver::CANCELLED => 'cancelled',
            default => 'unknown',
        };
    }

    private function validDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = Date::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
