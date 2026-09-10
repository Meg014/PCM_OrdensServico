<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePcmFoundation extends BaseMigration
{
    public function change(): void
    {
        $this->table('report_imports')
            ->addColumn('file_name', 'string', ['limit' => 255])
            ->addColumn('file_path', 'string', ['limit' => 1024])
            ->addColumn('report_date', 'date')
            ->addColumn('file_hash', 'string', ['limit' => 64])
            ->addColumn('file_size', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('sheet_name', 'string', ['limit' => 100, 'default' => 'sclxd280'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'processing'])
            ->addColumn('rows_read', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rows_imported', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rows_rejected', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('warning_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('error_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('started_at', 'datetime', ['precision' => 6])
            ->addColumn('finished_at', 'datetime', ['precision' => 6, 'null' => true])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addColumn('header_signature', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('metadata', 'json', ['null' => true])
            ->addTimestamps()
            ->addIndex(['file_hash'], ['unique' => true, 'name' => 'uq_report_imports_hash'])
            ->addIndex(['report_date', 'status'], ['name' => 'ix_report_imports_date_status'])
            ->create();

        $this->table('maintenance_areas')
            ->addColumn('source_code', 'string', ['limit' => 30])
            ->addColumn('display_name', 'string', ['limit' => 100])
            ->addColumn('slug', 'string', ['limit' => 120])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addTimestamps()->addIndex(['source_code'], ['unique' => true])->addIndex(['slug'], ['unique' => true])->create();

        $this->table('equipment')
            ->addColumn('branch_code', 'string', ['limit' => 10])
            ->addColumn('source_code', 'string', ['limit' => 100])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('is_generic', 'boolean', ['default' => false])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addTimestamps()->addIndex(['branch_code', 'source_code'], ['unique' => true, 'name' => 'uq_equipment_source'])->create();

        $this->table('services')
            ->addColumn('source_code', 'string', ['limit' => 30])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('pcm_category', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('classification_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addTimestamps()->addIndex(['source_code'], ['unique' => true])->create();

        $this->table('cost_centers')
            ->addColumn('source_code', 'string', ['limit' => 30])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addTimestamps()->addIndex(['source_code'], ['unique' => true])->create();

        $this->table('work_orders')
            ->addColumn('branch_code', 'string', ['limit' => 10])
            ->addColumn('source_order_number', 'string', ['limit' => 30])
            ->addColumn('equipment_id', 'integer', ['null' => true])
            ->addColumn('first_seen_report_date', 'date')->addColumn('last_seen_report_date', 'date')
            ->addTimestamps()
            ->addIndex(['branch_code', 'source_order_number'], ['unique' => true, 'name' => 'uq_work_orders_source'])
            ->addForeignKey('equipment_id', 'equipment', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])->create();

        $this->table('work_order_snapshots')
            ->addColumn('work_order_id', 'integer')->addColumn('report_import_id', 'integer')
            ->addColumn('report_date', 'date')->addColumn('maintenance_area_id', 'integer', ['null' => true])
            ->addColumn('equipment_id', 'integer', ['null' => true])->addColumn('service_id', 'integer', ['null' => true])
            ->addColumn('cost_center_id', 'integer', ['null' => true])
            ->addColumn('branch_code', 'string', ['limit' => 10])->addColumn('source_order_number', 'string', ['limit' => 30])
            ->addColumn('maintenance_plan_code', 'string', ['limit' => 30, 'null' => true])->addColumn('origin_date', 'date', ['null' => true])
            ->addColumn('order_type', 'string', ['limit' => 30, 'null' => true])->addColumn('equipment_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('equipment_name', 'string', ['limit' => 255, 'null' => true])->addColumn('service_code', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('service_name', 'string', ['limit' => 255, 'null' => true])->addColumn('sequence_code', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('maintenance_type', 'string', ['limit' => 30, 'null' => true])->addColumn('maintenance_area_code', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('cost_center_code', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('counter_value', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])->addColumn('counter_time_1_raw', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('labor_cost', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true])->addColumn('replacement_cost', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true])
            ->addColumn('material_cost', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true])->addColumn('substitute_cost', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true])
            ->addColumn('third_party_cost', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true])->addColumn('last_maintenance_date', 'date', ['null' => true])
            ->addColumn('maintenance_counter', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('general_planned_start', 'datetime', ['null' => true])->addColumn('general_planned_end', 'datetime', ['null' => true])
            ->addColumn('general_actual_start', 'datetime', ['null' => true])->addColumn('general_actual_end', 'datetime', ['null' => true])
            ->addColumn('maintenance_planned_start', 'datetime', ['null' => true])->addColumn('maintenance_planned_end', 'datetime', ['null' => true])
            ->addColumn('maintenance_actual_start', 'datetime', ['null' => true])->addColumn('maintenance_actual_end', 'datetime', ['null' => true])
            ->addColumn('position_counter', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])->addColumn('counter_value_2', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('finished_raw', 'string', ['limit' => 20, 'null' => true])->addColumn('changed_by', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('priority_code', 'string', ['limit' => 30, 'null' => true])->addColumn('counter_time_2_raw', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('source_situation', 'string', ['limit' => 50, 'null' => true])->addColumn('work_center_code', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('return_type', 'string', ['limit' => 30, 'null' => true])->addColumn('parent_order_number', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('parent_equipment_code', 'string', ['limit' => 100, 'null' => true])->addColumn('replacement_order_number', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('service_request', 'string', ['limit' => 100, 'null' => true])->addColumn('irregularity_code', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('third_party_raw', 'string', ['limit' => 20, 'null' => true])->addColumn('rework_quantity', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('rework_reason', 'string', ['limit' => 255, 'null' => true])->addColumn('tool_cost', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true])
            ->addColumn('original_order_number', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('treated_status', 'string', ['limit' => 20])->addColumn('status_rule_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('raw_payload', 'json')->addColumn('row_number', 'integer', ['signed' => false])->addColumn('row_hash', 'string', ['limit' => 64])
            ->addColumn('validation_warnings', 'json', ['null' => true])->addTimestamps()
            ->addIndex(['report_import_id', 'work_order_id'], ['unique' => true, 'name' => 'uq_snapshot_import_order'])
            ->addIndex(['report_date', 'treated_status'], ['name' => 'ix_snapshot_date_status'])
            ->addIndex(['maintenance_area_id', 'report_date'], ['name' => 'ix_snapshot_area_date'])
            ->addIndex(['equipment_id', 'report_date'], ['name' => 'ix_snapshot_equipment_date'])
            ->addIndex(['service_id', 'report_date'], ['name' => 'ix_snapshot_service_date'])
            ->addForeignKey('work_order_id', 'work_orders', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->addForeignKey('report_import_id', 'report_imports', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->addForeignKey('maintenance_area_id', 'maintenance_areas', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('equipment_id', 'equipment', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('service_id', 'services', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('cost_center_id', 'cost_centers', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])->create();
    }
}
