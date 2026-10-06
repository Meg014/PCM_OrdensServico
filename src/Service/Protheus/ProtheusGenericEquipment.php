<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Explicit generic-equipment identities. Names are documentation only and never classify data. */
final class ProtheusGenericEquipment
{
    public const STJ_PREDICATE = "((j.TJ_FILIAL = '01' AND RTRIM(j.TJ_CODBEM) = 'FAB 80 020')"
        . " OR (j.TJ_FILIAL = '01' AND RTRIM(j.TJ_CODBEM) = 'SET 50 002')"
        . " OR (j.TJ_FILIAL = '01' AND RTRIM(j.TJ_CODBEM) = 'SET 50 001')"
        . " OR (j.TJ_FILIAL = '01' AND RTRIM(j.TJ_CODBEM) = 'SET 80 004'))";
    public const PREDICATE = "((TJ_FILIAL = '01' AND RTRIM(TJ_CODBEM) = 'FAB 80 020')"
        . " OR (TJ_FILIAL = '01' AND RTRIM(TJ_CODBEM) = 'SET 50 002')"
        . " OR (TJ_FILIAL = '01' AND RTRIM(TJ_CODBEM) = 'SET 50 001')"
        . " OR (TJ_FILIAL = '01' AND RTRIM(TJ_CODBEM) = 'SET 80 004'))";
    public const ITEMS = [
        ['branch' => '01', 'code' => 'FAB 80 020', 'name' => 'FABRICA'],
        ['branch' => '01', 'code' => 'SET 50 002', 'name' => 'CALDEIRA'],
        ['branch' => '01', 'code' => 'SET 50 001', 'name' => 'PREDIO CALDEIRA'],
        ['branch' => '01', 'code' => 'SET 80 004', 'name' => 'CALDEIRA ALBORG'],
    ];

    /** Exact branch + trimmed code predicate, suitable for STJ010 aliases. */
    public static function predicate(string $branch, string $code): string
    {
        $pairs = array_map(
            static fn (array $item): string => "({$branch} = '" . $item['branch']
                . "' AND RTRIM({$code}) = '" . $item['code'] . "')",
            self::ITEMS,
        );

        return '(' . implode(' OR ', $pairs) . ')';
    }
}
