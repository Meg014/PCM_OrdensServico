<?php
declare(strict_types=1);
namespace App\Service\Protheus;

/** Fixed sector SQL only. No caller-supplied SQL fragments or identifiers. */
final class ProtheusSectorQueries
{
    private const BASE = <<<'SQL'
WITH scoped AS (
    SELECT j.R_E_C_N_O_ AS record_id, j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODBEM, j.TJ_SERVICO,
        j.TJ_TIPO, j.TJ_CODAREA, j.TJ_CCUSTO, j.TJ_SITUACA, j.TJ_TERMINO,
        TRY_CONVERT(date, NULLIF(j.TJ_DTMPINI, ''), 112) AS planned_date,
        j.TJ_HOMPINI, j.TJ_DTPRINI, j.TJ_HOPRINI,
        CASE WHEN j.TJ_TERMINO = 'S' THEN 'FECHADA' ELSE 'EM ABERTO' END AS status,
        COUNT_BIG(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) AS identity_count
    FROM dbo.STJ010 j
    WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_CODAREA = CAST(:area AS VARCHAR(100))
      AND (
SQL
        . ProtheusOperationalEligibility::OPERATIONAL . <<<'SQL'
)
), named AS (
    SELECT j.*,
        CASE WHEN bl.matches > 0 THEN bl.name ELSE bs.name END AS equipment_name,
        CASE WHEN bl.matches > 0 THEN bl.matches ELSE bs.matches END AS equipment_matches,
        CASE WHEN sl.matches > 0 THEN sl.name ELSE ss.name END AS service_name,
        CASE WHEN sl.matches > 0 THEN sl.matches ELSE ss.matches END AS service_matches
    FROM scoped j
    OUTER APPLY (SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name FROM dbo.ST9010 b
        WHERE b.T9_CODBEM = j.TJ_CODBEM AND b.T9_FILIAL = j.TJ_FILIAL AND b.D_E_L_E_T_ <> '*') bl
    OUTER APPLY (SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name FROM dbo.ST9010 b
        WHERE b.T9_CODBEM = j.TJ_CODBEM AND b.T9_FILIAL = '' AND b.D_E_L_E_T_ <> '*') bs
    OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
        WHERE s.T4_SERVICO = j.TJ_SERVICO AND s.T4_FILIAL = j.TJ_FILIAL AND s.D_E_L_E_T_ <> '*') sl
    OUTER APPLY (SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name FROM dbo.ST4010 s
        WHERE s.T4_SERVICO = j.TJ_SERVICO AND s.T4_FILIAL = '' AND s.D_E_L_E_T_ <> '*') ss
), filtered AS (
    SELECT n.* FROM named n
    CROSS JOIN (SELECT CAST(:filial AS VARCHAR(100)) AS filial, CAST(:status AS VARCHAR(20)) AS status,
        CAST(:equipment AS VARCHAR(100)) AS equipment, CAST(:service AS VARCHAR(100)) AS service,
        CAST(:service_name AS VARCHAR(255)) AS service_name, CAST(:cost_center AS VARCHAR(100)) AS cost_center,
        CAST(:maintenance_type AS VARCHAR(100)) AS maintenance_type, CAST(:q AS VARCHAR(450)) AS q) f
    WHERE (f.filial = '' OR n.TJ_FILIAL = f.filial) AND (f.status = '' OR n.status = f.status)
        AND (f.equipment = '' OR n.TJ_CODBEM = f.equipment) AND (f.service = '' OR n.TJ_SERVICO = f.service)
        AND (f.service_name = '' OR n.service_name = f.service_name)
        AND (f.cost_center = '' OR n.TJ_CCUSTO = f.cost_center)
        AND (f.maintenance_type = '' OR n.TJ_TIPO = f.maintenance_type)
        AND (f.q = '' OR n.TJ_ORDEM LIKE f.q ESCAPE '~' OR n.TJ_CODBEM LIKE f.q ESCAPE '~'
            OR n.equipment_name LIKE f.q ESCAPE '~' OR n.TJ_SERVICO LIKE f.q ESCAPE '~'
            OR n.service_name LIKE f.q ESCAPE '~')
)
SQL;

    public static function aggregates(): string
    {
        return self::BASE . <<<'SQL'

, grouped AS (
    SELECT CASE WHEN GROUPING(status) = 0 AND GROUPING(TJ_SERVICO) = 0 THEN 'cards'
        WHEN GROUPING(status) = 0 THEN 'status' WHEN GROUPING(TJ_TIPO) = 0 THEN 'maintenance'
        WHEN GROUPING(TJ_CODBEM) = 0 THEN 'equipment' WHEN GROUPING(TJ_SERVICO) = 0 THEN 'services'
        WHEN GROUPING(TJ_CCUSTO) = 0 THEN 'costCenters' ELSE 'total' END AS dimension,
        TJ_FILIAL, status, TJ_TIPO, TJ_CODBEM, equipment_name, TJ_SERVICO, service_name, TJ_CCUSTO,
        COUNT_BIG(*) AS quantity, MAX(identity_count) AS identity_count,
        MAX(equipment_matches) AS equipment_matches, MAX(service_matches) AS service_matches,
        SUM(CAST(CASE WHEN TRY_CONVERT(date, NULLIF(TJ_DTPRINI, ''), 112) IS NULL THEN 1 ELSE 0 END AS BIGINT)) AS missing_start
    FROM filtered
    GROUP BY GROUPING SETS ((), (status, TJ_TIPO, TJ_SERVICO, service_name), (status), (TJ_TIPO),
        (TJ_FILIAL, TJ_CODBEM, equipment_name), (TJ_FILIAL, TJ_SERVICO, service_name), (TJ_FILIAL, TJ_CCUSTO))
), ranked AS (
    SELECT g.*, ROW_NUMBER() OVER (PARTITION BY dimension ORDER BY quantity DESC, TJ_FILIAL,
        status, TJ_TIPO, TJ_CODBEM, TJ_SERVICO, TJ_CCUSTO) AS position FROM grouped g
)
SELECT dimension, TJ_FILIAL, status, TJ_TIPO, TJ_CODBEM, equipment_name, TJ_SERVICO, service_name,
    TJ_CCUSTO, quantity, identity_count, equipment_matches, service_matches, missing_start
FROM ranked WHERE (dimension = 'cards' AND position <= 2001) OR (dimension <> 'cards' AND position <= 10)
ORDER BY dimension, position
OPTION (RECOMPILE)
SQL;
    }

    public static function page(): string
    {
        return self::BASE . <<<'SQL'

SELECT record_id, TJ_FILIAL, TJ_ORDEM, TJ_CODBEM, equipment_name, TJ_SERVICO, service_name,
    TJ_CODAREA, TJ_CCUSTO, TJ_TIPO, TJ_SITUACA, TJ_TERMINO, status,
    CONVERT(VARCHAR(10), planned_date, 23) AS planned_date, TJ_HOMPINI, TJ_DTPRINI, TJ_HOPRINI,
    identity_count, equipment_matches, service_matches
FROM filtered
CROSS JOIN (SELECT CAST(:date_start AS VARCHAR(10)) AS start_date, CAST(:date_end AS VARCHAR(10)) AS end_date) d
WHERE (d.start_date = '' OR planned_date >= CONVERT(date, NULLIF(d.start_date, ''), 23))
    AND (d.end_date = '' OR planned_date <= CONVERT(date, NULLIF(d.end_date, ''), 23))
ORDER BY planned_date DESC, record_id DESC
OFFSET :offset ROWS FETCH NEXT :fetch ROWS ONLY
OPTION (RECOMPILE)
SQL;
    }
}
