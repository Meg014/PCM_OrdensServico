<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Shared scope for PCM analytical views. It is deliberately opt-in for /pcm/ordens. */
final class ProtheusAnalyticalScope
{
    public const START_DATE = '2025-01-01';
    public const START_DATE_PROTHEUS = '20250101';
    public const STJ_PREDICATE = "(TRY_CONVERT(date, NULLIF(j.TJ_DTORIGI, ''), 112) >= CONVERT(date, '20250101', 112))";

    public static function predicate(string $originDate): string
    {
        return "{$originDate} >= CONVERT(date, '" . self::START_DATE_PROTHEUS . "', 112)";
    }
}
