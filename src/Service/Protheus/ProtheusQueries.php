<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Closed SQL allowlist. Values are always bound separately as strings. */
final class ProtheusQueries
{
    public const HEALTH = 'SELECT 1 AS connection_ok';

    /** Fixed aggregate-only audit used to verify the analytical boundary against Protheus. */
    public const ANALYTICAL_AUDIT = <<<'SQL'
WITH source AS (
    SELECT RTRIM(j.TJ_FILIAL) AS branch, RTRIM(j.TJ_CODBEM) AS equipment, RTRIM(j.TJ_SERVICO) AS service,
        RTRIM(j.TJ_CODAREA) AS area, LTRIM(RTRIM(COALESCE(j.TJ_CCUSTO, ''))) AS cost_center,
        TRY_CONVERT(date, NULLIF(j.TJ_DTORIGI, ''), 112) AS origin_date
    FROM dbo.STJ010 j
    WHERE j.D_E_L_E_T_ <> '*' AND (j.TJ_SITUACA IS NULL OR j.TJ_SITUACA <> 'C')
), classified AS (
    SELECT *, CASE WHEN cost_center LIKE '31%' THEN 'factory'
        WHEN cost_center LIKE '41%' THEN 'mill' ELSE 'other' END AS unit_name,
        CASE WHEN branch = '01' AND equipment IN ('FAB 80 020', 'SET 50 002', 'SET 50 001', 'SET 80 004')
            THEN 1 ELSE 0 END AS is_generic
    FROM source
)
SELECT CASE WHEN GROUPING(unit_name) = 0 THEN 'unit:' + unit_name
        WHEN GROUPING(equipment) = 0 THEN 'generic:' + equipment ELSE 'all' END AS metric,
    COUNT_BIG(*) AS total,
    SUM(CAST(CASE WHEN origin_date >= CONVERT(date, '20250101', 112) THEN 1 ELSE 0 END AS BIGINT)) AS since_2025
FROM classified
GROUP BY GROUPING SETS ((), (unit_name), (equipment, is_generic))
HAVING GROUPING(equipment) = 1 OR is_generic = 1
UNION ALL
SELECT 'historical:safra_factory_mill', COUNT_BIG(*),
    SUM(CAST(CASE WHEN origin_date >= CONVERT(date, '20250101', 112) THEN 1 ELSE 0 END AS BIGINT))
FROM classified
WHERE unit_name IN ('factory', 'mill')
  AND NOT (branch = '01' AND service IN ('2425CA', '2425CI', '2425EL', '2425ME', '2425US',
      'ESCALD', 'ESDEST', 'ESMECA'))
ORDER BY metric
SQL;

    public const HISTORICAL_SERVICE_DEFINITIONS = <<<'SQL'
WITH grouped AS (
    SELECT j.TJ_FILIAL, j.TJ_SERVICO
    FROM dbo.STJ010 j
    WHERE j.D_E_L_E_T_ <> '*' AND (j.TJ_SITUACA IS NULL OR j.TJ_SITUACA <> 'C')
      AND
SQL
        . ProtheusUnit::STJ_FACTORY_OR_MILL . <<<'SQL'

      AND
SQL
        . ProtheusAnalyticalScope::STJ_PREDICATE . <<<'SQL'

    GROUP BY j.TJ_FILIAL, j.TJ_SERVICO
)
SELECT g.TJ_FILIAL, g.TJ_SERVICO,
    CASE WHEN local_service.matches > 0 THEN local_service.name ELSE shared_service.name END AS service_name,
    CASE WHEN local_service.matches > 0 THEN local_service.matches ELSE shared_service.matches END AS service_matches
FROM grouped g
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
    WHERE s.T4_SERVICO = g.TJ_SERVICO AND s.T4_FILIAL = g.TJ_FILIAL AND s.D_E_L_E_T_ <> '*') local_service
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
    WHERE s.T4_SERVICO = g.TJ_SERVICO AND s.T4_FILIAL = '' AND s.D_E_L_E_T_ <> '*') shared_service
ORDER BY g.TJ_FILIAL, g.TJ_SERVICO
SQL;

    public const AREAS = <<<'SQL'
SELECT DISTINCT RTRIM(j.TJ_CODAREA) AS code
FROM dbo.STJ010 j
WHERE j.D_E_L_E_T_ <> '*' AND
SQL
        . ProtheusOperationalEligibility::NOT_CANCELED . <<<'SQL'
 AND NULLIF(LTRIM(RTRIM(j.TJ_CODAREA)), '') IS NOT NULL
ORDER BY code
SQL;

    /** Aggregate before master lookup: no individual OS hydration or guessed maintenance type. */
    public const MANAGEMENT = <<<'SQL'
