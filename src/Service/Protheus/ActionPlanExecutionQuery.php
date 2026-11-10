<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** One read for the exact exported identities; no STL join and no TQ_OK requirement. */
final class ActionPlanExecutionQuery
{
    public const SQL = <<<'SQL'
SELECT q.TQ_FILIAL, q.TQ_ORDEM, q.TQ_PLANO, q.TQ_TAREFA, q.TQ_ETAPA,
    q.TQ_SEQETA, q.TQ_SEQTARE, q.TQ_OK, q.TQ_CODFUNC, q.R_E_C_N_O_ stage_record,
    CONVERT(varchar(max), q.TQ_OBSERVA) executed_observation
FROM dbo.STQ010 q
WHERE q.D_E_L_E_T_ <> '*' AND q.TQ_OBSERVA IS NOT NULL
    AND EXISTS (
        SELECT 1 FROM OPENJSON(CAST(:identities AS nvarchar(max)))
        WITH (branch varchar(100) '$.branch', number varchar(100) '$.number') selected
        WHERE q.TQ_FILIAL = selected.branch AND q.TQ_ORDEM = selected.number
    )
ORDER BY q.TQ_FILIAL, q.TQ_ORDEM, q.TQ_PLANO, q.TQ_TAREFA,
    q.TQ_SEQTARE, q.TQ_ETAPA, q.TQ_SEQETA, q.R_E_C_N_O_
SQL;
}
