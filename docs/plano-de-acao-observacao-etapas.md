# Plano de Ação — observação das Etapas da O.S.

Atualização de 10/11/2026: a origem STQ010.TQ_OBSERVA foi confirmada e [integrada ao relatório em lote](plano-de-acao-servico-executado-stq.md). As limitações de permissão e a coluna vazia descritas abaixo registram a investigação anterior.

Investigação local em 09/11/2026. Sem deploy, alteração de dados ou permissões. Esta etapa confirma candidatos e prepara a validação no banco; não instala um relacionamento presumido no relatório.

## Identificação na documentação oficial

A documentação de APIs SIGAMNT da própria TOTVS identifica **STQ — Etapas Executadas** e o campo **TQ_OBSERVA — Observação da Etapa**. É a candidata principal para o campo mostrado na tela “Etapas da O.S. — Incluir”, em vez do memo do cabeçalho ou dos insumos. [TOTVS — APIs SIGAMNT/SIGAGFR](https://tdn-homolog.totvs.com/plugins/viewsource/viewpagesrc.action?pageId=811722649).

Outra documentação oficial identifica **STX** como histórico de etapas executadas, alimentado pelo acúmulo de histórico da STQ. Isso não autoriza juntar automaticamente dados atuais e históricos: o universo e os cabeçalhos correspondentes precisam ser validados. [TOTVS — Histórico de ordens de serviço](https://centraldeatendimento.totvs.com/hc/pt-br/articles/1500007196661-Manufatura-Linha-Protheus-MNT-Hist%C3%B3rico-de-ordens-de-servi%C3%A7o).

**Identificação padrão não equivale a confirmação na Biolasa.** Ainda faltam os metadados e uma amostra real que corresponda ao print, incluindo nome físico, tipo/encoding do memo e todas as chaves. Nenhuma descrição cadastral de tarefa/etapa será usada como observação de execução.

## Verificação da conexão real do PCM

Foram executados somente SELECTs de permissões e metadados, pelo driver `ProtheusReadOnly` e consultas fixas na allowlist.

| Objeto candidato | SELECT | Metadados/definição da estrutura |
|---|---:|---|
| dbo.ST5010 | 0 | Sem visibilidade para confirmar campos |
| dbo.STH010 | 0 | Sem visibilidade para confirmar campos |
| dbo.STQ010 | 0 | Sem visibilidade para confirmar TQ_OBSERVA/chaves |
| dbo.STX010 | 0 | Sem visibilidade para confirmar campos de histórico |
| dbo.STG010 / dbo.STN010 | 0 | Sem visibilidade |
| dbo.TPA010 / dbo.TT9010 | 1 | Cadastros acessíveis; não comprovam o memo digitado por O.S. |
| dbo.STJ010 / dbo.STL010 | 1 | Cabeçalho/insumos acessíveis, mantidos separados |

`OBJECT_ID` retornou nulo para os objetos sem acesso. Isso **não** demonstra inexistência: a visibilidade dos metadados depende da conta. Não houve SELECT de dados das tabelas sem permissão, busca em outra empresa como atalho, concessão de privilégios ou execução de procedures.

Evidência local: `tmp/action-plan-stage-permissions.json` e `tmp/action-plan-stage-metadata.json`.

## SQL pronto para a TI

1. Executar [etapas-permissoes.sql](sql/plano-de-acao-etapas-permissoes.sql). Retorna permissões, nomes/tipos dos campos, índices reais e descoberta do dicionário SX2/SX3/SIX visível.
2. Confirmar com a TI se o alias STQ corresponde a `dbo.STQ010` para a empresa correta e se o memo da tela é `TQ_OBSERVA`. Validar também ST5/STH e seus relacionamentos, sem presumir nomes de campos.
3. **Somente após essa confirmação**, executar [etapas-amostra.sql](sql/plano-de-acao-etapas-amostra.sql) com uma conta autorizada. O exemplo usa filial `01` e O.S. `007334` como texto; substitua apenas pelos identificadores do print, preservando zeros à esquerda.
4. Comparar `observacao_etapa_texto` com a observação digitada pelo funcionário na tela. A consulta traz `q.*` para expor todas as sequências reais sem inventar nomes que a conta PCM não consegue ler. Não descarta duplicidades por texto nem faz join com STL.
5. Retornar metadados/índices e amostras de várias etapas, tarefa/etapa repetida em outra sequência, observação preenchida, vazia e contendo somente checklist. Confirmar os tipos do memo antes de escolher a conversão definitiva; o exemplo usa `CONVERT(varchar(max), ...)`, como os memos binários textuais já consultados no projeto.

Os dois scripts contêm somente SELECTs e variáveis locais. Não incluem alterações de banco ou permissões. Se for necessária uma permissão para a integração, solicitar à TI apenas SELECT no objeto confirmado ou uma view restrita equivalente; não há motivo para conceder escrita ou acesso irrestrito à base.

## Relacionamento e agregação a validar

- O primeiro escopo é filial + número exato da O.S., sempre como texto.
- Dentro dele, preservar plano, tarefa, etapa e **todas as sequências reais** expostas no SX3/SIX/índices. `R_E_C_N_O_` serve para identificar o registro e desempatar a ordem, não como substituto da chave de negócio.
- Não supor equivalência entre campos de nomes parecidos: `TJ_SEQRELA`, `TL_SEQRELA` e um eventual `TQ_SEQRELA` podem ter semânticas diferentes. Não adicionar essas igualdades sem confirmação.
- Não relacionar etapas a todas as tarefas de mesmo nome, nem somente por código de etapa ou descrição. Não pressupor que o plano registrado na etapa sempre seja o mesmo valor do cabeçalho.
- Após a confirmação, carregar as etapas em lote para as O.S. selecionadas, agrupar por filial/O.S. e preservar o contexto de cada etapa em blocos na mesma célula N. Não fazer uma consulta por O.S. nem juntar diretamente múltiplas etapas e múltiplos insumos de forma a multiplicar as linhas.
- Textos iguais de etapas/contextos distintos devem continuar identificáveis. Deduplicação não será feita apenas pelo conteúdo da observação.
- Observação vazia ou que só contenha o checklist conhecido produz ausência de texto de execução; não se acrescenta frase genérica. Catálogos `TPA_DESCRI`/`TT9_DESCRI`, `TJ_OBSERVA` e `TL_OBSERVA` não serão usados como substitutos.
- A existência de observação de etapa não altera o status da O.S.; trabalho registrado em uma etapa não prova encerramento do cabeçalho.

Essa agregação ainda não foi conectada ao download: primeiro é necessária a amostra autorizada para comprovar o vínculo e o memo. As melhorias anteriores de desempenho permanecem preservadas.

## Estado das colunas e arquivos desta etapa

Conforme a confirmação do usuário sobre as duas telas:

- **SERVIÇO A REALIZAR (H):** o memo do cabeçalho continua vindo de `STJ010.TJ_OBSERVA` pelo fluxo existente; não foi substituído por texto de etapa nem pelo nome de cadastro de serviço.
- **SERVIÇO EXECUTADO (N):** não recebe `TJ_OBSERVA` ou `TL_OBSERVA`. Continua vazio enquanto não houver fonte de observação da etapa confirmada. O renderer Excel já preparado só aplica limpeza ao texto de execução explicitamente separado.

Arquivos desta investigação:

- `src/Service/Protheus/ActionPlanStageQueries.php`: SELECTs fixos de permissão e metadados.
- `src/Service/Protheus/ProtheusQueries.php`: permite somente essas duas consultas adicionais, sem consulta de dados das etapas.
- `docs/sql/plano-de-acao-etapas-permissoes.sql` e `docs/sql/plano-de-acao-etapas-amostra.sql`.
- `tests/TestCase/Service/ActionPlanStageInvestigationTest.php`: allowlist, bloqueio de SQL alterado, scripts sem escrita e amostra delimitada por filial/O.S.
- Este documento e referência adicionada ao relatório da investigação anterior.

Nenhuma migration, configuração de e-mail, cron, regra de notificações, filtro de unidade ou consulta normal do Excel foi modificada nesta etapa.

## Validação local

Executados `ActionPlanStageInvestigationTest`, `ActionPlanObservationTest`, `ActionPlanTest` e `ProtheusOrderAccessTest`: **29 testes e 549 assertions, todos aprovados**. Saída em `tmp/action-plan-stage-tests.log`. A execução utilizou SQLite de teste, sem enviar e-mails reais. Também passaram a verificação de sintaxe PHP dos arquivos novos e `git diff --check`.
