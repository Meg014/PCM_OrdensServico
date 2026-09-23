<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Closed SQL allowlist. Values are always bound separately as strings. */
final class ProtheusQueries
{
    public const HEALTH = 'SELECT 1 AS connection_ok';

    /** Optional columns are probed without executing arbitrary SQL or scanning STJ010. */
    public const HISTORY_USER_COLUMNS = <<<'SQL'
SELECT COL_LENGTH('dbo.STJ010', 'TJ_USUAINI') AS inicio,
       COL_LENGTH('dbo.STJ010', 'TJ_USUAFIM') AS fim
SQL;

    /**
     * Finite templates only: boolean choices cannot inject identifiers or expressions.
     * SQL Server equality pads CHAR/VARCHAR operands, respecting trailing spaces
     * without wrapping indexed equipment/branch columns in RTRIM.
     */
    public static function equipmentHistory(bool $branch, bool $startUser = false, bool $endUser = false): string
    {
        $branchFilter = $branch ? ' AND j.TJ_FILIAL = CAST(:filial AS VARCHAR(100))' : '';
        return self::orderPage("j.TJ_CODBEM = CAST(:bem AS VARCHAR(100)) AND j.D_E_L_E_T_ <> '*'{$branchFilter}", $startUser, $endUser);
    }

    /** Eight closed variants: exact order, branch and equipment filters. */
    public static function orders(bool $number, bool $branch, bool $equipment): string
    {
        $where = "j.D_E_L_E_T_ <> '*'";
        if ($number) {
            $where .= ' AND j.TJ_ORDEM = CAST(:numero AS VARCHAR(100))';
        }
        if ($branch) {
            $where .= ' AND j.TJ_FILIAL = CAST(:filial AS VARCHAR(100))';
        }
        if ($equipment) {
            $where .= ' AND j.TJ_CODBEM = CAST(:bem AS VARCHAR(100))';
        }

        return self::orderPage($where, false, false, false);
    }

