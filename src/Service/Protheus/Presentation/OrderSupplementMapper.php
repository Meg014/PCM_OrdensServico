<?php
declare(strict_types=1);

namespace App\Service\Protheus\Presentation;

/** Offline adapter for the existing repository result, mapping only confirmed columns. */
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
                'professional' => null,
                'code' => $this->text($row['apontamento']['TL_CODIGO'] ?? null),
                'date' => null, 'start_time' => null, 'end_time' => null, 'hours' => null,
            ];
        }
        $materials = [];
        foreach ($order['materiais'] ?? [] as $row) {
            if (rtrim((string)($row['apontamento']['TL_TIPOREG'] ?? '')) !== 'P') {
                continue;
            }
            $materials[] = [
                'code' => $this->text($row['apontamento']['TL_CODIGO'] ?? null),
                'description' => null, 'quantity' => null, 'unit' => null,
                'used_date' => null, 'used_time' => null,
            ];
        }

        return new OrderSupplement(
            number: $this->text($order['numero'] ?? null) ?? '',
            branch: $this->text($order['dados_principais']['TJ_FILIAL'] ?? null),
            description: $this->text($order['descricao'] ?? null),
            labor: $labor,
            materials: $materials,
        );
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = rtrim($value, ' ');

        return $value === '' ? null : $value;
    }
}
