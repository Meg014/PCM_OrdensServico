<?php
declare(strict_types=1);
namespace App\Service\Protheus;

/** Investigation only: no presumed stage-field join is used by the Excel query. */
final class ActionPlanStageQueries
{
    public const PERMISSIONS = <<<'SQL'
SELECT candidate.object_name, OBJECT_ID(candidate.object_name) visible_object_id,
    HAS_PERMS_BY_NAME(candidate.object_name, 'OBJECT', 'SELECT') can_select,
    HAS_PERMS_BY_NAME(candidate.object_name, 'OBJECT', 'VIEW DEFINITION') can_view_definition
FROM (VALUES ('dbo.ST5010'), ('dbo.STH010'), ('dbo.STQ010'), ('dbo.STX010'),
    ('dbo.STG010'), ('dbo.STN010'), ('dbo.TPA010'), ('dbo.TT9010'), ('dbo.STJ010'), ('dbo.STL010')) candidate(object_name)
SQL;

    public const METADATA = <<<'SQL'
SELECT SCHEMA_NAME(o.schema_id) schema_name, o.name object_name, o.type_desc,
    c.column_id, c.name column_name, ty.name data_type, c.max_length,
    HAS_PERMS_BY_NAME(QUOTENAME(SCHEMA_NAME(o.schema_id)) + '.' + QUOTENAME(o.name), 'OBJECT', 'SELECT') can_select
FROM sys.objects o
LEFT JOIN sys.columns c ON c.object_id = o.object_id
LEFT JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE o.is_ms_shipped = 0 AND (o.name IN ('ST5010', 'STH010', 'STQ010', 'STX010',
    'STG010', 'STN010', 'TPA010', 'TT9010', 'STJ010', 'STL010') OR o.type = 'V')
ORDER BY schema_name, object_name, c.column_id
SQL;
}
