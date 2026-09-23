<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Closed SQL allowlist. Values are always bound separately as strings. */
final class ProtheusQueries
{
    public const HEALTH = 'SELECT 1 AS connection_ok';

    /** Aggregate before master lookup: no individual OS hydration or guessed maintenance type. */
    public const MANAGEMENT = <<<'SQL'
WITH base AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODAREA, j.TJ_SERVICO, j.TJ_TIPO, j.TJ_SITUACA, j.TJ_TERMINO,
           TRY_CONVERT(date, NULLIF(j.TJ_DTMPINI, ''), 112) AS planned_start,
           COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    CROSS JOIN (SELECT CAST(:filial AS VARCHAR(100)) AS filial, CAST(:area AS VARCHAR(100)) AS area,
        CAST(:bem AS VARCHAR(100)) AS bem, CAST(:servico AS VARCHAR(100)) AS servico,
        CAST(:centro AS VARCHAR(100)) AS centro, CAST(:tipo AS VARCHAR(100)) AS tipo,
        CAST(:situacao AS VARCHAR(100)) AS situacao, CAST(:termino AS VARCHAR(100)) AS termino) f
    WHERE j.D_E_L_E_T_ <> '*'
      AND (f.filial = '' OR j.TJ_FILIAL = f.filial)
      AND (f.area = '' OR j.TJ_CODAREA = f.area)
      AND (f.bem = '' OR j.TJ_CODBEM = f.bem)
      AND (f.servico = '' OR j.TJ_SERVICO = f.servico)
      AND (f.centro = '' OR j.TJ_CCUSTO = f.centro)
      AND (f.tipo = '' OR j.TJ_TIPO = f.tipo)
      AND (f.situacao = '' OR j.TJ_SITUACA = f.situacao)
      AND (f.termino = '' OR j.TJ_TERMINO = f.termino)
), counts AS (
    SELECT TJ_FILIAL, TJ_CODAREA, TJ_SERVICO, TJ_TIPO, COUNT_BIG(*) AS quantity,
        MAX(identity_count) AS identity_count,
        SUM(CAST(CASE WHEN TJ_TERMINO = 'N' AND TJ_SITUACA <> 'C'
            AND planned_start >= CONVERT(date, :cutoff, 112) THEN 1 ELSE 0 END AS BIGINT)) AS open_count,
        SUM(CAST(CASE WHEN TJ_TERMINO = 'S' AND TJ_SITUACA <> 'C' THEN 1 ELSE 0 END AS BIGINT)) AS closed_count,
        SUM(CAST(CASE WHEN TJ_SITUACA IS NULL OR TJ_SITUACA NOT IN ('C', 'L', 'P')
            OR TJ_TERMINO IS NULL OR TJ_TERMINO NOT IN ('N', 'S')
            OR (TJ_SITUACA = 'C' AND TJ_TERMINO = 'S') THEN 1 ELSE 0 END AS BIGINT)) AS unconfirmed_count
    FROM base GROUP BY TJ_FILIAL, TJ_CODAREA, TJ_SERVICO, TJ_TIPO
)
SELECT TOP (2001) c.TJ_FILIAL, c.TJ_CODAREA, c.TJ_SERVICO, c.TJ_TIPO, c.quantity,
    c.identity_count, c.open_count, c.closed_count, c.unconfirmed_count,
    CASE WHEN local_service.matches > 0 THEN local_service.name ELSE shared_service.name END AS service_name,
    CASE WHEN local_service.matches > 0 THEN local_service.matches ELSE shared_service.matches END AS service_matches
FROM counts c
OUTER APPLY (
    SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
    WHERE s.T4_SERVICO = c.TJ_SERVICO AND s.T4_FILIAL = c.TJ_FILIAL AND s.D_E_L_E_T_ <> '*'
) local_service
OUTER APPLY (
    SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
    WHERE s.T4_SERVICO = c.TJ_SERVICO AND s.T4_FILIAL = '' AND s.D_E_L_E_T_ <> '*'
) shared_service
ORDER BY c.TJ_FILIAL, c.TJ_CODAREA, c.TJ_SERVICO, c.TJ_TIPO
OPTION (RECOMPILE)
SQL;

    /** One bounded aggregate result; all filtering precedes aggregation on SQL Server. */
    public const DASHBOARD = <<<'SQL'
