<?php
declare(strict_types=1);

namespace App\Service\Protheus\Presentation;

/** Pure projection: no repository, database, config, routing or network dependency. */
final class OrderDetailPresenter
{
    private const PCM_FIELDS = [
        'source_order_number', 'branch_code', 'equipment_code', 'equipment_name',
        'service_code', 'service_name', 'maintenance_type', 'maintenance_area_code',
        'cost_center_code', 'origin_date', 'general_planned_start', 'general_planned_end',
        'general_actual_start', 'general_actual_end', 'maintenance_planned_start',
        'maintenance_planned_end', 'maintenance_actual_start', 'maintenance_actual_end',
    ];

    /**
     * Accepts snapshot->toArray(). PCM remains authoritative.
     * Date objects are preserved for the existing PcmTime helper. No HTML is generated.
     * A supplied supplement is ignored unless availability is explicitly Available.
     */
    public function present(
        array $snapshot,
        ?OrderSupplement $supplement = null,
        Availability $availability = Availability::Disabled,
    ): array {
        $pcm = [];
        foreach (self::PCM_FIELDS as $field) {
            $pcm[$field] = $snapshot[$field] ?? null;
        }
        if ($availability === Availability::Available && (
            $supplement === null
            || $supplement->number === ''
            || $supplement->number !== rtrim((string)$pcm['source_order_number'])
            || $supplement->branch === null
            || $supplement->branch !== rtrim((string)$pcm['branch_code'])
        )) {
            $availability = Availability::Unavailable;
        }
        $available = $availability === Availability::Available;

        return [
            'pcm' => $pcm,
            'protheus' => [
                'number' => $available ? $supplement->number : null,
                'branch' => $available ? $supplement->branch : null,
                'state' => $availability->value,
                'message' => match ($availability) {
                    Availability::Disabled => null,
                    Availability::Unavailable => 'Detalhes do Protheus temporariamente indisponíveis.',
                    Availability::NotFound => 'Dados complementares não encontrados.',
                    Availability::Available => null,
                },
                'origin_date' => $available ? $supplement->originDate : null,
                'description' => $available ? $supplement->description : null,
                'maintenance' => $available ? $this->project([$supplement->maintenance], [
                    'equipment_code', 'equipment_name', 'service_code', 'service_name', 'area', 'cost_center',
                    'planned_start_date', 'planned_start_time', 'planned_end_date', 'planned_end_time',
                    'actual_start_date', 'actual_start_time', 'actual_end_date', 'actual_end_time',
                    'general_planned_start_date', 'general_planned_start_time', 'general_planned_end_date', 'general_planned_end_time',
                    'general_actual_start_date', 'general_actual_start_time', 'general_actual_end_date', 'general_actual_end_time',
                ])[0] : [],
                'labor' => $available ? $this->project($supplement->labor, [
                    'professional', 'code', 'date', 'end_date', 'start_time', 'end_time', 'hours', 'unit',
                ]) : [],
                'materials' => $available ? $this->project($supplement->materials, [
                    'code', 'description', 'quantity', 'unit', 'used_date', 'used_time',
                ]) : [],
            ],
        ];
    }

    private function project(array $rows, array $fields): array
    {
        $result = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ($fields as $field) {
                $item[$field] = isset($row[$field]) && is_string($row[$field]) ? $row[$field] : null;
            }
            $result[] = $item;
        }

        return $result;
    }
}
