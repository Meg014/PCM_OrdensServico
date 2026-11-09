<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Isolated read: calculate the historical reference before applying the requested dates. */
final class ActionPlanQuery
{
    /** Explicit, SELECT-only investigation of route identities and recorded completion notes. */
    public const AUDIT = <<<'SQL'
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODBEM, j.TJ_CCUSTO, j.TJ_SERVICO, j.TJ_PLANO,
    j.TJ_TERMINO, j.TJ_SITUACA, j.TJ_DTORIGI, j.TJ_DTMRFIM,
    CONVERT(varchar(max), j.TJ_OBSERVA) order_note,
    b.T9_FILIAL, b.T9_NOME, b.T9_CCUSTO,
    l.TL_TIPOREG, l.TL_TAREFA, l.TL_ETAPA, l.TL_DTFIM, l.R_E_C_N_O_ entry_id,
    CONVERT(varchar(max), l.TL_OBSERVA) entry_note
FROM dbo.STJ010 j
LEFT JOIN dbo.ST9010 b ON b.T9_CODBEM = j.TJ_CODBEM AND b.T9_FILIAL = j.TJ_FILIAL AND b.D_E_L_E_T_ <> '*'
LEFT JOIN dbo.STL010 l ON l.TL_FILIAL = j.TJ_FILIAL AND l.TL_ORDEM = j.TJ_ORDEM AND l.D_E_L_E_T_ <> '*'
WHERE j.D_E_L_E_T_ <> '*' AND j.TJ_DTORIGI >= :start AND j.TJ_DTORIGI <= :end
ORDER BY j.TJ_FILIAL, j.TJ_ORDEM, l.TL_DTINICI, l.TL_HOINICI, l.R_E_C_N_O_
SQL;
    public static function sql(): string
    {
        return <<<'SQL'
WITH orders AS (
    SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CCUSTO, j.TJ_CODBEM, j.TJ_SERVICO,
        j.TJ_SITUACA, j.TJ_TERMINO, j.TJ_DTORIGI, j.TJ_OBSERVA,
        COUNT(*) OVER (PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM) identity_count
    FROM dbo.STJ010 j
    WHERE j.D_E_L_E_T_ <> '*'
), referenced AS (
    SELECT j.*, CASE WHEN a.entry_count > 0 THEN a.first_entry
        ELSE TRY_CONVERT(date, NULLIF(RTRIM(j.TJ_DTORIGI), ''), 112) END reference_date
    FROM orders j
    OUTER APPLY (
        SELECT COUNT_BIG(*) entry_count,
            MIN(TRY_CONVERT(date, NULLIF(RTRIM(l.TL_DTINICI), ''), 112)) first_entry
        FROM dbo.STL010 l
        WHERE l.TL_FILIAL = j.TJ_FILIAL AND l.TL_ORDEM = j.TJ_ORDEM AND l.D_E_L_E_T_ <> '*'
    ) a
    WHERE j.TJ_SITUACA = 'L' AND j.TJ_TERMINO IN ('N', 'S')
), filtered AS (
    SELECT j.* FROM referenced j CROSS JOIN (SELECT CAST(:unidade AS varchar(10)) unidade) f
    WHERE j.reference_date >= CONVERT(date, :date_start, 23)
        AND j.reference_date <= CONVERT(date, :date_end, 23) AND
SQL
        . ActionPlanRouteScope::predicate() . <<<'SQL'

)
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CCUSTO, j.TJ_CODBEM, j.TJ_SERVICO, j.identity_count, j.TJ_SITUACA, j.TJ_TERMINO,
    CONVERT(varchar(10), j.reference_date, 23) reference_date,
    CONVERT(varchar(max), j.TJ_OBSERVA) description,
    c.name cost_center_name, c.matches cost_center_matches,
    CASE WHEN b.matches > 0 THEN b.name ELSE bs.name END equipment_name,
    CASE WHEN b.matches > 0 THEN b.matches ELSE bs.matches END equipment_matches,
    CASE WHEN s.matches > 0 THEN s.name ELSE ss.name END service_name,
    CASE WHEN s.matches > 0 THEN s.matches ELSE ss.matches END service_matches,
    l.TL_TIPOREG, l.TL_CODIGO, CONVERT(varchar(max), l.TL_OBSERVA) entry_description,
    p.name professional_name, p.matches professional_matches,
    l.TL_TAREFA, CASE WHEN task_local.matches > 0 THEN task_local.description ELSE task_shared.description END task_description,
    CASE WHEN task_local.matches > 0 THEN task_local.matches ELSE task_shared.matches END task_matches
FROM filtered j
OUTER APPLY (SELECT COUNT(*) matches, MAX(c.CTT_DESC01) name FROM dbo.CTT010 c
    WHERE c.CTT_FILIAL = j.TJ_FILIAL AND c.CTT_CUSTO = j.TJ_CCUSTO AND c.D_E_L_E_T_ <> '*') c
OUTER APPLY (SELECT COUNT(*) matches, MAX(b.T9_NOME) name FROM dbo.ST9010 b
    WHERE b.T9_FILIAL = j.TJ_FILIAL AND b.T9_CODBEM = j.TJ_CODBEM AND b.D_E_L_E_T_ <> '*') b
OUTER APPLY (SELECT COUNT(*) matches, MAX(b.T9_NOME) name FROM dbo.ST9010 b
    WHERE b.T9_FILIAL = '' AND b.T9_CODBEM = j.TJ_CODBEM AND b.D_E_L_E_T_ <> '*') bs
OUTER APPLY (SELECT COUNT(*) matches, MAX(s.T4_NOME) name FROM dbo.ST4010 s
    WHERE s.T4_FILIAL = j.TJ_FILIAL AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') s
OUTER APPLY (SELECT COUNT(*) matches, MAX(s.T4_NOME) name FROM dbo.ST4010 s
    WHERE s.T4_FILIAL = '' AND s.T4_SERVICO = j.TJ_SERVICO AND s.D_E_L_E_T_ <> '*') ss
LEFT JOIN dbo.STL010 l ON l.TL_FILIAL = j.TJ_FILIAL AND l.TL_ORDEM = j.TJ_ORDEM
    AND l.D_E_L_E_T_ <> '*'
OUTER APPLY (SELECT COUNT(*) matches, MAX(p.T1_NOME) name FROM dbo.ST1010 p
    WHERE l.TL_TIPOREG = 'M' AND p.T1_FILIAL = l.TL_FILIAL
        AND p.T1_CODFUNC = l.TL_CODIGO AND p.D_E_L_E_T_ <> '*') p
OUTER APPLY (SELECT COUNT(*) matches, MAX(t.TT9_DESCRI) description FROM dbo.TT9010 t
    WHERE t.TT9_FILIAL = l.TL_FILIAL AND t.TT9_TAREFA = l.TL_TAREFA AND NULLIF(RTRIM(l.TL_TAREFA), '') IS NOT NULL AND t.D_E_L_E_T_ <> '*') task_local
OUTER APPLY (SELECT COUNT(*) matches, MAX(t.TT9_DESCRI) description FROM dbo.TT9010 t
    WHERE t.TT9_FILIAL = '' AND t.TT9_TAREFA = l.TL_TAREFA AND NULLIF(RTRIM(l.TL_TAREFA), '') IS NOT NULL AND t.D_E_L_E_T_ <> '*') task_shared

ORDER BY j.reference_date, j.TJ_FILIAL, j.TJ_ORDEM,
    CASE WHEN TRY_CONVERT(date, NULLIF(RTRIM(l.TL_DTINICI), ''), 112) IS NULL THEN 1 ELSE 0 END,
    l.TL_DTINICI, l.TL_HOINICI, l.R_E_C_N_O_
SQL;
    }
}
