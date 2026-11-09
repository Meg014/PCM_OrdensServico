/* Etapas da O.S. — SOMENTE APÓS executar etapas-permissoes e confirmar com a TI:
   1. a tabela física da empresa é dbo.STQ010;
   2. TQ_FILIAL, TQ_ORDEM, TQ_OBSERVA e os campos padrão de exclusão/registro existem;
   3. a conta que executará esta consulta tem SELECT autorizado.
   STQ/TQ_OBSERVA são documentados pela TOTVS; a instalação local ainda não foi lida.
   Se os nomes diferirem, solicitar os nomes corretos, sem improvisar relacionamento.
   O script não grava dados nem faz limpeza do memo. */
DECLARE @Filial varchar(100) = '01';
DECLARE @Ordem varchar(100) = '007334';

/* Observação do cabeçalho = SERVIÇO A REALIZAR. Não usar como execução. */
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_SERVICO, j.TJ_CODBEM,
    j.TJ_PLANO, j.TJ_SEQRELA, j.TJ_SITUACA, j.TJ_TERMINO,
    CONVERT(varchar(max), j.TJ_OBSERVA) AS servico_a_realizar
FROM dbo.STJ010 j
WHERE j.TJ_FILIAL = @Filial AND j.TJ_ORDEM = @Ordem AND j.D_E_L_E_T_ <> '*'
ORDER BY j.R_E_C_N_O_;

/* Amostra de etapas: todas as colunas preservam tarefa/etapa/plano/sequências reais,
   sem supor nomes que não ficaram visíveis para a conta PCM.
   Cada linha é um registro da etapa; NÃO fazer DISTINCT apenas por observação.
   A presença de uma etapa não significa, por si só, texto de trabalho preenchido. */
SELECT q.*, DATALENGTH(q.TQ_OBSERVA) AS quantidade_bytes_observacao_etapa,
    CONVERT(varchar(max), q.TQ_OBSERVA) AS observacao_etapa_texto
FROM dbo.STQ010 q
WHERE q.TQ_FILIAL = @Filial AND q.TQ_ORDEM = @Ordem AND q.D_E_L_E_T_ <> '*'
    AND EXISTS (SELECT 1 FROM dbo.STJ010 j
        WHERE j.TJ_FILIAL = q.TQ_FILIAL AND j.TJ_ORDEM = q.TQ_ORDEM AND j.D_E_L_E_T_ <> '*')
ORDER BY q.R_E_C_N_O_;

/* Encaminhar o resultado com:
   - a O.S./filial e a tarefa/etapa/sequências do print, mais o texto esperado;
   - confirmação de quais campos são reais versus virtuais no SX3;
   - chaves SX2/SIX e índices de STQ e sua relação com STJ/ST5/STH;
   - exemplos de várias etapas, etapa repetida com outra sequência e observação vazia;
   - indicação de qualquer customização da rotina/tela que altere onde a observação é gravada.
   Não usar TPA_DESCRI (catálogo da etapa), TT9_DESCRI (catálogo da tarefa),
   TJ_OBSERVA ou TL_OBSERVA como substitutos do memo da etapa.

   Se a O.S. foi acumulada em histórico, confirmar primeiro as tabelas físicas STS/STX
   e os campos TX_ com a TI. Não juntar histórico ao conjunto atual, nem trocar de
   empresa/filial, sem essa validação; isso pode duplicar ou associar outra O.S.
*/
