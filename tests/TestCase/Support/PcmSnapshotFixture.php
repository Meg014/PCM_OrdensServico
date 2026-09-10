<?php
declare(strict_types=1);

namespace App\Test\TestCase\Support;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;

trait PcmSnapshotFixture
{
    protected static function connection(): ConnectionInterface
    {
        return ConnectionManager::get('test');
    }

    protected static function clearPcmData(): void
    {
        $connection = static::connection();
        foreach ([
            'work_order_snapshots', 'work_orders', 'report_imports',
            'maintenance_areas', 'equipment', 'services', 'cost_centers',
        ] as $table) {
            $connection->execute("DELETE FROM {$table}");
        }
    }

    /**
     * Seeds two successful reports; the newer one reproduces the validated KPI totals.
     */
    protected static function seedValidatedSnapshot(): array
    {
        static::clearPcmData();
        $connection = static::connection();
        $connection->insert('maintenance_areas', [
            'source_code' => 'MECANI', 'display_name' => 'Mecânica', 'slug' => 'mecani', 'active' => 1,
            'created' => '2026-08-21 08:00:00', 'updated' => '2026-08-21 08:00:00',
        ]);
        $mechanicalId = (int)$connection->getDriver()->lastInsertId();
        $connection->insert('maintenance_areas', [
            'source_code' => 'ELETRI', 'display_name' => 'Elétrica', 'slug' => 'eletri', 'active' => 1,
            'created' => '2026-08-21 08:00:00', 'updated' => '2026-08-21 08:00:00',
        ]);
        $electricalId = (int)$connection->getDriver()->lastInsertId();

        $equipmentIds = [];
        foreach ([['EQ-M1', 'Bomba Mecânica'], ['EQ-M2', 'Redutor Mecânico'], ['EQ-E1', 'Motor Elétrico']] as [$code, $name]) {
            $connection->insert('equipment', ['branch_code' => '1', 'source_code' => $code, 'name' => $name,
                'is_generic' => 0, 'active' => 1, 'created' => '2026-08-21 08:00:00', 'updated' => '2026-08-21 08:00:00']);
            $equipmentIds[] = (int)$connection->getDriver()->lastInsertId();
        }
        $serviceIds = [];
        foreach ([['CORMEC', 'CORRETIVA MECANICA', 'corrective'], ['PREVEN', 'PREVENTIVA MECANICA', 'preventive'], ['ELEPRE', 'PREVENTIVA ELETRICA', 'preventive']] as [$code, $name, $category]) {
            $connection->insert('services', ['source_code' => $code, 'name' => $name, 'pcm_category' => $category,
                'classification_version' => 1, 'active' => 1, 'created' => '2026-08-21 08:00:00', 'updated' => '2026-08-21 08:00:00']);
            $serviceIds[] = (int)$connection->getDriver()->lastInsertId();
        }
        $costCenterIds = [];
        foreach (['3101001', '4101002'] as $code) {
            $connection->insert('cost_centers', ['source_code' => $code, 'active' => 1,
                'created' => '2026-08-21 08:00:00', 'updated' => '2026-08-21 08:00:00']);
            $costCenterIds[] = (int)$connection->getDriver()->lastInsertId();
        }

        $oldImportId = static::insertImport('2026-08-20', str_repeat('a', 64));
        $currentImportId = static::insertImport('2026-08-21', str_repeat('b', 64));
        for ($index = 1; $index <= 575; $index++) {
            $connection->insert('work_orders', [
                'branch_code' => '1',
                'source_order_number' => (string)(4000 + $index),
                'first_seen_report_date' => '2026-08-20',
                'last_seen_report_date' => '2026-08-21',
                'created' => '2026-08-21 08:00:00',
                'updated' => '2026-08-21 08:00:00',
            ]);
            $workOrderId = (int)$connection->getDriver()->lastInsertId();
            if ($index === 1) {
                static::insertSnapshot($oldImportId, $workOrderId, $mechanicalId, '2026-08-20', 'EM ABERTO', 1, $equipmentIds, $serviceIds, $costCenterIds);
            }
            $status = $index <= 371 ? 'FECHADA' : ($index <= 529 ? 'EM ABERTO' : 'CANCELADA');
            $areaId = $index <= 375 ? $mechanicalId : $electricalId;
            static::insertSnapshot($currentImportId, $workOrderId, $areaId, '2026-08-21', $status, $index, $equipmentIds, $serviceIds, $costCenterIds);
        }

        return compact('oldImportId', 'currentImportId', 'mechanicalId', 'electricalId');
    }

