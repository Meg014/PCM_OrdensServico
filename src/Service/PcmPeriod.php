<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use DateTimeZone;
use Cake\Core\Configure;

/** Indicator dates never substitute planning or origin for a missing actual completion. */
final class PcmPeriod
{
    public static function currentCycle(?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $configured = Configure::read('Pcm.currentCycleStart');
        $start = is_string($configured) && $configured !== '' ? $configured : $today->format('Y') . '-01-01';
        $period = self::fromFilters(['date_start' => $start, 'date_end' => $today->format('Y-m-d')]);

        return $period + ['source' => $configured ? 'configured_cycle' : 'operational_year'];
    }

    public static function sqlDate(string $alias = 'j'): string
    {
        return 'CASE WHEN ' . \App\Service\Protheus\ProtheusOperationalEligibility::closed($alias) . ' '
            . "THEN TRY_CONVERT(date, NULLIF({$alias}.TJ_DTPRFIM, ''), 112) "
            . "ELSE TRY_CONVERT(date, NULLIF({$alias}.TJ_DTORIGI, ''), 112) END";
    }

    public static function snapshotDate(): string
    {
        return "CASE WHEN WorkOrderSnapshots.treated_status = 'FECHADA' "
            . 'THEN WorkOrderSnapshots.general_actual_end ELSE WorkOrderSnapshots.origin_date END';
    }

    public static function fromFilters(array $filters): ?array
    {
        $start = $filters['date_start'] ?? '';
        $end = $filters['date_end'] ?? '';
        if ($start === '' && $end === '') {
            return null;
        }
        foreach ([$start, $end] as $value) {
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                throw new InvalidArgumentException('Informe início e fim do período (AAAA-MM-DD).');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Data do período inválida.');
            }
        }
        if ($start > $end) {
            throw new InvalidArgumentException('Período invertido.');
        }

        return ['date_start' => $start, 'date_end' => $end, 'basis' => 'status',
            'completed_basis' => 'general_actual_end', 'issued_basis' => 'origin_date'];
    }
}
