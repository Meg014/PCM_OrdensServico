<?php
declare(strict_types=1);
namespace App\Service\Protheus;

/** Explicit diagnostics only. Metadata visibility is not proof that hidden histories do not exist. */
final class ActionPlanObservationQueries
{
    public const SOURCES = <<<'SQL'
SELECT SCHEMA_NAME(o.schema_id) schema_name, o.name object_name, o.type_desc,
    c.name column_name, ty.name data_type, c.max_length,
    HAS_PERMS_BY_NAME(QUOTENAME(SCHEMA_NAME(o.schema_id)) + '.' + QUOTENAME(o.name), 'OBJECT', 'SELECT') can_select
FROM sys.objects o
LEFT JOIN sys.columns c ON c.object_id = o.object_id
LEFT JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE o.is_ms_shipped = 0 AND o.type IN ('U', 'V') AND
    (o.name IN ('STJ010', 'STL010', 'ST4010') OR o.name LIKE 'SX3%' OR o.name LIKE 'TQB%'
     OR o.name LIKE '%HIST%' OR o.name LIKE '%AUDIT%' OR o.name LIKE '%LOG%'
     OR EXISTS (SELECT 1 FROM sys.columns k WHERE k.object_id = o.object_id AND
        (k.name LIKE '%ORDEM%' OR k.name LIKE '%OBSERV%' OR k.name LIKE '%ENCERR%' OR k.name LIKE '%FINALIZ%' OR k.name LIKE '%FECHAM%')))
ORDER BY schema_name, object_name, c.column_id
SQL;

    public const HISTORY_METADATA = <<<'SQL'
SELECT SCHEMA_NAME(t.schema_id) schema_name, t.name table_name, t.temporal_type_desc,
    OBJECT_SCHEMA_NAME(t.history_table_id) history_schema, OBJECT_NAME(t.history_table_id) history_table,
    t.is_tracked_by_cdc,
    CASE WHEN EXISTS (SELECT 1 FROM sys.change_tracking_tables ct WHERE ct.object_id = t.object_id) THEN 1 ELSE 0 END change_tracking,
    tr.name trigger_name, tr.is_disabled,
    CASE WHEN OBJECT_DEFINITION(tr.object_id) IS NOT NULL THEN 1 ELSE 0 END trigger_definition_visible
FROM sys.tables t
LEFT JOIN sys.triggers tr ON tr.parent_id = t.object_id
WHERE t.object_id = OBJECT_ID('dbo.STJ010')
SQL;

    public const HEADER = <<<'SQL'
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_SERVICO, j.TJ_SITUACA, j.TJ_TERMINO,
    j.TJ_DTORIGI, j.TJ_DTMPINI, j.TJ_DTMPFIM, j.TJ_DTMRINI, j.TJ_DTMRFIM,
    j.TJ_USUAINI, j.TJ_USUAFIM, j.TJ_SOLICI, j.TJ_IRREGU, j.TJ_OSORIG, j.TJ_ORDEPAI, j.TJ_FILORI,
    j.S_T_A_M_P_, j.I_N_S_D_T_, j.R_E_C_N_O_, j.D_E_L_E_T_,
    DATALENGTH(j.TJ_OBSERVA) observation_bytes,
    CONVERT(varchar(max), j.TJ_OBSERVA) observation_text,
    CONVERT(varchar(128), SUBSTRING(j.TJ_OBSERVA, 1, 64), 2) observation_hex_prefix
FROM dbo.STJ010 j
WHERE j.TJ_FILIAL = CAST(:branch AS varchar(100)) AND j.TJ_ORDEM = CAST(:number AS varchar(100))
ORDER BY j.R_E_C_N_O_
SQL;
}