    /** Adds a controlled third report without touching the real database. */
    protected static function seedHistoricalComparison(): array
    {
        $ids = static::seedValidatedSnapshot();
        $connection = static::connection();
        $nextImportId = static::insertImport('2026-08-22', str_repeat('c', 64));
        $rows = $connection->execute(
            'SELECT * FROM work_order_snapshots WHERE report_import_id = :import ORDER BY id',
            ['import' => $ids['currentImportId']],
        )->fetchAll('assoc');
        foreach ($rows as $row) {
            unset($row['id']);
            if ($row['source_order_number'] === '4002') {
                continue;
            }
            $row['report_import_id'] = $nextImportId;
            $row['report_date'] = '2026-08-22';
            $row['row_hash'] = hash('sha256', "{$nextImportId}|{$row['work_order_id']}");
            $row['created'] = $row['updated'] = '2026-08-22 09:37:00';
            if ($row['source_order_number'] === '4001') {
                $row['treated_status'] = 'EM ABERTO';
                $row['finished_raw'] = 'Não';
            } elseif ($row['source_order_number'] === '4373') {
                $row['treated_status'] = 'FECHADA';
                $row['finished_raw'] = 'Sim';
                $row['maintenance_actual_start'] = '2026-08-22 08:00:00';
            } elseif ($row['source_order_number'] === '4376') {
                $row['treated_status'] = 'CANCELADA';
                $row['source_situation'] = 'Cancelada';
            }
            $connection->insert('work_order_snapshots', $row);
        }
        $connection->insert('work_orders', ['branch_code' => '1', 'source_order_number' => '4999',
            'first_seen_report_date' => '2026-08-22', 'last_seen_report_date' => '2026-08-22',
            'created' => '2026-08-22 09:37:00', 'updated' => '2026-08-22 09:37:00']);
        $newWorkOrderId = (int)$connection->getDriver()->lastInsertId();
        $newRow = $rows[0];
        unset($newRow['id']);
        $newRow['work_order_id'] = $newWorkOrderId;
        $newRow['report_import_id'] = $nextImportId;
        $newRow['report_date'] = '2026-08-22';
        $newRow['source_order_number'] = '4999';
        $newRow['treated_status'] = 'EM ABERTO';
        $newRow['finished_raw'] = 'Não';
        $newRow['maintenance_actual_start'] = null;
        $newRow['source_row_number'] = 576;
        $newRow['row_hash'] = hash('sha256', "{$nextImportId}|{$newWorkOrderId}");
        $newRow['created'] = $newRow['updated'] = '2026-08-22 09:37:00';
        $connection->insert('work_order_snapshots', $newRow);

        return $ids + compact('nextImportId', 'newWorkOrderId');
    }

    private static function insertImport(string $date, string $hash): int
    {
        $connection = static::connection();
        $connection->insert('report_imports', [
            'file_name' => "Relatorio_OS_{$date}.xlsx",
            'file_path' => "test/Relatorio_OS_{$date}.xlsx",
            'report_date' => $date,
            'file_hash' => $hash,
            'file_size' => 1,
            'sheet_name' => 'sclxd280',
            'status' => 'success',
            'started_at' => "{$date} 08:00:00",
            'finished_at' => "{$date} 09:37:00",
            'created' => "{$date} 08:00:00",
            'updated' => "{$date} 08:00:00",
        ]);

        return (int)$connection->getDriver()->lastInsertId();
    }

    private static function insertSnapshot(
        int $importId,
        int $workOrderId,
        int $areaId,
        string $date,
        string $status,
        int $row,
        array $equipmentIds,
        array $serviceIds,
        array $costCenterIds,
    ): void {
        $equipmentIndex = $row <= 375 ? ($row <= 250 ? 0 : 1) : 2;
        $serviceIndex = $row <= 375 ? ($row <= 220 ? 0 : 1) : 2;
        $equipmentCodes = ['EQ-M1', 'EQ-M2', 'EQ-E1'];
        $equipmentNames = ['Bomba Mecânica', 'Redutor Mecânico', 'Motor Elétrico'];
        $serviceCodes = ['CORMEC', 'PREVEN', 'ELEPRE'];
        $serviceNames = ['CORRETIVA MECANICA', 'PREVENTIVA MECANICA', 'PREVENTIVA ELETRICA'];
        static::connection()->insert('work_order_snapshots', [
            'work_order_id' => $workOrderId,
            'report_import_id' => $importId,
            'report_date' => $date,
            'maintenance_area_id' => $areaId,
            'equipment_id' => $equipmentIds[$equipmentIndex],
            'service_id' => $serviceIds[$serviceIndex],
            'cost_center_id' => $costCenterIds[$row % 2],
            'branch_code' => '1',
            'source_order_number' => (string)(4000 + $row),
            'equipment_code' => $equipmentCodes[$equipmentIndex],
            'equipment_name' => $equipmentNames[$equipmentIndex],
            'service_code' => $serviceCodes[$serviceIndex],
            'service_name' => $serviceNames[$serviceIndex],
            'maintenance_type' => match ($row % 4) {
                0 => 'PRE',
                1 => 'COR',
                2 => 'MEL',
                default => '',
            },
            'maintenance_area_code' => $row <= 375 ? 'MECANI' : 'ELETRI',
            'cost_center_code' => $row % 2 ? '4101002' : '3101001',
            'source_situation' => $status === 'CANCELADA' ? 'Cancelada' : 'Liberado',
            'finished_raw' => $status === 'FECHADA' ? 'Sim' : 'Não',
            'maintenance_planned_start' => "{$date} 07:00:00",
            'maintenance_actual_start' => $status === 'FECHADA' || ($status === 'EM ABERTO' && $row % 2 === 0)
                ? "{$date} 08:00:00"
                : null,
            'maintenance_actual_end' => $status === 'FECHADA' ? "{$date} 09:00:00" : null,
            'treated_status' => $status,
            'status_rule_version' => 4,
            'raw_payload' => '{}',
            'source_row_number' => $row,
            'row_hash' => hash('sha256', "{$importId}|{$row}"),
            'created' => "{$date} 08:00:00",
            'updated' => "{$date} 08:00:00",
        ]);
    }
}
