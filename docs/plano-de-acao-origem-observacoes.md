# Plano de Ação — origem da descrição e do Serviço Executado

**Complemento posterior:** o usuário confirmou que a observação do cabeçalho é Serviço a Realizar e que a observação buscada para execução pertence à tela de etapas. A investigação específica identificou o candidato padrão STQ/TQ_OBSERVA, com leitura bloqueada na conta atual. Consulte [Observação das Etapas da O.S.](plano-de-acao-observacao-etapas.md) e os dois SQLs para a TI. As dúvidas históricas abaixo registram a investigação anterior; não foi estabelecido uso de TJ_OBSERVA como execução.

Investigação local de 09/11/2026. Apenas SELECT no Protheus. Sem deploy, alteração de registros, alteração de permissões ou tentativa de contornar fontes não autorizadas.

## Resultado e limite da implementação

O texto informado para `007334/01` foi confirmado em `STJ010.TJ_OBSERVA`. Isso confirma **onde o texto está atualmente**, mas não confirma uma fonte exclusiva de fechamento nem uma versão da descrição na abertura.

Conforme a orientação posterior do usuário:

- A presença de checklist não indica execução ou fechamento.
- `TJ_TERMINO = S` também não prova que o texto atual de `TJ_OBSERVA` foi digitado na finalização; o campo pode conter a descrição de abertura.
- Não há reconhecimento por tempo verbal, palavras como “foi feito”, tipo de serviço ou aproximação de texto.
- O produtor atual do relatório **não preenche `executed_service`** a partir de `TJ_OBSERVA`, `TL_OBSERVA`, checklist, status ou cadastro do serviço. Portanto, Serviço Executado continua vazio nos casos de origem ambígua, inclusive `007334/01` na leitura atual.
- A limpeza de checklist está preparada exclusivamente no renderer Excel da coluna N, quando receber um texto de execução separado e confirmado. Ela não modifica o banco, os apontamentos, a observação original nem a coluna H.

## De onde vem SERVIÇO A REALIZAR hoje

O rastreamento do código não encontrou uma fonte temporal separada de “texto na abertura”. A cadeia atual é:

| Etapa | Origem e comportamento |
|---|---|
| `ActionPlanQuery::sql()` | `CONVERT(varchar(max), STJ010.TJ_OBSERVA) AS description`. Cabeçalho identificado por filial e O.S. |
| `ActionPlanService::group()` | Prioriza esse memo atual; se não houver texto útil, usa a primeira observação útil dos apontamentos ativos, em ordem cronológica; se ambas faltarem, usa o nome do serviço. |
| Apontamento alternativo | `STL010.TL_OBSERVA`, ligado por `TL_FILIAL = TJ_FILIAL` e `TL_ORDEM = TJ_ORDEM`. Não é uma versão anterior do memo do cabeçalho. |
| Serviço alternativo | `TJ_SERVICO = ST4010.T4_SERVICO`, respeitando filial exata e compartilhada. A consulta do Plano de Ação utiliza `T4_NOME`, não uma descrição específica da O.S. |
| `ActionPlanExcelReport::write()` | A chave `description` é escrita em H, região do cabeçalho SERVIÇO A REALIZAR. A apresentação existente aplica `OrderDescriptions::display()`. |

No detalhe da O.S. há outro fallback: `OrderDescriptionQueries::BATCH` e `ProtheusRepository::findOrder()` também podem usar `ST4010.T4_DESCRIC` como descrição cadastral detalhada. Isso **não** transforma `T4_DESCRIC` em descrição original da abertura. O nome interno `original_description` no detalhe identifica o texto corrente lido do cabeçalho, não um histórico auditado de versões anteriores.

Nenhuma substituição nova foi feita na coluna H. Seu fluxo anterior foi preservado. **Isso não confirma que H contenha a descrição de abertura**: se o mesmo memo foi alterado no Protheus, o PCM não dispõe, nas fontes atualmente acessíveis, de sua versão anterior para restaurar. Não foi feito fallback novo para um texto genérico de ST4 para mascarar essa ausência.

