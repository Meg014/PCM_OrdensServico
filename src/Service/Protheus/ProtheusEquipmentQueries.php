<?php
declare(strict_types=1);
namespace App\Service\Protheus;

/** Closed read-only queries using only confirmed STJ/ST9 fields. */
final class ProtheusEquipmentQueries
{
    public const HEADER = <<<'SQL'
SELECT TOP (2) b.T9_CODBEM, b.T9_NOME
FROM dbo.ST9010 b
WHERE b.T9_CODBEM = CAST(:codigo AS VARCHAR(100)) AND b.T9_FILIAL = CAST(:filial AS VARCHAR(100))
    AND b.D_E_L_E_T_ <> '*'
SQL;
    public const SCOPE = "j.D_E_L_E_T_ <> '*' AND j.TJ_CODBEM = CAST(:bem AS VARCHAR(100)) AND j.TJ_FILIAL = CAST(:filial AS VARCHAR(100))";
    public const FILTER_JOIN = <<<'SQL'
CROSS JOIN (SELECT CAST(:date_start AS VARCHAR(10)) AS start_date, CAST(:date_end AS VARCHAR(10)) AS end_date,
    CAST(:type AS VARCHAR(100)) AS maintenance_type, CAST(:status AS VARCHAR(10)) AS status) f
SQL;
    public const FILTER = "(f.start_date = '' OR " . ProtheusQueries::ORIGIN_DATE . " >= CONVERT(date, NULLIF(f.start_date, ''), 23))"
        . " AND (f.end_date = '' OR " . ProtheusQueries::ORIGIN_DATE . " <= CONVERT(date, NULLIF(f.end_date, ''), 23))"
        . " AND (f.maintenance_type = '' OR j.TJ_TIPO = f.maintenance_type)"
        . " AND (f.status = '' OR (f.status = 'open' AND " . ProtheusOperationalEligibility::OPEN . ')'
        . " OR (f.status = 'closed' AND " . ProtheusOperationalEligibility::CLOSED . '))';

    public static function summary(): string
    {
        $scope = self::SCOPE;
        $join = self::FILTER_JOIN;
        $filter = self::FILTER;
        $open = ProtheusOperationalEligibility::OPEN;
        $closed = ProtheusOperationalEligibility::CLOSED;
        return <<<SQL
WITH scoped AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_TIPO, j.TJ_SITUACA, j.TJ_TERMINO,
        j.TJ_CCUSTO, j.TJ_CODAREA, j.TJ_DTMRFIM, j.TJ_DTMRINI, j.TJ_DTORIGI,
        CASE WHEN LEN(RTRIM(j.TJ_DTORIGI)) = 8 AND RTRIM(j.TJ_DTORIGI) NOT LIKE '%[^0-9]%'
            THEN TRY_CONVERT(date, j.TJ_DTORIGI, 112) END AS origin_date,
        COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j WHERE {$scope}
), filtered AS (
    SELECT j.* FROM scoped j {$join} WHERE {$filter}
), metrics AS (
    SELECT COUNT_BIG(*) AS total,
        COUNT_BIG(CASE WHEN {$open} THEN 1 END) AS open_count,
        COUNT_BIG(CASE WHEN {$closed} THEN 1 END) AS closed_count,
        COUNT_BIG(CASE WHEN TJ_SITUACA = 'C' THEN 1 END) AS canceled_count,
        COUNT_BIG(CASE WHEN TJ_SITUACA = 'P' THEN 1 END) AS pending_count,
        COUNT_BIG(CASE WHEN (({$open}) OR ({$closed})) AND TJ_TIPO = 'COR' THEN 1 END) AS corrective,
        COUNT_BIG(CASE WHEN (({$open}) OR ({$closed})) AND TJ_TIPO = 'PRE' THEN 1 END) AS preventive,
        COUNT_BIG(CASE WHEN (({$open}) OR ({$closed})) AND TJ_TIPO = 'MEL' THEN 1 END) AS improvement,
        COUNT_BIG(CASE WHEN (({$open}) OR ({$closed})) AND TJ_TIPO = 'COR'
            AND origin_date >= CONVERT(date, :since30, 23) AND origin_date <= CONVERT(date, :until30, 23) THEN 1 END) AS recurrence30,
        COUNT_BIG(CASE WHEN (({$open}) OR ({$closed})) AND TJ_TIPO = 'COR'
            AND origin_date >= CONVERT(date, :since90, 23) AND origin_date <= CONVERT(date, :until90, 23) THEN 1 END) AS recurrence90,
        COUNT_BIG(CASE WHEN (({$open}) OR ({$closed})) AND TJ_TIPO = 'COR'
            AND origin_date >= CONVERT(date, :since365, 23) AND origin_date <= CONVERT(date, :until365, 23) THEN 1 END) AS recurrence365
    FROM filtered
), context AS (
    SELECT COUNT_BIG(*) AS all_count, MAX(identity_count) AS identity_count,
        COUNT(DISTINCT NULLIF(RTRIM(TJ_CCUSTO), '')) AS cost_center_count,
        MIN(NULLIF(RTRIM(TJ_CCUSTO), '')) AS cost_center,
        COUNT(DISTINCT NULLIF(RTRIM(TJ_CODAREA), '')) AS area_count,
        MIN(NULLIF(RTRIM(TJ_CODAREA), '')) AS area
    FROM scoped
)
SELECT m.*, c.* FROM metrics m CROSS JOIN context c
SQL;
    }
}
