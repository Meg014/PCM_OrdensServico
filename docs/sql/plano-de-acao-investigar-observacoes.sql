/*
  Diagnóstico somente leitura — Plano de Ação / SIGAMNT.
  Executar no SSMS na base correta, com a conta autorizada pela TI.
  Não altera tabelas, registros, permissões ou parâmetros do Protheus.
  Não presume que checklist, tempo verbal ou TJ_TERMINO provem a origem do memo.
  Filial e número continuam texto: não converter os identificadores para números.
*/
DECLARE @Filial varchar(100) = '01';
DECLARE @Ordem varchar(100) = '007334';

/* 1. Cabeçalho e memo atuais. Pode retornar versões logicamente excluídas: verificar a marca. */
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.R_E_C_N_O_, j.D_E_L_E_T_,
       j.TJ_SERVICO, j.TJ_CODBEM, j.TJ_SITUACA, j.TJ_TERMINO,
       j.TJ_DTORIGI, j.TJ_DTMPINI, j.TJ_DTMPFIM,
       j.TJ_DTMRINI, j.TJ_HOMRINI, j.TJ_DTMRFIM, j.TJ_HOMRFIM,
       j.TJ_USUAINI, j.TJ_USUAFIM, j.TJ_USUARIO,
       j.TJ_SOLICI, j.TJ_IRREGU, j.TJ_OSORIG, j.TJ_ORDEPAI, j.TJ_FILORI,
       j.S_T_A_M_P_, j.I_N_S_D_T_,
       DATALENGTH(j.TJ_OBSERVA) AS quantidade_bytes_memo,
       CONVERT(varchar(max), j.TJ_OBSERVA) AS observacao_atual,
       CONVERT(varchar(128), SUBSTRING(j.TJ_OBSERVA, 1, 64), 2) AS prefixo_hexadecimal
FROM dbo.STJ010 j
WHERE j.TJ_FILIAL = @Filial AND j.TJ_ORDEM = @Ordem
ORDER BY j.R_E_C_N_O_;

/* 2. Cadastro de serviço: NÃO é prova da descrição específica na abertura da O.S.
   Exibe filial exata e compartilhada, sem escolher outra filial por aproximação. */
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_SERVICO,
       s.T4_FILIAL, s.T4_SERVICO, s.T4_NOME,
       CONVERT(varchar(max), s.T4_DESCRIC) AS descricao_cadastral_servico
FROM dbo.STJ010 j
JOIN dbo.ST4010 s ON s.T4_SERVICO = j.TJ_SERVICO
    AND (s.T4_FILIAL = j.TJ_FILIAL OR s.T4_FILIAL = '')
    AND s.D_E_L_E_T_ <> '*'
WHERE j.TJ_FILIAL = @Filial AND j.TJ_ORDEM = @Ordem AND j.D_E_L_E_T_ <> '*'
ORDER BY s.T4_FILIAL DESC, s.R_E_C_N_O_;

/* 3. Apontamentos: não confundir a observação de um apontamento com memo exclusivo de fechamento. */
SELECT l.TL_FILIAL, l.TL_ORDEM, l.R_E_C_N_O_, l.D_E_L_E_T_,
       l.TL_TIPOREG, l.TL_CODIGO, l.TL_TAREFA, l.TL_ETAPA,
       l.TL_PLANO, l.TL_SEQRELA, l.TL_SEQMAN, l.TL_SEQTARE,
       l.TL_DTINICI, l.TL_HOINICI, l.TL_DTFIM, l.TL_HOFIM,
       DATALENGTH(l.TL_OBSERVA) AS quantidade_bytes_observacao,
       CONVERT(varchar(max), l.TL_OBSERVA) AS observacao_apontamento
FROM dbo.STL010 l
WHERE l.TL_FILIAL = @Filial AND l.TL_ORDEM = @Ordem
ORDER BY l.TL_DTINICI, l.TL_HOINICI, l.R_E_C_N_O_;

/* 4. Metadados visíveis: procurar fontes específicas de abertura, retorno e histórico.
   A ausência de resultados NÃO prova a inexistência de objetos ocultos à conta atual. */
SELECT SCHEMA_NAME(o.schema_id) AS esquema, o.name AS objeto, o.type_desc,
       c.name AS coluna, ty.name AS tipo, c.max_length,
       HAS_PERMS_BY_NAME(QUOTENAME(SCHEMA_NAME(o.schema_id)) + '.' + QUOTENAME(o.name), 'OBJECT', 'SELECT') AS pode_ler