WITH base AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODAREA, j.TJ_SERVICO, j.TJ_TIPO, j.TJ_SITUACA, j.TJ_TERMINO,
           j.TJ_DTMPINI,
           COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    CROSS JOIN (SELECT CAST(:filial AS VARCHAR(100)) AS filial, CAST(:area AS VARCHAR(100)) AS area,
        CAST(:bem AS VARCHAR(100)) AS bem, CAST(:servico AS VARCHAR(100)) AS servico,
        CAST(:centro AS VARCHAR(100)) AS centro, CAST(:tipo AS VARCHAR(100)) AS tipo,
        CAST(:situacao AS VARCHAR(100)) AS situacao, CAST(:termino AS VARCHAR(100)) AS termino) f
    WHERE j.D_E_L_E_T_ <> '*'
      AND (
SQL
        . ProtheusOperationalEligibility::NOT_CANCELED . <<<'SQL'
)
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
        SUM(CAST(CASE WHEN (
SQL
        . ProtheusOperationalEligibility::ELIGIBLE_OPEN . <<<'SQL'
) THEN 1 ELSE 0 END AS BIGINT)) AS open_count,
        SUM(CAST(CASE WHEN (
SQL
        . ProtheusOperationalEligibility::CLOSED . <<<'SQL'
) THEN 1 ELSE 0 END AS BIGINT)) AS closed_count,
        SUM(CAST(CASE WHEN TJ_SITUACA IS NULL OR TJ_SITUACA NOT IN ('C', 'L', 'P')
            OR TJ_TERMINO IS NULL OR TJ_TERMINO NOT IN ('N', 'S') THEN 1 ELSE 0 END AS BIGINT)) AS unconfirmed_count
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

    public static function management(bool $unit = false): string
    {
        if (!$unit) return self::MANAGEMENT;
        $sql = str_replace(
            'CAST(:situacao AS VARCHAR(100)) AS situacao, CAST(:termino AS VARCHAR(100)) AS termino) f',
            'CAST(:situacao AS VARCHAR(100)) AS situacao, CAST(:termino AS VARCHAR(100)) AS termino, '
                . 'CAST(:unidade AS VARCHAR(20)) AS unidade) f',
            self::MANAGEMENT,
        );

        return str_replace(
            "AND (f.termino = '' OR j.TJ_TERMINO = f.termino)",
            "AND (f.termino = '' OR j.TJ_TERMINO = f.termino)\n      AND " . ProtheusUnit::STJ_PREDICATE,
            $sql,
        );
    }

    /** One bounded aggregate result; all filtering precedes aggregation on SQL Server. */
    public const DASHBOARD = <<<'SQL'
WITH base AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODAREA, j.TJ_CODBEM, j.TJ_SERVICO,
           j.TJ_CCUSTO, j.TJ_TIPO, j.TJ_SITUACA, j.TJ_TERMINO,
           CASE WHEN j.TJ_TERMINO = 'S' THEN 'completed' ELSE 'open' END AS status_group,
           COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    CROSS JOIN (SELECT CAST(:filial AS VARCHAR(100)) AS filial,
                       CAST(:area AS VARCHAR(100)) AS area,
                       CAST(:bem AS VARCHAR(100)) AS bem,
                       CAST(:servico AS VARCHAR(100)) AS servico,
                       CAST(:centro AS VARCHAR(100)) AS centro,
                       CAST(:tipo AS VARCHAR(100)) AS tipo,
                       CAST(:situacao AS VARCHAR(100)) AS situacao,
                       CAST(:termino AS VARCHAR(100)) AS termino,
                       CAST(:unidade AS VARCHAR(20)) AS unidade,
                       CAST(:offseason_services AS NVARCHAR(MAX)) AS offseason_services) f
    WHERE j.D_E_L_E_T_ <> '*'
      AND (
SQL
        . ProtheusOperationalEligibility::NOT_CANCELED . <<<'SQL'
)
      AND (f.filial = '' OR j.TJ_FILIAL = f.filial)
      AND (f.area = '' OR j.TJ_CODAREA = f.area)
      AND (f.bem = '' OR j.TJ_CODBEM = f.bem)
      AND (f.servico = '' OR j.TJ_SERVICO = f.servico)
      AND (f.centro = '' OR j.TJ_CCUSTO = f.centro)
      AND (f.tipo = '' OR j.TJ_TIPO = f.tipo)
      AND (f.situacao = '' OR j.TJ_SITUACA = f.situacao)
      AND (f.termino = '' OR j.TJ_TERMINO = f.termino)
      AND
