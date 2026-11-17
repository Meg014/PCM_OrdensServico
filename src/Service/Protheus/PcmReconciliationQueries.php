<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use App\Model\Table\MaintenanceAreasTable;
use App\Service\PcmServiceClassifier;

/** SELECT-only reconciliation derived from the exact TV population and date predicate. */
final class PcmReconciliationQueries
{
    /** Independent SELECT: distinct identities, ending S, cancellation exclusion, no date predicate. */
    public const CLOSED_BY_AREA = <<<'SQL'
WITH named AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM,
        CASE WHEN UPPER(RTRIM(j.TJ_CODAREA)) = 'INSTRU' THEN 'ELETRI' ELSE UPPER(RTRIM(j.TJ_CODAREA)) END AS area,
        CASE WHEN sl.matches > 0 THEN sl.name ELSE ss.name END AS service_name
    FROM dbo.STJ010 j
    OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
        WHERE s.T4_FILIAL = j.TJ_FILIAL AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') sl
    OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
        WHERE s.T4_FILIAL = '' AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') ss
    WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_TERMINO = 'S' AND (j.TJ_SITUACA IS NULL OR j.TJ_SITUACA <> 'C')
)
SELECT area, CASE WHEN service_name COLLATE Latin1_General_100_CI_AI LIKE '%ENTRESSAFRA%'
    THEN 'ENTRESSAFRA' ELSE 'SAFRA' END AS season,
    COUNT_BIG(DISTINCT RTRIM(TJ_FILIAL) + CHAR(31) + RTRIM(TJ_ORDEM)) AS closed_count
FROM named GROUP BY area, CASE WHEN service_name COLLATE Latin1_General_100_CI_AI LIKE '%ENTRESSAFRA%'
    THEN 'ENTRESSAFRA' ELSE 'SAFRA' END
ORDER BY area, season
SQL;

    public static function totals(bool $period = true, bool $completedOnly = false): string
    {
        $sql = ProtheusQueries::management(true);
        if ($period) $sql = SectorPresentationScope::dashboardPeriod($sql, $completedOnly);
        $sql = str_replace('SELECT TOP (2001) c.TJ_FILIAL', ", resolved AS (\nSELECT c.TJ_FILIAL", $sql);
        $sql = substr($sql, 0, strpos($sql, 'ORDER BY c.TJ_FILIAL, c.TJ_CODAREA'));
        $token = PcmServiceClassifier::OFFSEASON_TOKEN;
        $labels = '';
        foreach (MaintenanceAreasTable::FRIENDLY_NAMES as $code => $name) {
            $labels .= " WHEN '{$code}' THEN N'" . str_replace("'", "''", $name) . "'";
        }

        return $sql . <<<SQL
), classified AS (
    SELECT CASE WHEN service_name COLLATE Latin1_General_100_CI_AI LIKE '%{$token}%'
        THEN 'ENTRESSAFRA' ELSE 'SAFRA' END AS season,
        CASE WHEN UPPER(LTRIM(RTRIM(TJ_CODAREA))) = 'INSTRU' THEN 'ELETRI'
            ELSE UPPER(LTRIM(RTRIM(COALESCE(TJ_CODAREA, '')))) END AS area,
        CASE UPPER(LTRIM(RTRIM(TJ_CODAREA))) {$labels} ELSE N'Área vazia/desconhecida' END AS area_name,
        state.status, state.quantity, identity_count, service_matches, unconfirmed_count
    FROM resolved CROSS APPLY (VALUES ('EM ABERTO', open_count), ('FECHADA', closed_count)) state(status, quantity)
)
SELECT season, area, MAX(area_name) AS area_name, status, SUM(quantity) AS quantity,
    MAX(identity_count) AS identity_count, MAX(service_matches) AS service_matches,
    SUM(unconfirmed_count) AS unconfirmed_count
FROM classified WHERE quantity > 0
GROUP BY season, area, status
ORDER BY season, area, status
SQL;
    }

    public const COMPLETION_FIELDS = <<<'SQL'
SELECT name AS field_name, TYPE_NAME(system_type_id) AS storage_type, max_length
FROM sys.columns WHERE object_id = OBJECT_ID('dbo.STJ010')
    AND name IN ('TJ_DTPRFIM', 'TJ_DTMRFIM', 'TJ_TERMINO', 'TJ_SITUACA')
ORDER BY name
SQL;

    public const QUALITY = <<<'SQL'
WITH source AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_SERVICO, j.TJ_CODAREA, j.TJ_SITUACA, j.TJ_TERMINO, f.as_of,
        TRY_CONVERT(date, NULLIF(j.TJ_DTORIGI, ''), 112) AS origin_date,
        TRY_CONVERT(date, NULLIF(j.TJ_DTPRFIM, ''), 112) AS completed_date,
        COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j CROSS JOIN (SELECT CONVERT(date, :as_of, 23) AS as_of) f WHERE j.D_E_L_E_T_ <> '*'
)
SELECT TJ_FILIAL, TJ_ORDEM, TJ_SERVICO, TJ_CODAREA, TJ_SITUACA, TJ_TERMINO,
    origin_date, completed_date, identity_count,
    CASE WHEN TJ_SITUACA = 'L' AND TJ_TERMINO = 'S' AND completed_date IS NULL THEN 1 ELSE 0 END AS missing_completion,
    CASE WHEN completed_date > as_of THEN 1 ELSE 0 END AS future_completion,
    CASE WHEN origin_date > as_of THEN 1 ELSE 0 END AS future_origin
FROM source WHERE identity_count > 1 OR (TJ_SITUACA = 'L' AND TJ_TERMINO = 'S' AND completed_date IS NULL)
    OR completed_date > as_of OR origin_date > as_of
ORDER BY TJ_FILIAL, TJ_ORDEM
SQL;
}