FROM sys.objects o
LEFT JOIN sys.columns c ON c.object_id = o.object_id
LEFT JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE o.is_ms_shipped = 0 AND o.type IN ('U', 'V') AND
    (o.name IN ('STJ010', 'STL010', 'ST4010') OR o.name LIKE 'SX3%' OR o.name LIKE 'TQB%'
     OR o.name LIKE '%HIST%' OR o.name LIKE '%AUDIT%' OR o.name LIKE '%LOG%'
     OR EXISTS (SELECT 1 FROM sys.columns k WHERE k.object_id = o.object_id AND
        (k.name LIKE '%ORDEM%' OR k.name LIKE '%OBSERV%' OR k.name LIKE '%ENCERR%'
         OR k.name LIKE '%FINALIZ%' OR k.name LIKE '%FECHAM%')))
ORDER BY esquema, objeto, c.column_id;

/* 5. Histórico nativo e triggers. Uma trigger de timestamp não deve ser presumida como auditoria.
   A definição abaixo é apenas retornada como texto; jamais é executada por este script. */
SELECT SCHEMA_NAME(t.schema_id) AS esquema, t.name AS tabela, t.temporal_type_desc,
       OBJECT_SCHEMA_NAME(t.history_table_id) AS esquema_historico,
       OBJECT_NAME(t.history_table_id) AS tabela_historico,
       t.is_tracked_by_cdc,
       CASE WHEN EXISTS (SELECT 1 FROM sys.change_tracking_tables ct WHERE ct.object_id = t.object_id) THEN 1 ELSE 0 END AS change_tracking,
       tr.name AS trigger_nome, tr.is_disabled,
       OBJECT_DEFINITION(tr.object_id) AS definicao_visivel_trigger
FROM sys.tables t
LEFT JOIN sys.triggers tr ON tr.parent_id = t.object_id
WHERE t.object_id = OBJECT_ID('dbo.STJ010');

/* 6. Identificar onde está o dicionário SX3 e quais colunas ele realmente possui.
   O dicionário pode não estar em SQL ou não estar visível para esta conta. */
SELECT SCHEMA_NAME(o.schema_id) AS esquema, o.name AS objeto,
       c.name AS coluna, ty.name AS tipo
FROM sys.objects o
JOIN sys.columns c ON c.object_id = o.object_id
JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE o.is_ms_shipped = 0 AND (o.name LIKE 'SX3%' OR c.name IN ('X3_CAMPO', 'X3_ARQUIVO'))
ORDER BY esquema, objeto, c.column_id;

/*
  7. MODELO OPCIONAL — somente depois de a TI confirmar o nome e as colunas do SX3.
  Substituir o objeto; manter este bloco comentado até a confirmação. Não executar
  contra um objeto suposto ou de outra empresa como alternativa a uma permissão negada.

SELECT X3_ARQUIVO, X3_CAMPO, X3_TIPO, X3_TAMANHO,
       X3_TITULO, X3_DESCRIC, X3_CBOX, X3_VALID, X3_RELACAO
FROM [esquema_confirmado].[objeto_SX3_confirmado]
WHERE D_E_L_E_T_ <> '*' AND
     (X3_ARQUIVO IN ('STJ', 'STL', 'ST4', 'TQB')
      OR X3_CAMPO LIKE '%OBSERV%' OR X3_CAMPO LIKE '%FINAL%' OR X3_CAMPO LIKE '%ENCERR%')
ORDER BY X3_ARQUIVO, X3_CAMPO;

  Solicitar também à TI:
  - versão/rotina ADVPL/API efetivamente usada para incluir e finalizar a O.S.;
  - quais campos essa rotina grava em abertura, retorno, finalização e reabertura;
  - se existe auditoria/log habilitado que preserve o memo anterior e qual sua chave;
  - um exemplo confirmado de abertura e execução separadas, mesma empresa/filial/O.S.;
  - se TQB/Solicitação é uma fonte possível, comprovar as chaves e o vínculo real
    antes de relacionar TJ_SOLICI com qualquer campo presumido.

  Nenhuma consulta acima faz alteração de estado nem grava a O.S.
*/
