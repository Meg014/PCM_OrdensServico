<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\MaintenanceAreasTable;
use DateTimeImmutable;
use DateTimeInterface;

/** Read-only quality signals. Original source fields are never rewritten. */
final class OrderAuditFlags
{
    // Operational date bounds, independent of the current day. No source values are corrected.
    public const MIN_YEAR = 1900;
    public const MAX_YEAR = 2100;

    public static function forOrder(array $order, ?DateTimeImmutable $asOf = null): array
    {
        $flags = [];
        $area = strtoupper(trim((string)($order['maintenance_area_code'] ?? $order['TJ_CODAREA'] ?? '')));
        if ($area === '') {
            $flags['missing_area'] = 'Área de manutenção vazia';
        } elseif (!isset(MaintenanceAreasTable::FRIENDLY_NAMES[$area])) {
            $flags['unknown_area'] = 'Área de manutenção desconhecida: ' . $area;
        }
        $name = (string)($order['service_name'] ?? '');
        if (preg_match('/\bTESTE\b/ui', $name)) {
            $flags['test_service'] = 'Serviço de teste: ' . $name;
        }
        // Origin, planning and execution may all be in the future.
        foreach (['origin_date', 'maintenance_planned_start', 'maintenance_planned_end',
            'maintenance_actual_start', 'maintenance_actual_end', 'general_planned_start',
            'general_planned_end', 'general_actual_start', 'general_actual_end', 'planned_date',
            'TJ_DTORIGI', 'TJ_DTMPINI', 'TJ_DTMPFIM', 'TJ_DTMRINI', 'TJ_DTMRFIM',
            'TJ_DTPPINI', 'TJ_DTPPFIM', 'TJ_DTPRINI', 'TJ_DTPRFIM'] as $field) {
            $value = $order[$field] ?? null;
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            if ($value === null || (is_string($value) && in_array(trim($value), ['', '00000000', '0000-00-00'], true))) continue;
            if (!is_string($value)
                || !preg_match('/^(\d{4})-?(\d{2})-?(\d{2})(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', trim($value), $parts)
                || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
                $flags['invalid_date'] = 'Data inválida no TOTVS (verificar cadastro)';
            } elseif ((int)$parts[1] < self::MIN_YEAR || (int)$parts[1] > self::MAX_YEAR) {
                $flags['date_out_of_range'] = 'Data fora do intervalo aceitável (' . self::MIN_YEAR . ' a ' . self::MAX_YEAR
                    . '): ' . $parts[3] . '/' . $parts[2] . '/' . $parts[1] . ' (verificar cadastro no TOTVS)';
            }
        }

        return $flags;
    }
}