SQL
        . ProtheusAnalyticalScope::STJ_PREDICATE . <<<'SQL'

      AND NOT EXISTS (
        SELECT 1 FROM OPENJSON(f.offseason_services)
        WITH (branch VARCHAR(100) '$.branch', code VARCHAR(100) '$.code') offseason
        WHERE offseason.branch = j.TJ_FILIAL AND offseason.code = j.TJ_SERVICO
      )
      AND
SQL
        . ProtheusUnit::STJ_FACTORY_OR_MILL . <<<'SQL'

      AND
SQL
        . ProtheusUnit::STJ_PREDICATE . <<<'SQL'

), grouped AS (
    SELECT CASE
        WHEN GROUPING(TJ_CODAREA) = 0 THEN 'area'
        WHEN GROUPING(TJ_SERVICO) = 0 THEN 'service'
        WHEN GROUPING(TJ_TIPO) = 0 THEN 'type'
        WHEN GROUPING(status_group) = 0 THEN 'status'
        ELSE 'total' END AS dimension,
        CASE
        WHEN GROUPING(TJ_CODAREA) = 0 THEN TJ_CODAREA
        WHEN GROUPING(TJ_SERVICO) = 0 THEN TJ_SERVICO
        WHEN GROUPING(TJ_TIPO) = 0 THEN TJ_TIPO
        WHEN GROUPING(status_group) = 0 THEN status_group
        ELSE '' END AS code,
        '' AS ending,
        CASE WHEN GROUPING(TJ_FILIAL) = 0 THEN TJ_FILIAL ELSE '' END AS branch,
        COUNT_BIG(*) AS quantity, MAX(identity_count) AS identity_count
    FROM base
    GROUP BY GROUPING SETS ((), (TJ_CODAREA), (TJ_FILIAL, TJ_SERVICO), (TJ_TIPO), (status_group))
    UNION ALL
    SELECT 'equipment', TJ_CODBEM, '', TJ_FILIAL, COUNT_BIG(*), MAX(identity_count)
    FROM base j WHERE NULLIF(LTRIM(RTRIM(TJ_CODBEM)), '') IS NOT NULL
      AND NULLIF(LTRIM(RTRIM(TJ_CCUSTO)), '') IS NOT NULL AND NOT
SQL
        . ProtheusGenericEquipment::STJ_PREDICATE . <<<'SQL'
      AND (EXISTS (SELECT 1 FROM dbo.ST9010 eb WHERE eb.T9_CODBEM = j.TJ_CODBEM
            AND eb.T9_FILIAL = j.TJ_FILIAL AND eb.D_E_L_E_T_ <> '*'
            AND NULLIF(LTRIM(RTRIM(eb.T9_NOME)), '') IS NOT NULL)
        OR EXISTS (SELECT 1 FROM dbo.ST9010 eb WHERE eb.T9_CODBEM = j.TJ_CODBEM
            AND eb.T9_FILIAL = '' AND eb.D_E_L_E_T_ <> '*'
            AND NULLIF(LTRIM(RTRIM(eb.T9_NOME)), '') IS NOT NULL))
    GROUP BY TJ_FILIAL, TJ_CODBEM
    UNION ALL
    SELECT 'cost_center', TJ_CCUSTO, '', '', COUNT_BIG(*), MAX(identity_count)
    FROM base WHERE NULLIF(LTRIM(RTRIM(TJ_CCUSTO)), '') IS NOT NULL GROUP BY TJ_CCUSTO
    UNION ALL
    SELECT 'generic' AS dimension, TJ_CODBEM AS code, '' AS ending, TJ_FILIAL AS branch,
        COUNT_BIG(*) AS quantity, MAX(identity_count) AS identity_count
    FROM base j WHERE
SQL
        . ProtheusGenericEquipment::STJ_PREDICATE . <<<'SQL'
    GROUP BY TJ_FILIAL, TJ_CODBEM
), ranked AS (
    SELECT dimension, code, ending, branch, quantity, identity_count,
        ROW_NUMBER() OVER (PARTITION BY dimension ORDER BY quantity DESC, branch, code, ending) AS position
    FROM grouped
)
SELECT r.dimension, r.code, r.ending, r.branch, r.quantity, r.identity_count,
    CASE WHEN r.dimension IN ('equipment', 'generic') THEN
        CASE WHEN local_equipment.matches > 0 THEN local_equipment.name ELSE shared_equipment.name END
    END AS equipment_name,
    CASE WHEN r.dimension IN ('equipment', 'generic') THEN
        CASE WHEN local_equipment.matches > 0 THEN local_equipment.matches ELSE shared_equipment.matches END
    ELSE 0 END AS equipment_matches
    ,CASE WHEN r.dimension = 'service' THEN
        CASE WHEN local_service.matches > 0 THEN local_service.name ELSE shared_service.name END
    END AS service_name
    ,CASE WHEN r.dimension = 'service' THEN
        CASE WHEN local_service.matches > 0 THEN local_service.matches ELSE shared_service.matches END
    ELSE 0 END AS service_matches
