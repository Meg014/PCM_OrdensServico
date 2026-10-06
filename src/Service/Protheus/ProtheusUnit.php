<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use InvalidArgumentException;

/** Single source of truth for the unit recorded on each STJ010 cost center. */
final class ProtheusUnit
{
    public const STJ_PREDICATE = "(f.unidade = ''"
        . " OR (f.unidade = 'factory' AND LTRIM(RTRIM(COALESCE(j.TJ_CCUSTO, ''))) LIKE '31%')"
        . " OR (f.unidade = 'mill' AND LTRIM(RTRIM(COALESCE(j.TJ_CCUSTO, ''))) LIKE '41%')"
        . " OR (f.unidade = 'other' AND LTRIM(RTRIM(COALESCE(j.TJ_CCUSTO, ''))) NOT LIKE '31%'"
        . " AND LTRIM(RTRIM(COALESCE(j.TJ_CCUSTO, ''))) NOT LIKE '41%'))";
    public const STJ_FACTORY_OR_MILL = "(LTRIM(RTRIM(COALESCE(j.TJ_CCUSTO, ''))) LIKE '31%'"
        . " OR LTRIM(RTRIM(COALESCE(j.TJ_CCUSTO, ''))) LIKE '41%')";
    public const ALL = '';
    public const FACTORY = 'factory';
    public const MILL = 'mill';
    public const OTHER = 'other';
    public const VALUES = [self::ALL, self::FACTORY, self::MILL, self::OTHER];
    public const LABELS = [
        self::FACTORY => 'Fábrica',
        self::MILL => 'Usina',
        self::OTHER => 'Outros / Sem unidade',
    ];

    public static function validate(mixed $value): string
    {
        if (!is_string($value) || !in_array(trim($value), self::VALUES, true)) {
            throw new InvalidArgumentException('Unidade inválida.');
        }

        return trim($value);
    }

    public static function classify(?string $costCenter): string
    {
        return match (substr(trim((string)$costCenter), 0, 2)) {
            '31' => self::FACTORY,
            '41' => self::MILL,
            default => self::OTHER,
        };
    }

    /** SQL predicate for a validated selector expression and an STJ010 cost-center expression. */
    public static function predicate(string $costCenter, string $selector): string
    {
        if ($costCenter === 'j.TJ_CCUSTO' && $selector === 'f.unidade') {
            return self::STJ_PREDICATE;
        }
        $code = "LTRIM(RTRIM(COALESCE({$costCenter}, '')))";

        return "({$selector} = ''"
            . " OR ({$selector} = 'factory' AND {$code} LIKE '31%')"
            . " OR ({$selector} = 'mill' AND {$code} LIKE '41%')"
            . " OR ({$selector} = 'other' AND {$code} NOT LIKE '31%' AND {$code} NOT LIKE '41%'))";
    }
}
