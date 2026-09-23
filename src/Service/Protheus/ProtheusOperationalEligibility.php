<?php
declare(strict_types=1);
namespace App\Service\Protheus;

/** Shared closed SQL predicates; pending/cancelled orders are never operational. */
final class ProtheusOperationalEligibility
{
    public const OPEN = "TJ_SITUACA = 'L' AND TJ_TERMINO = 'N'";
    public const CLOSED = "TJ_SITUACA = 'L' AND TJ_TERMINO = 'S'";
    public const ELIGIBLE_OPEN = '(' . self::OPEN . ") AND TRY_CONVERT(date, NULLIF(TJ_DTMPINI, ''), 112) >= CONVERT(date, :cutoff, 112)";
    public const OPERATIONAL = '(' . self::CLOSED . ') OR (' . self::ELIGIBLE_OPEN . ')';
}
