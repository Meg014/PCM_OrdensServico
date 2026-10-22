<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Fixed, read-only metadata check; never probes denied task data. */
final class OrderDescriptionQueries
{
    /** One batch for the already paginated identities; no task table or service-name fallback. */
    public const BATCH = <<<'SQL'
SELECT j.TJ_FILIAL, j.TJ_ORDEM, CONVERT(VARCHAR(MAX), j.TJ_OBSERVA) AS order_description,
    CONVERT(VARCHAR(MAX), l.TL_OBSERVA) AS entry_description,
    CASE WHEN sl.matches > 0 THEN sl.description ELSE ss.description END AS service_description
FROM OPENJSON(CAST(:identities AS NVARCHAR(MAX)))
    WITH (branch VARCHAR(100) '$.branch', number VARCHAR(100) '$.number') keys_page
INNER JOIN dbo.STJ010 j ON j.TJ_FILIAL = keys_page.branch AND j.TJ_ORDEM = keys_page.number
    AND j.D_E_L_E_T_ <> '*'
LEFT JOIN dbo.STL010 l ON l.TL_FILIAL = j.TJ_FILIAL AND l.TL_ORDEM = j.TJ_ORDEM
    AND l.D_E_L_E_T_ <> '*' AND l.TL_OBSERVA IS NOT NULL
OUTER APPLY (SELECT COUNT(*) matches, MAX(CONVERT(VARCHAR(MAX), s.T4_DESCRIC)) description
    FROM dbo.ST4010 s WHERE s.T4_FILIAL = j.TJ_FILIAL AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') sl
OUTER APPLY (SELECT COUNT(*) matches, MAX(CONVERT(VARCHAR(MAX), s.T4_DESCRIC)) description
    FROM dbo.ST4010 s WHERE s.T4_FILIAL = '' AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') ss
ORDER BY j.TJ_FILIAL, j.TJ_ORDEM, l.TL_DTINICI, l.TL_HOINICI, l.R_E_C_N_O_
SQL;
    public const PERMISSIONS = <<<'SQL'
SELECT HAS_PERMS_BY_NAME('dbo.TT9010', 'OBJECT', 'SELECT') AS task_catalog_select,
    HAS_PERMS_BY_NAME('dbo.ST5010', 'OBJECT', 'SELECT') AS maintenance_tasks_select,
    HAS_PERMS_BY_NAME('dbo.ST4010', 'OBJECT', 'SELECT') AS services_select
SQL;
    public const METADATA = <<<'SQL'
SELECT o.name AS table_name, c.name AS column_name, t.name AS data_type,
    HAS_PERMS_BY_NAME('dbo.' + o.name, 'OBJECT', 'SELECT') AS can_select
FROM sys.objects o
LEFT JOIN sys.columns c ON c.object_id = o.object_id
LEFT JOIN sys.types t ON t.user_type_id = c.user_type_id
WHERE o.schema_id = SCHEMA_ID('dbo') AND o.name IN ('ST4010', 'ST5010', 'TT9010', 'STL010')
ORDER BY o.name, c.column_id
SQL;
}