FROM ranked r
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name FROM dbo.ST9010 b
    WHERE r.dimension IN ('equipment', 'generic') AND b.T9_CODBEM = r.code AND b.T9_FILIAL = r.branch
      AND b.D_E_L_E_T_ <> '*') local_equipment
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name FROM dbo.ST9010 b
    WHERE r.dimension IN ('equipment', 'generic') AND b.T9_CODBEM = r.code AND b.T9_FILIAL = ''
      AND b.D_E_L_E_T_ <> '*') shared_equipment
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
    WHERE r.dimension = 'service' AND s.T4_SERVICO = r.code AND s.T4_FILIAL = r.branch
      AND s.D_E_L_E_T_ <> '*') local_service
OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
    WHERE r.dimension = 'service' AND s.T4_SERVICO = r.code AND s.T4_FILIAL = ''
      AND s.D_E_L_E_T_ <> '*') shared_service
WHERE r.dimension NOT IN ('equipment', 'service', 'cost_center') OR r.position <= 10
ORDER BY dimension, position
OPTION (RECOMPILE)
SQL;

    public static function dashboardAnalysis(): string
    {
        return self::DASHBOARD;
    }

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
    public static function equipmentHistory(bool $branch, bool $startUser = false, bool $endUser = false, bool $unit = false): string
    {
        $branchFilter = $branch ? ' AND j.TJ_FILIAL = CAST(:filial AS VARCHAR(100))' : '';
        $unitFilter = $unit ? ' AND ' . ProtheusUnit::predicate('j.TJ_CCUSTO', 'f.unit') : '';
        $join = $unit ? 'CROSS JOIN (SELECT CAST(:unit AS VARCHAR(20)) AS unit) f' : '';
        return self::orderPage("j.TJ_CODBEM = CAST(:bem AS VARCHAR(100)) AND j.D_E_L_E_T_ <> '*'{$branchFilter}{$unitFilter}", $startUser, $endUser, true, $join);
    }

    /** Dedicated equipment page, sharing the established history joins and ordering. */
    public static function equipmentPortfolioPage(bool $sector = false): string
    {
        return self::orderPage(ProtheusEquipmentQueries::scope($sector) . ' AND ' . ProtheusEquipmentQueries::filter(),
            false, false, true, ProtheusEquipmentQueries::FILTER_JOIN, self::ORIGIN_DATE);
    }

    /** Eight closed variants: exact order, branch and equipment filters. */
    public static function orders(bool $number, bool $branch, bool $equipment, bool $filters = false, bool $historical = false, bool $safra = false): string
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

        $join = '';
        if ($filters) {
            $unitField = ', CAST(:unidade AS VARCHAR(20)) AS unidade, CAST(:analitico AS VARCHAR(1)) AS analitico'
                . (($historical || $safra) ? ', CAST(:offseason_services AS NVARCHAR(MAX)) AS offseason_services' : '');
            $join = "CROSS JOIN (SELECT CAST(:centro AS VARCHAR(100)) AS centro, CAST(:centro_modo AS VARCHAR(10)) AS centro_modo, CAST(:area AS VARCHAR(100)) AS area, CAST(:servico AS VARCHAR(100)) AS servico, CAST(:tipo AS VARCHAR(100)) AS tipo, CAST(:situacao AS VARCHAR(100)) AS situacao, CAST(:termino AS VARCHAR(100)) AS termino, CAST(:date_start AS VARCHAR(10)) AS date_start, CAST(:date_end AS VARCHAR(10)) AS date_end, CAST(:nome_bem AS VARCHAR(202)) AS nome_bem{$unitField}) f";
            $date = self::ORIGIN_DATE;
            $where .= " AND (f.centro_modo = '' OR (f.centro_modo = 'exact' AND j.TJ_CCUSTO = f.centro) OR (f.centro_modo = 'blank' AND j.TJ_CCUSTO = '') OR (f.centro_modo = 'null' AND j.TJ_CCUSTO IS NULL))"
                . " AND (f.area = '' OR j.TJ_CODAREA = f.area)"
                . " AND (f.nome_bem = '' OR EXISTS (SELECT 1 FROM dbo.ST9010 name_local WHERE name_local.T9_CODBEM = j.TJ_CODBEM AND name_local.T9_FILIAL = j.TJ_FILIAL AND name_local.D_E_L_E_T_ <> '*' AND UPPER(name_local.T9_NOME) LIKE f.nome_bem ESCAPE '~') OR (NOT EXISTS (SELECT 1 FROM dbo.ST9010 any_local WHERE any_local.T9_CODBEM = j.TJ_CODBEM AND any_local.T9_FILIAL = j.TJ_FILIAL AND any_local.D_E_L_E_T_ <> '*') AND EXISTS (SELECT 1 FROM dbo.ST9010 name_shared WHERE name_shared.T9_CODBEM = j.TJ_CODBEM AND name_shared.T9_FILIAL = '' AND name_shared.D_E_L_E_T_ <> '*' AND UPPER(name_shared.T9_NOME) LIKE f.nome_bem ESCAPE '~')))"
                . " AND (f.servico = '' OR j.TJ_SERVICO = f.servico)"
                . " AND (f.tipo = '' OR j.TJ_TIPO = f.tipo)"
                . " AND (f.situacao = '' OR j.TJ_SITUACA = f.situacao)"
                . " AND (f.termino = '' OR j.TJ_TERMINO = f.termino)"
                . " AND (f.date_start = '' OR {$date} >= CONVERT(date, NULLIF(f.date_start, ''), 23))"
                . " AND (f.date_end = '' OR {$date} <= CONVERT(date, NULLIF(f.date_end, ''), 23))"
                . " AND (f.analitico = '' OR " . ProtheusAnalyticalScope::STJ_PREDICATE . ')'
                . ' AND ' . ProtheusUnit::predicate('j.TJ_CCUSTO', 'f.unidade');
            if ($historical || $safra) {
                $where .= " AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.offseason_services) WITH (branch VARCHAR(100) '$.branch', code VARCHAR(100) '$.code') offseason WHERE offseason.branch = j.TJ_FILIAL AND offseason.code = j.TJ_SERVICO)"
                    . ' AND ' . ProtheusAnalyticalScope::STJ_PREDICATE;
                if ($historical) $where .= ' AND ' . ProtheusUnit::STJ_FACTORY_OR_MILL;
            }
        }

        // The general export displays the same OS observation used by the detail page.
        return self::orderPage($where, false, false, true, $join, self::ORIGIN_DATE, 'origin_date');
    }

    /** General, filtered, bounded page with one row per STL010 entry. */
    public static function generalEntries(): string
    {
        $date = self::ORIGIN_DATE;
        $notCanceled = ProtheusOperationalEligibility::notCanceled('j');
        $unitScope = ProtheusUnit::predicate('j.TJ_CCUSTO', 'f.unidade');
        $factoryOrMill = ProtheusUnit::STJ_FACTORY_OR_MILL;
        $analyticalScope = ProtheusAnalyticalScope::STJ_PREDICATE;
        return <<<SQL
WITH filtered AS (
    SELECT j.R_E_C_N_O_ AS record_id, j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODBEM, j.TJ_SERVICO,
        j.TJ_TIPO, j.TJ_CODAREA, j.TJ_CCUSTO, j.TJ_SITUACA, j.TJ_TERMINO,
        CONVERT(VARCHAR(MAX), j.TJ_OBSERVA) AS descricao, {$date} AS reference_date,
        CASE WHEN j.TJ_TERMINO = 'S' THEN 'FECHADA' ELSE 'EM ABERTO' END AS status,
        COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    CROSS JOIN (SELECT CAST(:numero AS VARCHAR(100)) numero, CAST(:filial AS VARCHAR(100)) filial,
        CAST(:bem AS VARCHAR(100)) bem, CAST(:centro AS VARCHAR(100)) centro, CAST(:centro_modo AS VARCHAR(10)) centro_modo,
        CAST(:area AS VARCHAR(100)) area, CAST(:servico AS VARCHAR(100)) servico,
        CAST(:nome_bem AS VARCHAR(202)) nome_bem,
        CAST(:tipo AS VARCHAR(100)) tipo, CAST(:situacao AS VARCHAR(100)) situacao,
        CAST(:termino AS VARCHAR(100)) termino, CAST(:date_start AS VARCHAR(10)) date_start,
        CAST(:date_end AS VARCHAR(10)) date_end, CAST(:historico AS VARCHAR(1)) historico,
        CAST(:safra AS VARCHAR(1)) safra,
        CAST(:analitico AS VARCHAR(1)) analitico,
        CAST(:unidade AS VARCHAR(20)) unidade, CAST(:offseason_services AS NVARCHAR(MAX)) offseason_services) f
    WHERE j.D_E_L_E_T_ <> '*' AND {$notCanceled} AND (f.numero = '' OR j.TJ_ORDEM = f.numero)
      AND (f.filial = '' OR j.TJ_FILIAL = f.filial) AND (f.bem = '' OR j.TJ_CODBEM = f.bem)
      AND (f.centro_modo = '' OR (f.centro_modo = 'exact' AND j.TJ_CCUSTO = f.centro)
        OR (f.centro_modo = 'blank' AND j.TJ_CCUSTO = '') OR (f.centro_modo = 'null' AND j.TJ_CCUSTO IS NULL))
      AND (f.nome_bem = ''
        OR EXISTS (SELECT 1 FROM dbo.ST9010 name_local WHERE name_local.T9_CODBEM = j.TJ_CODBEM
          AND name_local.T9_FILIAL = j.TJ_FILIAL AND name_local.D_E_L_E_T_ <> '*'
          AND UPPER(name_local.T9_NOME) LIKE f.nome_bem ESCAPE '~')
        OR (NOT EXISTS (SELECT 1 FROM dbo.ST9010 any_local WHERE any_local.T9_CODBEM = j.TJ_CODBEM
          AND any_local.T9_FILIAL = j.TJ_FILIAL AND any_local.D_E_L_E_T_ <> '*')
          AND EXISTS (SELECT 1 FROM dbo.ST9010 name_shared WHERE name_shared.T9_CODBEM = j.TJ_CODBEM
            AND name_shared.T9_FILIAL = '' AND name_shared.D_E_L_E_T_ <> '*'
            AND UPPER(name_shared.T9_NOME) LIKE f.nome_bem ESCAPE '~')))
      AND (f.area = '' OR j.TJ_CODAREA = f.area) AND (f.servico = '' OR j.TJ_SERVICO = f.servico)
      AND (f.tipo = '' OR j.TJ_TIPO = f.tipo) AND (f.situacao = '' OR j.TJ_SITUACA = f.situacao)
      AND (f.termino = '' OR j.TJ_TERMINO = f.termino)
      AND (f.date_start = '' OR {$date} >= CONVERT(date, NULLIF(f.date_start, ''), 23))
      AND (f.date_end = '' OR {$date} <= CONVERT(date, NULLIF(f.date_end, ''), 23))
      AND (f.analitico = '' OR {$analyticalScope})
      AND {$unitScope}
      AND (f.historico = '' OR (NOT EXISTS (SELECT 1 FROM OPENJSON(f.offseason_services)
          WITH (branch VARCHAR(100) '$.branch', code VARCHAR(100) '$.code') offseason
          WHERE offseason.branch = j.TJ_FILIAL AND offseason.code = j.TJ_SERVICO)
        AND {$analyticalScope}
        AND {$factoryOrMill}))
      AND (f.safra = '' OR NOT EXISTS (SELECT 1 FROM OPENJSON(f.offseason_services)
          WITH (branch VARCHAR(100) '$.branch', code VARCHAR(100) '$.code') offseason
          WHERE offseason.branch = j.TJ_FILIAL AND offseason.code = j.TJ_SERVICO))
), named AS (
    SELECT j.*, CASE WHEN bl.matches > 0 THEN bl.name ELSE bs.name END equipment_name,
        CASE WHEN bl.matches > 0 THEN bl.matches ELSE bs.matches END equipment_matches,
        CASE WHEN sl.matches > 0 THEN sl.name ELSE ss.name END service_name,
        CASE WHEN sl.matches > 0 THEN sl.matches ELSE ss.matches END service_matches
    FROM filtered j
    OUTER APPLY (SELECT COUNT(*) matches, MAX(b.T9_NOME) name FROM dbo.ST9010 b WHERE b.T9_CODBEM=j.TJ_CODBEM AND b.T9_FILIAL=j.TJ_FILIAL AND b.D_E_L_E_T_<>'*') bl
    OUTER APPLY (SELECT COUNT(*) matches, MAX(b.T9_NOME) name FROM dbo.ST9010 b WHERE b.T9_CODBEM=j.TJ_CODBEM AND b.T9_FILIAL='' AND b.D_E_L_E_T_<>'*') bs
    OUTER APPLY (SELECT COUNT(*) matches, MAX(s.T4_NOME) name FROM dbo.ST4010 s WHERE s.T4_SERVICO=j.TJ_SERVICO AND s.T4_FILIAL=j.TJ_FILIAL AND s.D_E_L_E_T_<>'*') sl
    OUTER APPLY (SELECT COUNT(*) matches, MAX(s.T4_NOME) name FROM dbo.ST4010 s WHERE s.T4_SERVICO=j.TJ_SERVICO AND s.T4_FILIAL='' AND s.D_E_L_E_T_<>'*') ss
)
SELECT n.*, l.TL_TIPOREG, l.TL_CODIGO, l.TL_DTINICI, l.TL_DTFIM, l.TL_HOINICI, l.TL_HOFIM,
    l.TL_QUANTID, l.TL_UNIDADE, CASE WHEN l.TL_TIPOREG='M' THEN professional.name END professional_name,
    CASE WHEN l.TL_TIPOREG='P' THEN product.name END product_name,
    cost_center.name cost_center_name, cost_center.matches cost_center_matches,
    professional.matches professional_matches, product.matches product_matches
FROM named n
INNER JOIN dbo.STL010 l ON l.TL_ORDEM=n.TJ_ORDEM AND l.TL_FILIAL=n.TJ_FILIAL AND l.D_E_L_E_T_<>'*'
OUTER APPLY (SELECT COUNT(*) matches, MAX(c.CTT_DESC01) name FROM dbo.CTT010 c
    WHERE c.CTT_CUSTO=n.TJ_CCUSTO AND c.CTT_FILIAL=n.TJ_FILIAL AND c.D_E_L_E_T_<>'*') cost_center
OUTER APPLY (SELECT COUNT(*) matches, MAX(p.T1_NOME) name FROM dbo.ST1010 p WHERE l.TL_TIPOREG='M' AND p.T1_CODFUNC=l.TL_CODIGO AND p.T1_FILIAL=n.TJ_FILIAL AND p.D_E_L_E_T_<>'*') professional
OUTER APPLY (SELECT COUNT(*) matches, MAX(p.B1_DESC) name FROM dbo.SB1010 p WHERE l.TL_TIPOREG='P' AND p.B1_COD=l.TL_CODIGO AND p.B1_FILIAL=n.TJ_FILIAL AND p.D_E_L_E_T_<>'*') product
ORDER BY n.record_id DESC, l.R_E_C_N_O_ ASC
OFFSET :offset ROWS FETCH NEXT :fetch ROWS ONLY
OPTION (RECOMPILE)
SQL;
    }

    public const REFERENCE_DATE = "COALESCE(TRY_CONVERT(date, NULLIF(j.TJ_DTMRFIM, ''), 112), TRY_CONVERT(date, NULLIF(j.TJ_DTMRINI, ''), 112), TRY_CONVERT(date, NULLIF(j.TJ_DTORIGI, ''), 112))";
    public const ORIGIN_DATE = "TRY_CONVERT(date, NULLIF(j.TJ_DTORIGI, ''), 112)";

    private static function orderPage(string $where, bool $startUser, bool $endUser, bool $description = true, string $join = '', ?string $dateExpression = null, string $dateAlias = 'reference_date'): string
    {
        $start = $startUser ? 'j.TJ_USUAINI' : 'CAST(NULL AS VARCHAR(25))';
        $end = $endUser ? 'j.TJ_USUAFIM' : 'CAST(NULL AS VARCHAR(25))';
        $descriptionColumn = $description ? 'CONVERT(VARCHAR(MAX), j.TJ_OBSERVA) AS descricao,' : '';
        $referenceDate = $dateExpression ?? self::REFERENCE_DATE;
        $where = '(' . $where . ') AND ' . ProtheusOperationalEligibility::notCanceled('j');

        return <<<SQL
WITH page_keys AS (
    SELECT j.R_E_C_N_O_, {$referenceDate} AS {$dateAlias},
           COUNT(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    {$join}
    WHERE {$where}
    ORDER BY {$dateAlias} DESC, j.R_E_C_N_O_ DESC
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
       CONVERT(VARCHAR(10), p.{$dateAlias}, 23) AS {$dateAlias}
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
ORDER BY p.{$dateAlias} DESC, p.R_E_C_N_O_ DESC
SQL;
    }

    public const ORDER = <<<'SQL'
SELECT TOP (2) j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODBEM, j.TJ_SERVICO, j.TJ_CODAREA, j.TJ_CCUSTO,
    j.TJ_DTORIGI,
    j.TJ_DTMPINI, j.TJ_HOMPINI, j.TJ_DTMPFIM, j.TJ_HOMPFIM,
    j.TJ_DTMRINI, j.TJ_HOMRINI, j.TJ_DTMRFIM, j.TJ_HOMRFIM,
    j.TJ_DTPPINI, j.TJ_HOPPINI, j.TJ_DTPPFIM, j.TJ_HOPPFIM,
    j.TJ_DTPRINI, j.TJ_HOPRINI, j.TJ_DTPRFIM, j.TJ_HOPRFIM,
    CONVERT(VARCHAR(MAX), j.TJ_OBSERVA) AS pcm_descricao
FROM dbo.STJ010 j
WHERE RTRIM(j.TJ_ORDEM) = :numero AND j.D_E_L_E_T_ <> '*' AND
SQL
        . ProtheusOperationalEligibility::NOT_CANCELED . <<<'SQL'

SQL;

    public const ORDER_BRANCH = self::ORDER . ' AND RTRIM(j.TJ_FILIAL) = :filial';

    public const ORDER_IDENTITY = <<<'SQL'
SELECT TOP (2) j.TJ_ORDEM, j.TJ_FILIAL, j.TJ_CODBEM
FROM dbo.STJ010 j
WHERE j.TJ_ORDEM = CAST(:numero AS VARCHAR(100))
    AND j.TJ_FILIAL = CAST(:filial AS VARCHAR(100)) AND j.D_E_L_E_T_ <> '*' AND
SQL
        . ProtheusOperationalEligibility::NOT_CANCELED . <<<'SQL'

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
    AND RTRIM(j.TJ_FILIAL) = RTRIM(l.TL_FILIAL) AND j.D_E_L_E_T_ <> '*' AND
SQL
        . ProtheusOperationalEligibility::NOT_CANCELED . <<<'SQL'

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
        if (self::allowsBase($sql)) return true;
        $base = str_replace(self::AREA_SCOPE, '', $sql);

        return $base !== $sql && self::allowsBase($base) && self::withAreaScope($base) === $sql;
    }

    private const AREA_SCOPE = ' AND j.TJ_CODAREA = CAST(:scope_area AS VARCHAR(100))';

    /** Fixed transformation of allowlisted SQL; first STJ source, before pagination/aggregation. */
    public static function withAreaScope(string $sql): string
    {
        $anchor = "j.D_E_L_E_T_ <> '*'";
        $position = strpos($sql, $anchor);

        return $position === false ? $sql : substr_replace($sql, $anchor . self::AREA_SCOPE, $position, strlen($anchor));
    }

    private static function allowsBase(string $sql): bool
    {
        if (in_array($sql, [self::equipmentPortfolioPage(), self::equipmentPortfolioPage(true),
            ProtheusEquipmentQueries::HEADER, ProtheusEquipmentQueries::summary(), ProtheusEquipmentQueries::summary(true)], true)) {
            return true;
        }
        if (in_array($sql, [ProtheusSectorQueries::aggregates(), ProtheusSectorQueries::aggregates(false),
            ProtheusSectorQueries::page(), ProtheusSectorQueries::page(false, false),
            ProtheusSectorQueries::page(true), ProtheusSectorQueries::page(true, false),
            ProtheusSectorQueries::page(false, true, true), ProtheusSectorQueries::page(false, false, true),
            ProtheusSectorQueries::page(true, true, true), ProtheusSectorQueries::page(true, false, true),
            ProtheusSectorQueries::backlog(), ProtheusSectorQueries::backlog(false),
            ProtheusSectorQueries::equipmentRanking(), ProtheusSectorQueries::equipmentRanking(false),
            ProtheusSectorQueries::historicalRankings(), ProtheusSectorQueries::historicalRankings(false),
            ProtheusSectorQueries::opportunityCostCenters(),
            ProtheusSectorQueries::opportunityCostCenters(true)], true)
            || $sql === ProtheusSectorQueries::entriesPage() || $sql === ProtheusSectorQueries::entriesPage(true)) {
            return true;
        }
        if ($sql === self::generalEntries()) return true;
        foreach ([false, true] as $number) {
            foreach ([false, true] as $branch) {
                foreach ([false, true] as $equipment) {
                    foreach ([false, true] as $historical) {
                        foreach ([false, true] as $safra) {
                            if ($sql === self::orders($number, $branch, $equipment)
                                || $sql === self::orders($number, $branch, $equipment, true, $historical, $safra)) {
                                return true;
                            }
                        }
                    }
                }
            }
        }
        foreach ([false, true] as $branch) {
            foreach ([false, true] as $startUser) {
                foreach ([false, true] as $endUser) {
                    foreach ([false, true] as $unit) {
                        if ($sql === self::equipmentHistory($branch, $startUser, $endUser, $unit)) {
                            return true;
                        }
                    }
                }
            }
        }
        return in_array($sql, [
            self::dashboardAnalysis(), self::management(), self::management(true), self::AREAS, self::HISTORICAL_SERVICE_DEFINITIONS,
            self::ORDER_IDENTITY, self::EQUIPMENT_BRANCH, self::SERVICE_BRANCH,
            self::PROFESSIONAL_BRANCH, self::PRODUCT_BRANCH,
            self::HISTORY_USER_COLUMNS, self::ANALYTICAL_AUDIT,
            self::HEALTH, self::ORDER, self::ORDER_BRANCH, self::EQUIPMENT,
            self::SERVICE, self::ENTRIES, self::PROFESSIONAL, self::PRODUCT,
        ], true);
    }
}
