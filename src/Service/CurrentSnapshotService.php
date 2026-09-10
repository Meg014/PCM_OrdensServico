<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\FactoryLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use DateTimeInterface;

final class CurrentSnapshotService
{
    private Table $imports;
    private Table $snapshots;
    private Table $areas;
    private bool $importResolved = false;
    private ?object $resolvedImport = null;

    /** Resolves repositories used by all current-portfolio queries. */
    public function __construct()
    {
        $locator = FactoryLocator::get('Table');
        $this->imports = $locator->get('ReportImports');
        $this->snapshots = $locator->get('WorkOrderSnapshots');
        $this->areas = $locator->get('MaintenanceAreas');
    }

    /** Gets the latest successful import by its unique, monotonic ID. */
    public function currentImport(): ?object
    {
        if (!$this->importResolved) {
            $this->resolvedImport = $this->imports->find('latestSuccessful')->first();
            $this->importResolved = true;
        }

        return $this->resolvedImport;
    }

    /** @return array{report_date:string,import_id:int,updated_at:string}|null */
    public function currentVersion(): ?array
    {
        $import = $this->currentImport();
        if ($import === null) {
            return null;
        }

        return [
            'report_date' => $import->report_date->format('Y-m-d'),
            'import_id' => (int)$import->id,
            'updated_at' => $import->finished_at->format(DATE_ATOM),
        ];
    }

    /**
     * Returns when the current successful import effectively finished.
     */
    public function lastSuccessfulImportAt(): ?DateTimeInterface
    {
        $import = $this->currentImport();
        $finishedAt = $import?->finished_at;

        return $finishedAt instanceof DateTimeInterface ? $finishedAt : null;
    }

    /**
     * Builds one reusable current-snapshot query without loading associations.
     */
    public function query(?string $areaCode = null, ?string $status = null): ?SelectQuery
    {
        $import = $this->currentImport();
        if ($import === null) {
            return null;
        }
        $query = $this->snapshots->find('forImport', reportImportId: (int)$import->id);
        if ($areaCode !== null) {
            $query->innerJoinWith(
                'MaintenanceAreas',
                fn(SelectQuery $areas): SelectQuery => $areas
                    ->where(['MaintenanceAreas.source_code' => $areaCode]),
            );
        }
        if ($status !== null) {
            $query->where(['WorkOrderSnapshots.treated_status' => $status]);
        }

        return $query;
    }

    /**
     * Returns dynamic areas that occur in the current import.
     *
     * @return list<object>
     */
    public function areas(): array
    {
        $import = $this->currentImport();
        if ($import === null) {
            return [];
        }

        return $this->areas
            ->find('forImport', reportImportId: (int)$import->id)
            ->all()
            ->toList();
    }

    /**
     * Finds an area globally so an existing area with zero current rows is valid.
     */
    public function area(string $code): ?object
    {
        return $this->areas->find()
            ->where(['source_code' => $code, 'active' => true])
            ->first();
    }

    /** Returns one OS only when it belongs to the current operational snapshot. */
    public function order(int $snapshotId): ?object
    {
        $import = $this->currentImport();
        if ($snapshotId < 1 || $import === null) {
            return null;
        }

        return $this->snapshots->find()
            ->contain([
                'WorkOrders',
                'ReportImports',
                'MaintenanceAreas',
                'Equipment',
                'Services',
                'CostCenters',
            ])
            ->where([
                'WorkOrderSnapshots.id' => $snapshotId,
                'WorkOrderSnapshots.report_import_id' => (int)$import->id,
            ])
            ->first();
    }
}
