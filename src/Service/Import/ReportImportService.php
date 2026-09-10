<?php
declare(strict_types=1);

namespace App\Service\Import;

use App\Service\WorkOrderStatusResolver;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\FactoryLocator;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class ReportImportService
{
    private Table $imports;
    private Table $areas;
    private Table $equipment;
    private Table $services;
    private Table $costCenters;
    private Table $workOrders;
    private Table $snapshots;

    /** Creates the importer with its parsing collaborators. */
    public function __construct(
        private readonly TotvsReportReader $reportReader = new TotvsReportReader(),
        private readonly TotvsRowMapper $rowMapper = new TotvsRowMapper(),
    ) {
        $locator = FactoryLocator::get('Table');
        $this->imports = $locator->get('ReportImports');
        $this->areas = $locator->get('MaintenanceAreas');
        $this->equipment = $locator->get('Equipment');
        $this->services = $locator->get('Services');
        $this->costCenters = $locator->get('CostCenters');
        $this->workOrders = $locator->get('WorkOrders');
        $this->snapshots = $locator->get('WorkOrderSnapshots');
    }

    /** Imports one immutable full snapshot. */
    public function import(string $path, ?string $originalName = null, ?Date $reportDate = null): object
    {
        $originalName ??= basename($path);
        $reportDate ??= $this->reportDateFromFile($path);
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Não foi possível calcular o hash do arquivo.');
        }
        $existing = $this->imports->find()->where(['file_hash' => $hash])->first();
        if ($existing !== null && in_array($existing->status, ['success', 'processing'], true)) {
            throw new DuplicateReportException($hash);
        }
        $sheetName = (string)Configure::read('Pcm.reports.sheet', 'sclxd280');
        $data = ['file_name' => $originalName, 'file_path' => $path, 'report_date' => $reportDate, 'file_hash' => $hash,
            'file_size' => filesize($path) ?: 0, 'sheet_name' => $sheetName, 'status' => 'processing', 'started_at' => DateTime::now(),
            'finished_at' => null, 'error_message' => null, 'rows_read' => 0, 'rows_imported' => 0, 'rows_rejected' => 0,
            'warning_count' => 0, 'error_count' => 0];
        $import = $existing === null ? $this->imports->newEntity($data) : $existing->patch($data);
        $this->imports->saveOrFail($import);

        try {
            $workbook = $this->reportReader->read($path, $sheetName);
            $mappedRows = [];
            $seen = [];
            foreach ($workbook['rows'] as $row) {
                $mapped = $this->rowMapper->map($row['values'], $row['source_row_number']);
                if (!$mapped['branch_code'] || !$mapped['source_order_number']) {
                    throw new RuntimeException(sprintf(
                        'Linha %d sem filial ou número da OS.',
                        $row['source_row_number'],
                    ));
                }
                $key = $mapped['branch_code'] . '|' . $mapped['source_order_number'];
                if (isset($seen[$key])) {
                    throw new RuntimeException(sprintf('OS duplicada no arquivo nas linhas %d e %d: %s.', $seen[$key], $row['source_row_number'], $mapped['source_order_number']));
                }
                $seen[$key] = $row['source_row_number'];
                $mappedRows[] = $mapped;
            }
            $connection = ConnectionManager::get('default');
            $connection->transactional(function () use ($mappedRows, $workbook, $import, $reportDate): void {
                foreach ($mappedRows as $data) {
                    $equipment = $this->dimension($this->equipment, ['branch_code' => $data['branch_code'], 'source_code' => $data['equipment_code']], ['name' => $data['equipment_name'] ?? $data['equipment_code']]);
                    $area = $this->dimension($this->areas, ['source_code' => $data['maintenance_area_code']], ['display_name' => $data['maintenance_area_code'], 'slug' => $this->rowMapper->slug($data['maintenance_area_code'] ?? 'sem-area')]);
                    $service = $this->dimension($this->services, ['source_code' => $data['service_code']], ['name' => $data['service_name'] ?? $data['service_code'], 'classification_version' => 1]);
                    $costCenter = $this->dimension($this->costCenters, ['source_code' => $data['cost_center_code']], []);
                    $workOrder = $this->workOrders->find()->where(['branch_code' => $data['branch_code'], 'source_order_number' => $data['source_order_number']])->first();
                    if (!$workOrder) {
                        $workOrder = $this->workOrders->newEntity(['branch_code' => $data['branch_code'], 'source_order_number' => $data['source_order_number'], 'first_seen_report_date' => $reportDate]);
                    }
                    $workOrder->patch(['equipment_id' => $equipment?->id, 'last_seen_report_date' => $reportDate]);
                    $this->workOrders->saveOrFail($workOrder);
                    $data += ['work_order_id' => $workOrder->id, 'report_import_id' => $import->id, 'report_date' => $reportDate,
                        'maintenance_area_id' => $area?->id, 'equipment_id' => $equipment?->id, 'service_id' => $service?->id,
                        'cost_center_id' => $costCenter?->id, 'validation_warnings' => $workbook['warnings'] ?: null];
                    $snapshot = $this->snapshots->newEntity($data);
                    $snapshot->set('raw_payload', $data['raw_payload']);
                    $snapshot->set('validation_warnings', $data['validation_warnings']);
                    $this->snapshots->saveOrFail($snapshot);
                }
            });
            $import->patch(['status' => 'success', 'rows_read' => count($mappedRows), 'rows_imported' => count($mappedRows),
                'rows_rejected' => 0, 'warning_count' => count($workbook['warnings']), 'error_count' => 0,
                'sheet_name' => $workbook['sheet_name'], 'header_signature' => $workbook['header_signature'],
                'metadata' => ['status_rule_version' => WorkOrderStatusResolver::VERSION], 'finished_at' => DateTime::now()]);
            $import->set('metadata', ['status_rule_version' => WorkOrderStatusResolver::VERSION]);
            $this->imports->saveOrFail($import);

            return $import;
        } catch (Throwable $exception) {
            $import->patch(['status' => 'failed', 'error_count' => 1, 'error_message' => mb_substr($exception->getMessage(), 0, 65000), 'finished_at' => DateTime::now()]);
            $this->imports->save($import);
            throw $exception;
        }
    }

    /** Reports whether content is already effective or currently being processed. */
    public function hasImportedHash(string $hash): bool
    {
        return $this->imports->find()
            ->where(['file_hash' => $hash, 'status IN' => ['success', 'processing']])
            ->count() > 0;
    }

    /** Uses the export file timestamp without deriving snapshot identity from its name. */
    private function reportDateFromFile(string $path): Date
    {
        $modifiedAt = filemtime($path);
        if ($modifiedAt === false) {
            throw new RuntimeException('Não foi possível determinar a data do arquivo.');
        }
        $timezone = new DateTimeZone((string)Configure::read('App.displayTimezone', 'America/Sao_Paulo'));

        return new Date((new DateTimeImmutable('@' . $modifiedAt))->setTimezone($timezone));
    }

    /** Finds or creates one optional source dimension. */
    private function dimension(Table $table, array $conditions, array $defaults): ?object
    {
        if (in_array(null, $conditions, true) || in_array('', $conditions, true)) {
            return null;
        }
        $entity = $table->find()->where($conditions)->first();
        if (!$entity) {
            $entity = $table->newEntity($conditions + $defaults + ['active' => true]);
            $table->saveOrFail($entity);
        }

        return $entity;
    }
}
