<?php
declare(strict_types=1);

namespace App\Service\Protheus\Presentation;

/** Pure adapter using the fields validated against the company's Protheus data. */
final class OrderSupplementMapper
{
    public function map(array $order): OrderSupplement
    {
        $labor = [];
        foreach ($order['mao_de_obra'] ?? [] as $row) {
            if (rtrim((string)($row['apontamento']['TL_TIPOREG'] ?? '')) !== 'M') {
                continue;
            }
            $labor[] = [
                'professional' => $this->text($row['profissional']['T1_NOME'] ?? null),
                'code' => $this->text($row['apontamento']['TL_CODIGO'] ?? null),
                'date' => $this->date($row['apontamento']['TL_DTINICI'] ?? null),
                'end_date' => $this->date($row['apontamento']['TL_DTFIM'] ?? null),
                'start_time' => $this->time($row['apontamento']['TL_HOINICI'] ?? null),
                'end_time' => $this->time($row['apontamento']['TL_HOFIM'] ?? null),
                'hours' => $this->text($row['apontamento']['TL_QUANTID'] ?? null),
                'unit' => $this->text($row['apontamento']['TL_UNIDADE'] ?? null),
            ];
        }
        $materials = [];
        foreach ($order['materiais'] ?? [] as $row) {
            if (rtrim((string)($row['apontamento']['TL_TIPOREG'] ?? '')) !== 'P') {
                continue;
            }
            $materials[] = [
                'code' => $this->text($row['apontamento']['TL_CODIGO'] ?? null),
                'description' => $this->text($row['produto']['B1_DESC'] ?? null),
                'quantity' => $this->text($row['apontamento']['TL_QUANTID'] ?? null),
                'unit' => $this->text($row['apontamento']['TL_UNIDADE'] ?? null),
                'used_date' => $this->date($row['apontamento']['TL_DTINICI'] ?? null),
                'used_time' => $this->time($row['apontamento']['TL_HOINICI'] ?? null),
            ];
        }

        $main = $order['dados_principais'] ?? [];
        $maintenance = [
            'equipment_code' => $this->text($main['TJ_CODBEM'] ?? null),
            'equipment_name' => $this->text($order['equipamento']['T9_NOME'] ?? null),
            'service_code' => $this->text($main['TJ_SERVICO'] ?? null),
            'service_name' => $this->text($order['servico']['T4_NOME'] ?? null),
            'area' => $this->text($main['TJ_CODAREA'] ?? null),
            'cost_center' => $this->text($main['TJ_CCUSTO'] ?? null),
        ];
        foreach (['planned_start' => ['TJ_DTMPINI', 'TJ_HOMPINI'],
            'planned_end' => ['TJ_DTMPFIM', 'TJ_HOMPFIM'],
            'actual_start' => ['TJ_DTMRINI', 'TJ_HOMRINI'],
            'actual_end' => ['TJ_DTMRFIM', 'TJ_HOMRFIM'],
            'general_planned_start' => ['TJ_DTPPINI', 'TJ_HOPPINI'],
            'general_planned_end' => ['TJ_DTPPFIM', 'TJ_HOPPFIM'],
            'general_actual_start' => ['TJ_DTPRINI', 'TJ_HOPRINI'],
            'general_actual_end' => ['TJ_DTPRFIM', 'TJ_HOPRFIM'],
        ] as $label => [$date, $time]) {
            $maintenance[$label . '_date'] = $this->date($main[$date] ?? null);
            $maintenance[$label . '_time'] = $this->time($main[$time] ?? null);
        }

        return new OrderSupplement(
            number: $this->text($order['numero'] ?? null) ?? '',
            branch: isset($main['TJ_FILIAL']) ? rtrim((string)$main['TJ_FILIAL'], ' ') : null,
            description: $this->text($order['descricao'] ?? null),
            originDate: $this->date($main['TJ_DTORIGI'] ?? null),
            labor: $labor,
            materials: $materials,
            maintenance: $maintenance,
        );
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $value = rtrim((string)$value, ' ');

        return $value === '' ? null : $value;
    }

    public function date(mixed $value): ?string
    {
        $value = $this->text($value);
        if ($value === null || !preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/D', $value, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            return null;
        }

        return $parts[1] . '-' . $parts[2] . '-' . $parts[3];
    }

    private function time(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value !== null && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value) ? $value : null;
    }

}