    private static function orderPage(string $where, bool $startUser, bool $endUser, bool $description = true): string
    {
        $start = $startUser ? 'j.TJ_USUAINI' : 'CAST(NULL AS VARCHAR(25))';
        $end = $endUser ? 'j.TJ_USUAFIM' : 'CAST(NULL AS VARCHAR(25))';
        $descriptionColumn = $description ? 'CONVERT(VARCHAR(MAX), j.TJ_OBSERVA) AS descricao,' : '';

        return <<<SQL
WITH page_keys AS (
    SELECT j.R_E_C_N_O_, COALESCE(
               TRY_CONVERT(date, NULLIF(j.TJ_DTMRFIM, ''), 112),
               TRY_CONVERT(date, NULLIF(j.TJ_DTMRINI, ''), 112),
               TRY_CONVERT(date, NULLIF(j.TJ_DTORIGI, ''), 112)
           ) AS reference_date,
           COUNT(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    WHERE {$where}
    ORDER BY reference_date DESC, j.R_E_C_N_O_ DESC
    OFFSET :offset ROWS FETCH NEXT :fetch ROWS ONLY
)
SELECT j.R_E_C_N_O_ AS record_id, p.identity_count, j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODBEM,
       CASE WHEN b_local.matches > 0 THEN b_local.name ELSE b_shared.name END AS equipment_name,
       CASE WHEN b_local.matches > 0 THEN b_local.matches ELSE b_shared.matches END AS equipment_matches,
       j.TJ_SERVICO,
       CASE WHEN s_local.matches > 0 THEN s_local.name ELSE s_shared.name END AS service_name,
       CASE WHEN s_local.matches > 0 THEN s_local.matches ELSE s_shared.matches END AS service_matches,
       j.TJ_TIPO, j.TJ_CODAREA, j.TJ_CCUSTO, j.TJ_SITUACA, j.TJ_TERMINO,
       {$descriptionColumn}
       j.TJ_DTORIGI, j.TJ_DTPPINI, j.TJ_HOPPINI, j.TJ_DTPPFIM, j.TJ_HOPPFIM,
       j.TJ_DTPRINI, j.TJ_HOPRINI, j.TJ_DTPRFIM, j.TJ_HOPRFIM,
       j.TJ_DTMPINI, j.TJ_HOMPINI, j.TJ_DTMPFIM, j.TJ_HOMPFIM,
       j.TJ_DTMRINI, j.TJ_HOMRINI, j.TJ_DTMRFIM, j.TJ_HOMRFIM,
       {$start} AS TJ_USUAINI, {$end} AS TJ_USUAFIM,
       CONVERT(VARCHAR(10), p.reference_date, 23) AS reference_date
FROM page_keys p
INNER JOIN dbo.STJ010 j ON j.R_E_C_N_O_ = p.R_E_C_N_O_ AND j.D_E_L_E_T_ <> '*'
OUTER APPLY (
    SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name
    FROM dbo.ST9010 b
    WHERE b.T9_CODBEM = j.TJ_CODBEM AND b.D_E_L_E_T_ <> '*'
      AND b.T9_FILIAL = j.TJ_FILIAL
) b_local
OUTER APPLY (
    SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name
    FROM dbo.ST9010 b
    WHERE b.T9_CODBEM = j.TJ_CODBEM AND b.D_E_L_E_T_ <> '*'
      AND b.T9_FILIAL = ''
) b_shared
OUTER APPLY (
    SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name
    FROM dbo.ST4010 s
    WHERE s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*'
      AND s.T4_FILIAL = j.TJ_FILIAL
) s_local
OUTER APPLY (
    SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name
    FROM dbo.ST4010 s
    WHERE s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*'
      AND s.T4_FILIAL = ''
) s_shared
ORDER BY p.reference_date DESC, p.R_E_C_N_O_ DESC
SQL;
    }

    public const ORDER = <<<'SQL'
SELECT TOP (2) j.*, CONVERT(VARCHAR(MAX), j.TJ_OBSERVA) AS pcm_descricao
FROM dbo.STJ010 j
WHERE RTRIM(j.TJ_ORDEM) = :numero AND j.D_E_L_E_T_ <> '*'
SQL;

    public const ORDER_BRANCH = self::ORDER . ' AND RTRIM(j.TJ_FILIAL) = :filial';

    public const ORDER_IDENTITY = <<<'SQL'
SELECT TOP (2) j.TJ_ORDEM, j.TJ_FILIAL, j.TJ_CODBEM
FROM dbo.STJ010 j
WHERE j.TJ_ORDEM = CAST(:numero AS VARCHAR(100))
    AND j.TJ_FILIAL = CAST(:filial AS VARCHAR(100)) AND j.D_E_L_E_T_ <> '*'
SQL;

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

    public const EQUIPMENT_BRANCH = self::EQUIPMENT . ' AND b.T9_FILIAL = CAST(:filial AS VARCHAR(100))';
    public const SERVICE_BRANCH = self::SERVICE . ' AND s.T4_FILIAL = CAST(:filial AS VARCHAR(100))';
    public const PROFESSIONAL_BRANCH = self::PROFESSIONAL . ' AND p.T1_FILIAL = CAST(:filial AS VARCHAR(100))';
    public const PRODUCT_BRANCH = self::PRODUCT . ' AND p.B1_FILIAL = CAST(:filial AS VARCHAR(100))';

    public static function allows(string $sql): bool
    {
        foreach ([false, true] as $number) {
            foreach ([false, true] as $branch) {
                foreach ([false, true] as $equipment) {
                    if ($sql === self::orders($number, $branch, $equipment)) {
                        return true;
                    }
                }
            }
        }
        foreach ([false, true] as $branch) {
            foreach ([false, true] as $startUser) {
                foreach ([false, true] as $endUser) {
                    if ($sql === self::equipmentHistory($branch, $startUser, $endUser)) {
                        return true;
                    }
                }
            }
        }
        return in_array($sql, [
            self::ORDER_IDENTITY, self::EQUIPMENT_BRANCH, self::SERVICE_BRANCH,
            self::PROFESSIONAL_BRANCH, self::PRODUCT_BRANCH,
            self::HISTORY_USER_COLUMNS,
            self::HEALTH, self::ORDER, self::ORDER_BRANCH, self::EQUIPMENT,
            self::SERVICE, self::ENTRIES, self::PROFESSIONAL, self::PRODUCT,
        ], true);
    }
}
