<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use InvalidArgumentException;

/** Display dates: maintenance execution and general dates are separate, without fallbacks. */
final class OrderDates
{
    public const FIELDS = [
        'planned_start' => ['TJ_DTMPINI', 'TJ_HOMPINI'],
        'planned_end' => ['TJ_DTMPFIM', 'TJ_HOMPFIM'],
        'actual_start' => ['TJ_DTMRINI', 'TJ_HOMRINI'],
        'actual_end' => ['TJ_DTMRFIM', 'TJ_HOMRFIM'],
        'general_planned_start' => ['TJ_DTPPINI', 'TJ_HOPPINI'],
        'general_planned_end' => ['TJ_DTPPFIM', 'TJ_HOPPFIM'],
        'general_actual_start' => ['TJ_DTPRINI', 'TJ_HOPRINI'],
        'general_actual_end' => ['TJ_DTPRFIM', 'TJ_HOPRFIM'],
    ];

    public static function pair(array $row, string $role): array
    {
        [$date, $time] = self::FIELDS[$role] ?? throw new InvalidArgumentException('Papel da data desconhecido.');

        return [rtrim((string)($row[$date] ?? '')), rtrim((string)($row[$time] ?? ''))];
    }
}
