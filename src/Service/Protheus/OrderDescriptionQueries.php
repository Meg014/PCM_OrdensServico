<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Fixed, read-only metadata check; never probes denied task data. */
final class OrderDescriptionQueries
{
    /** One batch for the already paginated identities; no task table or service-name fallback. */
    public const BATCH = <<<'SQL'
SELECT j.TJ_FILIAL, j.TJ_ORDEM, CONVERT(VARCHAR(MAX), j.TJ_OBSERVA) AS order_description
FROM OPENJSON(CAST(:identities AS NVARCHAR(MAX)))
    WITH (branch VARCHAR(100) '$.branch', number VARCHAR(100) '$.number') keys_page
INNER JOIN dbo.STJ010 j ON j.TJ_FILIAL = keys_page.branch AND j.TJ_ORDEM = keys_page.number
    AND j.D_E_L_E_T_ <> '*'
SQL;
    public const PERMISSIONS = <<<'SQL'
SELECT HAS_PERMS_BY_NAME('dbo.TT9010', 'OBJECT', 'SELECT') AS task_catalog_select,
    HAS_PERMS_BY_NAME('dbo.ST5010', 'OBJECT', 'SELECT') AS maintenance_tasks_select,
    HAS_PERMS_BY_NAME('dbo.ST5010', 'OBJECT', 'VIEW DEFINITION') AS maintenance_tasks_definition,
    HAS_PERMS_BY_NAME('dbo.ST4010', 'OBJECT', 'SELECT') AS services_select,
    HAS_PERMS_BY_NAME('dbo.TPA010', 'OBJECT', 'SELECT') AS stage_catalog_select
SQL;
    public const METADATA = <<<'SQL'
SELECT SCHEMA_NAME(o.schema_id) AS schema_name, o.name AS table_name, c.name AS column_name, t.name AS data_type,
    HAS_PERMS_BY_NAME(QUOTENAME(SCHEMA_NAME(o.schema_id)) + '.' + QUOTENAME(o.name), 'OBJECT', 'SELECT') AS can_select
FROM sys.objects o
LEFT JOIN sys.columns c ON c.object_id = o.object_id
LEFT JOIN sys.types t ON t.user_type_id = c.user_type_id
WHERE o.is_ms_shipped = 0 AND o.type IN ('U', 'V') AND
    (o.type = 'V' OR o.name LIKE 'ST%010' OR o.name LIKE 'TT%010' OR o.name LIKE 'TP%010'
     OR EXISTS (SELECT 1 FROM sys.columns k WHERE k.object_id = o.object_id AND
        (k.name LIKE '%ORDEM%' OR k.name LIKE '%TAREFA%' OR k.name LIKE '%ETAPA%' OR k.name LIKE '%PLANO%')))
ORDER BY o.name, c.column_id
SQL;
}
