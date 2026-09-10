<?php
declare(strict_types=1);

namespace App\Service\Import;

use App\Service\WorkOrderStatusResolver;

final class TotvsRowMapper
{
    public function __construct(
        private readonly TotvsValueNormalizer $normalizer = new TotvsValueNormalizer(),
        private readonly WorkOrderStatusResolver $statusResolver = new WorkOrderStatusResolver(),
    ) {
    }

    /** @param list<mixed> $v */
    public function map(array $v, int $rowNumber): array
    {
        $maintenanceActualStart = $this->normalizer->combineDateTime($v[34], $v[35]);
        $raw = [];
        foreach (TotvsHeaderValidator::EXPECTED as $index => $header) {
            $raw[sprintf('%02d_%s', $index + 1, $header)] = $v[$index] ?? null;
        }
        $data = [
            'branch_code' => $this->normalizer->code($v[0]), 'source_order_number' => $this->normalizer->code($v[1]),
            'maintenance_plan_code' => $this->normalizer->code($v[2]), 'origin_date' => $this->normalizer->date($v[3]),
            'order_type' => $this->normalizer->text($v[4]), 'equipment_code' => $this->normalizer->code($v[5]),
            'equipment_name' => $this->normalizer->text($v[6]), 'service_code' => $this->normalizer->code($v[7]),
            'service_name' => $this->normalizer->text($v[8]), 'sequence_code' => $this->normalizer->code($v[9]),
            'maintenance_type' => $this->normalizer->code($v[10]), 'maintenance_area_code' => $this->normalizer->code($v[11]),
            'cost_center_code' => $this->normalizer->code($v[12]), 'counter_value' => $this->normalizer->decimal($v[13]),
            'counter_time_1_raw' => $this->normalizer->text($v[14]), 'labor_cost' => $this->normalizer->decimal($v[15]),
            'replacement_cost' => $this->normalizer->decimal($v[16]), 'material_cost' => $this->normalizer->decimal($v[17]),
            'substitute_cost' => $this->normalizer->decimal($v[18]), 'third_party_cost' => $this->normalizer->decimal($v[19]),
            'last_maintenance_date' => $this->normalizer->date($v[20]), 'maintenance_counter' => $this->normalizer->decimal($v[21]),
            'general_planned_start' => $this->normalizer->combineDateTime($v[22], $v[23]), 'general_planned_end' => $this->normalizer->combineDateTime($v[24], $v[25]),
            'general_actual_start' => $this->normalizer->combineDateTime($v[26], $v[27]), 'general_actual_end' => $this->normalizer->combineDateTime($v[28], $v[29]),
            'maintenance_planned_start' => $this->normalizer->combineDateTime($v[30], $v[31]), 'maintenance_planned_end' => $this->normalizer->combineDateTime($v[32], $v[33]),
            'maintenance_actual_start' => $maintenanceActualStart, 'maintenance_actual_end' => $this->normalizer->combineDateTime($v[36], $v[37]),
            'position_counter' => $this->normalizer->decimal($v[38]), 'counter_value_2' => $this->normalizer->decimal($v[39]),
            'finished_raw' => $this->normalizer->text($v[40]), 'changed_by' => $this->normalizer->text($v[41]),
            'priority_code' => $this->normalizer->code($v[42]), 'counter_time_2_raw' => $this->normalizer->text($v[43]),
            'source_situation' => $this->normalizer->text($v[44]), 'work_center_code' => $this->normalizer->code($v[45]),
            'return_type' => $this->normalizer->code($v[46]), 'parent_order_number' => $this->normalizer->code($v[47]),
            'parent_equipment_code' => $this->normalizer->code($v[48]), 'replacement_order_number' => $this->normalizer->code($v[49]),
            'service_request' => $this->normalizer->code($v[50]), 'irregularity_code' => $this->normalizer->code($v[51]),
            'third_party_raw' => $this->normalizer->text($v[52]), 'rework_quantity' => $this->normalizer->decimal($v[53]),
            'rework_reason' => $this->normalizer->text($v[54]), 'tool_cost' => $this->normalizer->decimal($v[55]),
            'original_order_number' => $this->normalizer->code($v[56]), 'source_row_number' => $rowNumber, 'raw_payload' => $raw,
        ];
        $data['treated_status'] = $this->statusResolver->resolve(
            $data['source_situation'],
            $data['finished_raw'],
        );
        $data['status_rule_version'] = WorkOrderStatusResolver::VERSION;
        $data['row_hash'] = hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $data;
    }

    public function slug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '';

        return trim($slug, '-') ?: 'sem-area';
    }
}
