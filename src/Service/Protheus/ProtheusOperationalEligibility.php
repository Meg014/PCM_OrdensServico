<?php
declare(strict_types=1);
namespace App\Service\Protheus;

/** Shared status predicates. A closed order needs ending S and must not be canceled. */
final class ProtheusOperationalEligibility
{
    /** Cancellation has precedence over ending: every STJ010 row with situation C is outside PCM. */
    public const CANCELED = "TJ_SITUACA = 'C'";
    public const NOT_CANCELED = "(TJ_SITUACA IS NULL OR TJ_SITUACA <> 'C')";
    public const OPEN = "TJ_SITUACA = 'L' AND TJ_TERMINO = 'N'";
    public const CLOSED = "TJ_TERMINO = 'S' AND " . self::NOT_CANCELED;
    /** Open Entressafra remains active regardless of planned year (including missing dates). */
    public const OFFSEASON_NAME = "EXISTS (SELECT 1 FROM dbo.ST4010 active_service "
        . "WHERE active_service.D_E_L_E_T_ <> '*' AND active_service.T4_SERVICO = TJ_SERVICO "
        . "AND (active_service.T4_FILIAL = TJ_FILIAL OR (active_service.T4_FILIAL = '' AND NOT EXISTS ("
        . "SELECT 1 FROM dbo.ST4010 local_active_service WHERE local_active_service.D_E_L_E_T_ <> '*' "
        . "AND local_active_service.T4_SERVICO = TJ_SERVICO AND local_active_service.T4_FILIAL = TJ_FILIAL))) "
        . "AND active_service.T4_NOME COLLATE Latin1_General_100_CI_AI LIKE '%"
        . \App\Service\PcmServiceClassifier::OFFSEASON_TOKEN . "%')";
    public const ELIGIBLE_OPEN = '(' . self::OPEN . ") AND (TRY_CONVERT(date, NULLIF(TJ_DTMPINI, ''), 112) >= CONVERT(date, :cutoff, 112) OR "
        . self::OFFSEASON_NAME . ')';
    public const ELIGIBLE_OPEN_COUNTS = '(' . self::OPEN . ") AND (TRY_CONVERT(date, NULLIF(TJ_DTMPINI, ''), 112) >= CONVERT(date, :cutoff, 112) OR offseason_active = 1)";
    public const OPERATIONAL = '(' . self::CLOSED . ') OR (' . self::ELIGIBLE_OPEN . ')';

    public static function notCanceled(string $alias = ''): string
    {
        $column = $alias === '' ? 'TJ_SITUACA' : $alias . '.TJ_SITUACA';

        return "({$column} IS NULL OR {$column} <> 'C')";
    }

    public static function closed(string $alias = ''): string
    {
        $column = $alias === '' ? 'TJ_TERMINO' : $alias . '.TJ_TERMINO';

        return "{$column} = 'S' AND " . self::notCanceled($alias);
    }

    public static function isClosed(?string $situation, ?string $ending): bool
    {
        return trim($situation ?? '') !== 'C' && trim($ending ?? '') === 'S';
    }
}
