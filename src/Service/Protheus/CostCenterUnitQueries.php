<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Read-only aggregate diagnostics for validating the official unit catalog. */
final class CostCenterUnitQueries
{
    public const AUDIT = <<<'SQL'
SELECT RTRIM(j.TJ_CCUSTO) AS cost_center, RTRIM(j.TJ_FILIAL) AS branch,
    RTRIM(j.TJ_SERVICO) AS service, COUNT_BIG(*) AS quantity,
    CASE WHEN local_service.matches > 0 THEN local_service.name ELSE shared_service.name END AS service_name
FROM dbo.STJ010 j
OUTER APPLY (SELECT COUNT(*) matches, MAX(s.T4_NOME) name FROM dbo.ST4010 s
    WHERE s.T4_FILIAL = j.TJ_FILIAL AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') local_service
OUTER APPLY (SELECT COUNT(*) matches, MAX(s.T4_NOME) name FROM dbo.ST4010 s
    WHERE s.T4_FILIAL = '' AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') shared_service
WHERE j.D_E_L_E_T_ <> '*' AND (j.TJ_SITUACA IS NULL OR j.TJ_SITUACA <> 'C')
GROUP BY j.TJ_CCUSTO, j.TJ_FILIAL, j.TJ_SERVICO,
    CASE WHEN local_service.matches > 0 THEN local_service.name ELSE shared_service.name END
ORDER BY cost_center, branch, service
SQL;
    public const CENTERS = <<<'SQL'
SELECT RTRIM(CTT_FILIAL) AS branch, RTRIM(CTT_CUSTO) AS code, RTRIM(CTT_DESC01) AS description
FROM dbo.CTT010 WHERE D_E_L_E_T_ <> '*'
ORDER BY CTT_FILIAL, CTT_CUSTO
SQL;
}
