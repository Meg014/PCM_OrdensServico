<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\MaintenanceAreasTable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Read-only quality signals. Original source fields are never rewritten. */
final class OrderAuditFlags
{
    public static function forOrder(array $order, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
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
        // Future planning is valid; only origin and actual execution dates are audited against today.
        foreach (['origin_date', 'maintenance_actual_start', 'maintenance_actual_end',
            'general_actual_start', 'general_actual_end', 'TJ_DTORIGI',
            'TJ_DTMRINI', 'TJ_DTMRFIM', 'TJ_DTPRINI', 'TJ_DTPRFIM'] as $field) {
            $value = $order[$field] ?? null;
            if ($value instanceof DateTimeInterface) {
                $date = $value->format('Y-m-d');
            } elseif (is_string($value) && preg_match('/^\d{8}$/D', $value)) {
                $date = substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
            } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                $date = substr($value, 0, 10);
            } else {
                continue;
            }
            if ($date > $asOf->format('Y-m-d')) {
                $flags['future_date'] = 'Data posterior a ' . $asOf->format('d/m/Y') . ' (validar origem e execução)';
            }
        }

        return $flags;
    }
}
