<?php
declare(strict_types=1);
namespace App\Service\Protheus;

/** Fixed SELECT-only schema investigation; never infers event semantics from update timestamps. */
final class OrderAuditQueries
{
    public const BATCH = <<<'SQL'
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_USUAINI, j.TJ_USUAFIM, j.TJ_USUARIO,
    j.TJ_CODAREA AS original_area,
    j.TJ_DTMRFIM, j.TJ_HOMRFIM, j.TJ_DTPRFIM, j.TJ_HOPRFIM
FROM OPENJSON(CAST(:identities AS NVARCHAR(MAX)))
WITH (branch VARCHAR(100) '$.branch', number VARCHAR(100) '$.number') k
INNER JOIN dbo.STJ010 j ON j.TJ_FILIAL=k.branch AND j.TJ_ORDEM=k.number AND j.D_E_L_E_T_ <> '*'
SQL;
    public const USERS = <<<'SQL'
SELECT DISTINCT RTRIM(j.TJ_USUAINI) AS code
FROM dbo.STJ010 j WHERE j.D_E_L_E_T_ <> '*' AND NULLIF(RTRIM(j.TJ_USUAINI), '') IS NOT NULL
ORDER BY code
SQL;
    public const USER_SAMPLE = <<<'SQL'
SELECT TOP (10) j.TJ_ORDEM, j.TJ_USUAINI, j.TJ_USUAFIM, j.TJ_USUARIO, j.TJ_SITUACA, j.TJ_TERMINO
FROM dbo.STJ010 j WHERE j.D_E_L_E_T_ <> '*' AND NULLIF(RTRIM(j.TJ_USUAINI), '') IS NOT NULL
ORDER BY j.R_E_C_N_O_ DESC
SQL;
    public const METADATA = <<<'SQL'
SELECT o.name table_name, c.name column_name, t.name data_type,
    HAS_PERMS_BY_NAME('dbo.' + o.name, 'OBJECT', 'SELECT') can_select
FROM sys.objects o JOIN sys.columns c ON c.object_id=o.object_id
JOIN sys.types t ON t.user_type_id=c.user_type_id
WHERE SCHEMA_NAME(o.schema_id)='dbo'
  AND (o.name IN ('STJ010', 'SYS_USR') OR o.name LIKE 'SX3%' OR o.name LIKE 'STJ%')
ORDER BY o.name,c.column_id
SQL;
}