## Conferência da O.S. 007334, filial 01

| Campo/consulta | Resultado observado |
|---|---|
| `TJ_ORDEM`, `TJ_FILIAL` | `007334`, `01`, preservados como texto |
| `TJ_SERVICO` | `CORMEC` |
| `TJ_CODBEM` | `ELV 80 001` |
| `TJ_SITUACA`, `TJ_TERMINO` | `L`, `N` |
| `TJ_DTMRINI`, `TJ_DTMRFIM`, `TJ_USUAFIM` | Vazios |
| Apontamentos ativos de STL010 | Nenhum na leitura efetuada |
| `TJ_SOLICI`, `TJ_IRREGU`, `TJ_OSORIG`, `TJ_ORDEPAI`, `TJ_FILORI` | Vazios; não comprovam uma origem alternativa para esta O.S. |
| `TJ_OBSERVA` | 471 bytes, contendo o texto indicado pelo usuário, cinco perguntas, preenchimento de espaços e NUL final |
| `ST4010.T4_NOME`, filial 01 | `CORRETIVA MECANICA` — nome cadastral |
| `ST4010.T4_DESCRIC`, filial 01 | Apenas NUL; não recupera a descrição de abertura |

O texto conferido foi:

> foi feita a troca da correia do elevador 705.1 fizemos mltirao para fazer a substituiçao o mais rapio possivel

A conversão já usada pelo projeto, `CONVERT(varchar(max), TJ_OBSERVA)`, retornou os acentos corretamente pela conexão SQL Server. O prefixo hexadecimal foi conferido contra o texto: não foi tratado como número, hexadecimal textual ou UTF-16 por suposição. O texto original não foi regravado. Apenas na apresentação Excel, NUL e preenchimento final não imprimível são retirados; grafia, caixa, pontuação, espaços internos e quebras das observações úteis são preservados.

## Regra de status e investigação de histórico

O PCM já usa `TJ_SITUACA = L` com `TJ_TERMINO = N` para abertas e `TJ_SITUACA = L` com `TJ_TERMINO = S` para finalizadas. Cancelamento por `TJ_SITUACA = C` tem precedência. Essa regra existente não foi alterada nem substituída por checklist, data, nome de serviço ou texto livre.

