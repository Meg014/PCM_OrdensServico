<?php
declare(strict_types=1);

namespace App\Service\Protheus;

final class EquipmentLookupQueries
{
    private const EQUIPMENT_ANCHOR = "AND (f.equipment = '' OR n.TJ_CODBEM = f.equipment)";
    private const SUBSTRING_FILTER = " AND (n.TJ_CODBEM COLLATE Latin1_General_100_CI_AI LIKE CAST(:equipment_pattern AS NVARCHAR(202)) ESCAPE '~' OR n.equipment_name COLLATE Latin1_General_100_CI_AI LIKE CAST(:equipment_name_pattern AS NVARCHAR(202)) ESCAPE '~')";

    public static function withSubstring(string $sql): string
    {
        return str_replace(self::EQUIPMENT_ANCHOR, self::EQUIPMENT_ANCHOR . self::SUBSTRING_FILTER, $sql);
    }

    public static function withoutSubstring(string $sql): string
    {
        return str_replace(self::SUBSTRING_FILTER, '', $sql);
    }

    /** Shared masters resolve to the branches of their O.S.; local masters take precedence. */
    public const SEARCH = <<<'SQL'
WITH matches AS (
    SELECT b.T9_CODBEM, b.T9_NOME, b.T9_FILIAL
    FROM dbo.ST9010 b
    WHERE b.D_E_L_E_T_ <> '*' AND NULLIF(RTRIM(b.T9_CODBEM), '') IS NOT NULL
      AND (b.T9_CODBEM COLLATE Latin1_General_100_CI_AI LIKE CAST(:term AS NVARCHAR(202)) ESCAPE '~'
        OR b.T9_NOME COLLATE Latin1_General_100_CI_AI LIKE CAST(:name_term AS NVARCHAR(202)) ESCAPE '~')
), resolved AS (
    SELECT T9_CODBEM code, T9_NOME name, T9_FILIAL branch FROM matches WHERE T9_FILIAL <> ''
    UNION
    SELECT b.T9_CODBEM, b.T9_NOME, j.TJ_FILIAL FROM matches b
    INNER JOIN dbo.STJ010 j ON j.TJ_CODBEM = b.T9_CODBEM AND j.D_E_L_E_T_ <> '*'
    WHERE b.T9_FILIAL = '' AND NOT EXISTS (SELECT 1 FROM dbo.ST9010 local
        WHERE local.T9_CODBEM = b.T9_CODBEM AND local.T9_FILIAL = j.TJ_FILIAL AND local.D_E_L_E_T_ <> '*')
)
SELECT DISTINCT TOP (20) RTRIM(code) code, RTRIM(name) name, RTRIM(branch) branch
FROM resolved ORDER BY name, code, branch
SQL;
}
