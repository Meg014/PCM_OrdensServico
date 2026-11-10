# Plano de Ação — Serviço Executado confirmado na STQ010

Implementação local de 10/11/2026. Fonte confirmada pelo usuário e validada por SELECT na conexão Protheus/DADOSP12: `dbo.STQ010.TQ_OBSERVA`, da tela Etapas da O.S.

## Consulta em lote

A consulta original das O.S., datas, status, apontamentos e filtros permanece. Depois de agrupar as O.S., é feita uma segunda consulta somente leitura, restrita às identidades realmente selecionadas. Para um resultado vazio, essa consulta é dispensada.

Filial e O.S. são enviadas como strings em um parâmetro JSON, preservando zeros. `EXISTS` contra `OPENJSON` evita multiplicação mesmo com identidades repetidas. Não há JOIN com a STL nem consulta por O.S. A exclusão lógica usa `D_E_L_E_T_ <> '*'`. NULL é descartado na consulta; vazio e espaços são ignorados na agregação. `TQ_OK` é lido, mas não restringe o texto nem modifica o status.

`CONVERT(varchar(max), TQ_OBSERVA)` converte o memo no SQL Server; o driver fornece UTF-8, como no restante da integração. Acentos foram validados nas O.S. reais. Texto inválido causa erro sem substituição silenciosa. Não há conversão presumida de outra codificação nem uso de TJ_OBSERVA/TL_OBSERVA para preencher execução.

## Agrupamento e texto

A ordenação é estável por filial, O.S., plano, tarefa, sequência da tarefa, etapa, sequência da etapa e registro físico. O PHP também ordena explicitamente. Observações de contextos diferentes são mantidas mesmo com texto idêntico; somente uma repetição idêntica do mesmo registro físico é descartada. Conteúdo conflitante para a mesma identidade causa erro.

Uma observação aparece sem prefixo. Várias observações aparecem em blocos identificados por plano/tarefa/etapa/sequências preenchidas, separados por quebras na mesma célula. A O.S. continua em uma única linha. O texto do funcionário, incluindo acentos, pontuação, espaços e quebras, não é corrigido ou reescrito. NUL de armazenamento é removido. O limite de 32.767 caracteres da célula continua protegido, sem truncamento silencioso.

## Excel

- H / PLANO DE AÇÃO (serviço a realizar): descrição da abertura pelo fluxo existente; as cinco perguntas conhecidas do checklist são removidas conservadoramente somente na renderização do Excel. A descrição retornada pelo agrupamento permanece sem essa limpeza. Observações legítimas antes/depois são preservadas.
- M / SERVIÇO EXECUTADO: observações confirmadas da STQ010. Não remove checklist desta coluna e não usa o texto da abertura.
- N / Status: última coluna, com os critérios e cores anteriores.

Preservados o modelo aprovado, somente uma aba, demais colunas, filtros até N, larguras, bordas, quebras, altura, zeros e geração direta em OOXML.

## Validação real e desempenho

Os cinco textos fornecidos pelo usuário foram encontrados na filial 01: **006889, 007356, 007324, 007307 e 007325**. A O.S. 006889 também possui a observação “FAZER 2 BUCHA” em outra tarefa; ambas foram preservadas em blocos identificados, sem inferir conclusão por TQ_OK. Evidência: `tmp/stq-action-plan-validation.json`.

No período 01/01/2026–10/11/2026: **3.691 O.S., sem linhas adicionais por etapa; 213 O.S. com observação de execução**. A leitura retornou 731 registros não NULL, incluindo registros depois ignorados por conterem somente espaços.

Medição local da carga: consulta principal 3.093,4 ms, agrupamento 37,7 ms e leitura/agregação da execução 77,4 ms. O comando que gerou o relatório anual e o arquivo das cinco O.S. levou 75,26 s, incluindo os arquivos e em paralelo com testes. Não é garantia de tempo de download em produção. A integração não introduz consultas por O.S.

## Testes e conferência

**Suíte PHP completa: 385 testes e 6.914 assertions, todos aprovados**. Log: `tmp/action-plan-execution-full.log`.

Os testes cobrem os cinco exemplos, exclusão lógica, conversão de BLOB no ambiente de teste, isolamento por filial, NULL/espaços, ausência de condição TQ_OK, acentos, zeros, múltiplas etapas, sequências, ordem, deduplicação por registro e duas consultas em vez de N+1. Conferem ainda o texto no Excel, a limpeza somente em H e STATUS no final. A conversão real no SQL Server foi validada separadamente.

Arquivos gerados com dados atuais:

- `tmp/Plano_de_Acao_conferencia_5_OS_STQ010.xlsx`: as cinco O.S. para conferência rápida.
- `tmp/Plano_de_Acao_STQ010_2026-01-01_2026-11-10.xlsx`: relatório completo do período.
- `tmp/Plano_de_Acao_conferencia_STQ010.pdf`: prévia das cinco O.S., renderizada pelo Excel local em modo somente leitura, sem salvar mudanças no arquivo.

## Arquivos desta revisão

- `src/Service/Protheus/ActionPlanExecutionQuery.php`.
- `src/Service/Protheus/ProtheusRepository.php`.
- `src/Service/Protheus/ProtheusQueries.php`.
- `src/Service/ActionPlanExecution.php`.
- `src/Service/ActionPlanService.php`.
- `src/Service/ActionPlanObservation.php`.
- `src/Service/ActionPlanExcelReport.php`.
- `tests/TestCase/Service/ActionPlanExecutionTest.php`.
- `tests/TestCase/Service/ActionPlanObservationTest.php`.
- Este documento e notas de atualização nas documentações anteriores.

Nenhuma migration necessária. A conta de integração precisa ter SELECT na STQ010 no ambiente de execução. Não houve deploy, acesso ao Ubuntu, escrita no TOTVS ou envio de e-mails.