A documentação oficial da TOTVS descreve S/N no indicador de término e L/P/C na situação. Isso sustenta a leitura dos indicadores, **não** prova a origem temporal de uma observação ou a rotina/customização utilizada nesta instalação. [TOTVS — campos principais de STJ](https://centraldeatendimento.totvs.com/hc/es/articles/43577217023639-TOTVS-MANUFACTURA-TOTVS-Manufactura-L%C3%ADnea-Protheus-MI-Mantenimiento-de-Activos-SIGAMNT-Para-qu%C3%A9-se-utiliza-la-tabla-STJ-%C3%93rdenes-de-Servicio-y-cu%C3%A1les-son-sus-campos-principales-de-identificaci%C3%B3n-clasificaci%C3%B3n-fechas-y-costos).

Há confirmação oficial de que `TJ_OBSERVA` pode ser modificado na liberação e receber observação no cancelamento, portanto não é exclusivo de finalização. [TOTVS — Liberação de O.S.](https://tdn-homolog.totvs.com/pages/releaseview.action?pageId=799273899).

Metadados locais de STJ010:

- `NON_TEMPORAL_TABLE`, sem tabela temporal de histórico associada.
- CDC e Change Tracking desabilitados nessa tabela, segundo os indicadores retornados.
- Trigger visível `STJ010_STAMP`, habilitada; a definição não está visível para a conta do PCM. O nome não foi interpretado como prova de auditoria ou da lógica de encerramento.
- Não foi identificado um histórico específico de memo ou uma fonte separada de finalização nos metadados visíveis à conta. Essa visibilidade é limitada e **não demonstra que uma fonte inacessível não exista**.
- O SX3 não ficou visível nessa investigação. A rotina ADVPL/API, seus pontos de entrada/customizações e o dicionário real precisam ser confirmados pela TI antes de estabelecer o comportamento efetivo da finalização/reabertura.

A API oficial também documenta `TQB_DESCSS` como descrição de solicitação. É somente uma **candidata para investigação**, não foi adotada como fonte da O.S. nem foi inventada uma chave de relacionamento. Para `007334/01`, `TJ_SOLICI` está vazio. [TOTVS — APIs SIGAMNT/SIGAGFR](https://tdn-homolog.totvs.com/plugins/viewsource/viewpagesrc.action?pageId=811722649).

## SQL para o usuário/TI

Arquivo pronto: [sql/plano-de-acao-investigar-observacoes.sql](sql/plano-de-acao-investigar-observacoes.sql).

O script usa filial e O.S. em variáveis de texto, inicia com `01`/`007334` e contém somente leituras e variáveis locais. Inclui cabeçalho, memo textual/hexadecimal, ST4, STL, metadados de fontes candidatas, histórico temporal/CDC/Change Tracking, triggers e descoberta de SX3. O exemplo de consulta ao dicionário permanece comentado até a TI confirmar seu nome e colunas. Não são criadas tabelas temporárias nem executadas definições de triggers/procedures.

Para fechar a integração faltam: campo/tabela e chave confirmados para a descrição específica de abertura; fonte/campo ou evento de histórico confirmado para o texto de execução; rotina/regra de finalização e reabertura usada pela empresa; um registro que demonstre os dois textos separados. Só depois se deve adicionar uma consulta em lote e alimentar as duas colunas.

## Código e testes desta etapa

- `src/Service/ActionPlanObservation.php`: apresentação Excel das duas famílias conhecidas de checklist (curta e extensa), opções marcadas/vazias, caixa/acentos e NR-12/NR12/NR.12. Remove apenas trechos reconhecidos em linhas de pergunta; frases legítimas/variações não reconhecidas não são apagadas.
- `src/Service/ActionPlanExcelReport.php`: N aceita somente a chave separada `executed_service`; ausência dessa chave produz célula vazia. Não usa `description` como alternativa para N e não altera H a partir de N.
- `src/Service/Protheus/ActionPlanObservationQueries.php` e `ProtheusQueries.php`: três consultas fixas de diagnóstico somente leitura, adicionadas à allowlist; não são consultas automáticas do download.
- `tests/TestCase/Service/ActionPlanObservationTest.php`: caso 007334 com a grafia original, preservação de observações antes/entre/depois, checklist curto/extenso, opções/acentos/caixa, conteúdo vazio e sem checklist, separação de H/N com fontes **simuladas e explicitamente separadas**, ausência de inferência por checklist/status e diagnóstico somente leitura.
- Este documento e o script SQL.

O teste com abertura simulada comprova a separação técnica das colunas; **não** é apresentado como descoberta da descrição histórica real de 007334. A consulta real e o texto original dessa O.S. foram preservados em arquivos de conferência locais em `tmp/action-plan-007334-original.json` e `tmp/action-plan-execution-{header|sources|history}.json`, fora dos arquivos de implantação.

Não há migration. Nenhum registro do TOTVS ou banco operacional do PCM foi alterado.

Validação: suíte PHP executada com **366 testes e 6.503 asserções aprovadas**; após acrescentar as duas verificações finais de não inferência/diagnóstico, a rodada específica atual passou com **27 testes e 532 asserções**. Relatórios: `tmp/action-plan-execution-final.log`, `tmp/action-plan-execution-final.xml` e `tmp/action-plan-execution-focused.log`. Os bancos de testes são SQLite separados; nenhum teste faz escrita no Protheus. Sintaxe PHP e `git diff --check` também foram conferidos.
