<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Closed SQL allowlist. Values are always bound separately as strings. */
final class ProtheusQueries
{
    public const HEALTH = 'SELECT 1 AS connection_ok';

    public const ORDER = <<<'SQL'
SELECT TOP (2) j.*, CONVERT(VARCHAR(MAX), j.TJ_OBSERVA) AS pcm_descricao
FROM dbo.STJ010 j
WHERE RTRIM(j.TJ_ORDEM) = :numero AND j.D_E_L_E_T_ <> '*'
SQL;

    public const ORDER_BRANCH = self::ORDER . ' AND RTRIM(j.TJ_FILIAL) = :filial';

    public const EQUIPMENT = <<<'SQL'
SELECT TOP (2) b.*
FROM dbo.ST9010 b
WHERE RTRIM(b.T9_CODBEM) = :codigo AND b.D_E_L_E_T_ <> '*'
SQL;

    public const SERVICE = <<<'SQL'
SELECT TOP (2) s.*
FROM dbo.ST4010 s
WHERE RTRIM(s.T4_SERVICO) = :codigo AND s.D_E_L_E_T_ <> '*'
SQL;

    public const ENTRIES = <<<'SQL'
SELECT l.*
FROM dbo.STL010 l
INNER JOIN dbo.STJ010 j ON RTRIM(j.TJ_ORDEM) = RTRIM(l.TL_ORDEM)
    AND RTRIM(j.TJ_FILIAL) = RTRIM(l.TL_FILIAL) AND j.D_E_L_E_T_ <> '*'
WHERE RTRIM(j.TJ_ORDEM) = :numero AND RTRIM(j.TJ_FILIAL) = :filial
    AND l.D_E_L_E_T_ <> '*'
SQL;

    public const PROFESSIONAL = <<<'SQL'
SELECT TOP (2) p.*
FROM dbo.ST1010 p
WHERE RTRIM(p.T1_CODFUNC) = :codigo AND p.D_E_L_E_T_ <> '*'
SQL;

    public const PRODUCT = <<<'SQL'
SELECT TOP (2) p.*
FROM dbo.SB1010 p
WHERE RTRIM(p.B1_COD) = :codigo AND p.D_E_L_E_T_ <> '*'
SQL;

    public static function allows(string $sql): bool
    {
        return in_array($sql, [
            self::HEALTH, self::ORDER, self::ORDER_BRANCH, self::EQUIPMENT,
            self::SERVICE, self::ENTRIES, self::PROFESSIONAL, self::PRODUCT,
        ], true);
    }
}
