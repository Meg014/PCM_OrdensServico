<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Service\PcmServiceClassifier;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use DateTimeInterface;

class WorkOrderSnapshotsTable extends Table
{
    /** Applies shared, bound filters to current-snapshot queries. */
    public function findFiltered(SelectQuery $query, array $filters): SelectQuery
    {
        $map = ['status' => 'treated_status', 'equipment' => 'equipment_code', 'service' => 'service_code',
            'service_name' => 'service_name', 'cost_center' => 'cost_center_code',
            'maintenance_type' => 'maintenance_type', 'area' => 'maintenance_area_code'];
        foreach ($map as $key => $column) {
            if (isset($filters[$key])) {
                $query->where([$this->aliasField($column) => $filters[$key]]);
            }
        }
        if (isset($filters['q'])) {
            $term = '%' . $filters['q'] . '%';
            $query->where(['OR' => ['source_order_number LIKE' => $term, 'equipment_code LIKE' => $term,
                'equipment_name LIKE' => $term, 'service_name LIKE' => $term]]);
        }
        if (isset($filters['classification'])) {
            (new PcmServiceClassifier())->applyFilter($query, $filters['classification']);
        }

        return $query;
    }

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('work_order_snapshots');
        $this->setPrimaryKey('id');
        $this->getSchema()->setColumnType('raw_payload', 'json')->setColumnType('validation_warnings', 'json');
        $this->addBehavior('Timestamp');
        $this->belongsTo('WorkOrders');
        $this->belongsTo('ReportImports');
        $this->belongsTo('MaintenanceAreas');
        $this->belongsTo('Equipment');
        $this->belongsTo('Services');
        $this->belongsTo('CostCenters');
    }

    public function findForReportDate(SelectQuery $query, DateTimeInterface|string $date): SelectQuery
    {
        return $query->where([$this->aliasField('report_date') => $date]);
    }

    public function findByArea(SelectQuery $query, string $sourceCode): SelectQuery
    {
        return $query->matching('MaintenanceAreas', fn(SelectQuery $q): SelectQuery => $q->where(['MaintenanceAreas.source_code' => $sourceCode]));
    }

    /**
     * Restricts a query to one immutable report import.
     */
    public function findForImport(SelectQuery $query, int $reportImportId): SelectQuery
    {
        return $query->where([$this->aliasField('report_import_id') => $reportImportId]);
    }

    /**
     * Returns grouped status counts with an optional area filter.
     *
     * @return array<string, int>
     */
    public function statusCounts(int $reportImportId, ?int $maintenanceAreaId = null): array
    {
        $query = $this->find()
            ->select([
                'treated_status',
                'quantity' => $this->find()->func()->count('*'),
            ])
            ->where(['report_import_id' => $reportImportId])
            ->groupBy('treated_status')
            ->disableHydration();
        if ($maintenanceAreaId !== null) {
            $query->where(['maintenance_area_id' => $maintenanceAreaId]);
        }

        $counts = [];
        foreach ($query as $row) {
            $counts[(string)$row['treated_status']] = (int)$row['quantity'];
        }

        return $counts;
    }

    /**
     * Counts open work orders by the exact Tipo Manut. value imported from TOTVS.
     *
     * @return array<string, int>
     */
    public function openMaintenanceTypeCounts(int $reportImportId, ?int $maintenanceAreaId = null): array
    {
        $query = $this->find()
            ->select([
                'maintenance_type',
                'quantity' => $this->find()->func()->count('*'),
            ])
            ->where([
                'report_import_id' => $reportImportId,
                'treated_status' => 'EM ABERTO',
            ])
            ->groupBy('maintenance_type')
            ->disableHydration();
        if ($maintenanceAreaId !== null) {
            $query->where(['maintenance_area_id' => $maintenanceAreaId]);
        }

        $counts = [];
        foreach ($query as $row) {
            $counts[strtoupper(trim((string)($row['maintenance_type'] ?? '')))] = (int)$row['quantity'];
        }

        return $counts;
    }
}
