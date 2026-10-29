<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Fixed read-only queries. STL proves recorded activities, never complete planning. */
final class OrderTaskQueries
{
    /** Explicit diagnostic only; no eligibility changes to the detail page. */
    public const ORDER_AUDIT = <<<'SQL'
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_PLANO, j.TJ_SEQRELA, j.TJ_SERVICO, j.TJ_CODBEM,
    j.TJ_SITUACA, j.TJ_TERMINO, j.D_E_L_E_T_ AS deleted
FROM dbo.STJ010 j
WHERE j.TJ_FILIAL = CAST(:filial AS VARCHAR(100)) AND j.TJ_ORDEM = CAST(:numero AS VARCHAR(100))
SQL;
    public const CATALOG_AUDIT = <<<'SQL'
SELECT 'task' AS kind, TT9_FILIAL AS branch, TT9_TAREFA AS code, TT9_DESCRI AS description
FROM dbo.TT9010 WHERE TT9_TAREFA = CAST(:task AS VARCHAR(100)) AND D_E_L_E_T_ <> '*'
UNION ALL
SELECT 'stage', TPA_FILIAL, TPA_ETAPA, TPA_DESCRI
FROM dbo.TPA010 WHERE TPA_ETAPA = CAST(:stage AS VARCHAR(100)) AND D_E_L_E_T_ <> '*'
SQL;
    public static function recorded(bool $tasks = true, bool $stages = true): string
    {
        $taskDescription = $tasks ? 'CASE WHEN task_local.matches = 1 THEN task_local.description WHEN task_local.matches = 0 AND task_shared.matches = 1 THEN task_shared.description END' : 'CAST(NULL AS VARCHAR(MAX))';
        $stageDescription = $stages ? 'CASE WHEN stage_local.matches = 1 THEN stage_local.description WHEN stage_local.matches = 0 AND stage_shared.matches = 1 THEN stage_shared.description END' : 'CAST(NULL AS VARCHAR(MAX))';
        $catalogs = '';
        if ($tasks) $catalogs .= <<<'SQL'

OUTER APPLY (SELECT COUNT(*) matches, MAX(t.TT9_DESCRI) description FROM dbo.TT9010 t
    WHERE t.TT9_FILIAL = l.TL_FILIAL AND t.TT9_TAREFA = l.TL_TAREFA AND t.D_E_L_E_T_ <> '*') task_local
OUTER APPLY (SELECT COUNT(*) matches, MAX(t.TT9_DESCRI) description FROM dbo.TT9010 t
    WHERE t.TT9_FILIAL = '' AND t.TT9_TAREFA = l.TL_TAREFA AND t.D_E_L_E_T_ <> '*') task_shared
SQL;
        if ($stages) $catalogs .= <<<'SQL'

OUTER APPLY (SELECT COUNT(*) matches, MAX(s.TPA_DESCRI) description FROM dbo.TPA010 s
    WHERE s.TPA_FILIAL = l.TL_FILIAL AND s.TPA_ETAPA = l.TL_ETAPA AND s.D_E_L_E_T_ <> '*') stage_local
OUTER APPLY (SELECT COUNT(*) matches, MAX(s.TPA_DESCRI) description FROM dbo.TPA010 s
    WHERE s.TPA_FILIAL = '' AND s.TPA_ETAPA = l.TL_ETAPA AND s.D_E_L_E_T_ <> '*') stage_shared
SQL;

        return <<<SQL
SELECT l.TL_FILIAL, l.TL_ORDEM, l.TL_PLANO, l.TL_SEQRELA, l.TL_SEQMAN, l.TL_SEQTARE,
    l.TL_TAREFA, l.TL_ETAPA, l.R_E_C_N_O_ AS entry_id,
    {$taskDescription} AS task_description, {$stageDescription} AS stage_description
FROM dbo.STL010 l
{$catalogs}
WHERE l.TL_FILIAL = CAST(:filial AS VARCHAR(100)) AND l.TL_ORDEM = CAST(:numero AS VARCHAR(100))
    AND l.D_E_L_E_T_ <> '*'
    AND (NULLIF(RTRIM(l.TL_TAREFA), '') IS NOT NULL OR NULLIF(RTRIM(l.TL_ETAPA), '') IS NOT NULL)
ORDER BY l.TL_PLANO, TRY_CONVERT(BIGINT, NULLIF(l.TL_SEQRELA, '')), l.TL_SEQRELA, l.TL_SEQMAN,
    TRY_CONVERT(BIGINT, NULLIF(l.TL_SEQTARE, '')), l.TL_SEQTARE, l.R_E_C_N_O_
SQL;
    }
}