WITH base AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODAREA, j.TJ_CODBEM, j.TJ_SERVICO,
           j.TJ_CCUSTO, j.TJ_TIPO, j.TJ_SITUACA, j.TJ_TERMINO,
           COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    CROSS JOIN (SELECT CAST(:filial AS VARCHAR(100)) AS filial,
                       CAST(:area AS VARCHAR(100)) AS area,
                       CAST(:bem AS VARCHAR(100)) AS bem,
                       CAST(:servico AS VARCHAR(100)) AS servico,
                       CAST(:centro AS VARCHAR(100)) AS centro,
                       CAST(:tipo AS VARCHAR(100)) AS tipo,
                       CAST(:situacao AS VARCHAR(100)) AS situacao,
                       CAST(:termino AS VARCHAR(100)) AS termino) f
    WHERE j.D_E_L_E_T_ <> '*'
      AND (f.filial = '' OR j.TJ_FILIAL = f.filial)
      AND (f.area = '' OR j.TJ_CODAREA = f.area)
      AND (f.bem = '' OR j.TJ_CODBEM = f.bem)
      AND (f.servico = '' OR j.TJ_SERVICO = f.servico)
      AND (f.centro = '' OR j.TJ_CCUSTO = f.centro)
      AND (f.tipo = '' OR j.TJ_TIPO = f.tipo)
      AND (f.situacao = '' OR j.TJ_SITUACA = f.situacao)
      AND (f.termino = '' OR j.TJ_TERMINO = f.termino)
), grouped AS (
    SELECT CASE
        WHEN GROUPING(TJ_CODAREA) = 0 THEN 'area'
        WHEN GROUPING(TJ_CODBEM) = 0 THEN 'equipment'
        WHEN GROUPING(TJ_SERVICO) = 0 THEN 'service'
        WHEN GROUPING(TJ_CCUSTO) = 0 THEN 'cost_center'
        WHEN GROUPING(TJ_TIPO) = 0 THEN 'type'
        WHEN GROUPING(TJ_SITUACA) = 0 THEN 'status_raw'
        ELSE 'total' END AS dimension,
        CASE
        WHEN GROUPING(TJ_CODAREA) = 0 THEN TJ_CODAREA
        WHEN GROUPING(TJ_CODBEM) = 0 THEN TJ_CODBEM
        WHEN GROUPING(TJ_SERVICO) = 0 THEN TJ_SERVICO
        WHEN GROUPING(TJ_CCUSTO) = 0 THEN TJ_CCUSTO
        WHEN GROUPING(TJ_TIPO) = 0 THEN TJ_TIPO
        WHEN GROUPING(TJ_SITUACA) = 0 THEN TJ_SITUACA
        ELSE '' END AS code,
        CASE WHEN GROUPING(TJ_SITUACA) = 0 THEN TJ_TERMINO ELSE '' END AS ending,
        CASE WHEN GROUPING(TJ_FILIAL) = 0 THEN TJ_FILIAL ELSE '' END AS branch,
        COUNT_BIG(*) AS quantity, MAX(identity_count) AS identity_count
    FROM base
    GROUP BY GROUPING SETS ((), (TJ_FILIAL, TJ_CODAREA), (TJ_FILIAL, TJ_CODBEM),
        (TJ_FILIAL, TJ_SERVICO), (TJ_FILIAL, TJ_CCUSTO), (TJ_FILIAL, TJ_TIPO),
        (TJ_FILIAL, TJ_SITUACA, TJ_TERMINO))
), ranked AS (
    SELECT dimension, code, ending, branch, quantity, identity_count,
        ROW_NUMBER() OVER (PARTITION BY dimension ORDER BY quantity DESC, branch, code, ending) AS position
    FROM grouped
)
SELECT dimension, code, ending, branch, quantity, identity_count
FROM ranked WHERE position <= 10
ORDER BY dimension, position
OPTION (RECOMPILE)
SQL;

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
        if ($sql === ProtheusSectorQueries::aggregates() || $sql === ProtheusSectorQueries::page()) {
            return true;
        }
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
            self::DASHBOARD, self::MANAGEMENT,
            self::ORDER_IDENTITY, self::EQUIPMENT_BRANCH, self::SERVICE_BRANCH,
            self::PROFESSIONAL_BRANCH, self::PRODUCT_BRANCH,
            self::HISTORY_USER_COLUMNS,
            self::HEALTH, self::ORDER, self::ORDER_BRANCH, self::EQUIPMENT,
            self::SERVICE, self::ENTRIES, self::PROFESSIONAL, self::PRODUCT,
        ], true);
    }
}
