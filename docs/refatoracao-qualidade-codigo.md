# Refatoração de qualidade do PCM

## Objetivo e limites

Esta refatoração melhora a manutenção do código sem alterar regras de negócio, resultados dos dashboards, estrutura do banco ou aparência aprovada. A única extensão funcional é o fluxo solicitado para o arquivo fixo `Relatorio_OS_ATUAL.xlsx`.

## Problemas encontrados

- O importador dependia do padrão `Relatorio_OS_YYYY-MM-DD.xlsx` para obter a data do snapshot.
- O processamento antigo movia arquivos da origem para `processados`; agora toda a pasta oficial é somente leitura.
- A consulta do detalhe da OS atual estava implementada diretamente no controller.
- A documentação histórica da fase 1 ainda apresentava a regra v2 como se fosse vigente.
- A ferramenta de estilo já configurada aponta dívida antiga, principalmente templates compactados em linhas extensas e ausência de docblocks exigidos pelo padrão local.

## Refatorações realizadas

- A regra de STATUS permanece exclusivamente em `WorkOrderStatusResolver`, na versão 4: cancelada, fechada ou em aberto. Datas reais não participam da classificação.
- `CurrentSnapshotService` centraliza importação atual, consulta atual, filtro por setor, áreas e detalhe da OS atual.
- `ReportImportService` continua sendo o único responsável pela persistência da fotografia e agora aceita uma data operacional confiável quando o nome não contém data.
- `ReportFileProcessor` diferencia o arquivo operacional fixo dos arquivos legados datados.
- A detecção de conteúdo já importado foi encapsulada em consulta por SHA-256 antes de copiar/processar o arquivo fixo.
- Diretórios, nome operacional, aba, limites, timezone e parâmetros de agendamento são configuráveis por ambiente.

## Fluxo de `Relatorio_OS_ATUAL.xlsx`

1. O agendador encontra o arquivo configurado na pasta de entrada.
2. O sistema espera a idade mínima configurada e verifica que o arquivo não está em uso.
3. Calcula o SHA-256 do conteúdo.
4. Se o hash já pertence a uma importação bem-sucedida ou em processamento, encerra sem importar novamente.
5. Se o hash é novo, copia o arquivo para uma área temporária e importa uma fotografia completa.
6. A fotografia bem-sucedida passa a ser a carteira atual pelo maior `report_date` e, em empate, maior identificador de importação.
7. A cópia temporária é arquivada com data e hora; todos os arquivos originais permanecem na entrada.
8. Dashboards operacionais consultam somente o `report_import_id` atual. Histórico compara importações separadamente.

## Fonte CSV

O fluxo suporta CSV como fonte principal sem alterar o contrato das 57 colunas usado pelo XLSX. O CSV de 28/08/2026 foi identificado como Windows-1252, delimitado por ponto e vírgula e com duas linhas de preâmbulo antes do cabeçalho. O leitor converte o conteúdo para UTF-8, valida integralmente os cabeçalhos e entrega as mesmas posições ao `TotvsRowMapper`. Assim, STATUS, dimensões, datas temporais e payload bruto usam exatamente o mesmo processamento nos dois formatos.

## Decisões arquiteturais

- Não foi criada tabela física de “carteira atual”; isso evitaria duplicação e risco de divergência.
- Não foram criadas interfaces ou camadas adicionais sem necessidade concreta.
- Não foram adicionados índices: as consultas operacionais já usam `report_import_id`, coberto pelo índice único composto existente, e não houve evidência de gargalo que justificasse migração.
- O payload bruto continua fora das consultas resumidas e só é carregado no detalhe da OS.
- A data do arquivo fixo usa seu `mtime` no timezone configurado, pois o XLSX não possui uma data de snapshot confiável em célula.

## Código removido e duplicações eliminadas

- A montagem ORM do detalhe atual foi removida do controller e consolidada em `CurrentSnapshotService::order()`.
- A verificação de hash vigente passou a ser reutilizável em `ReportImportService::hasImportedHash()`.
- Não houve remoção especulativa de código, CSS ou JavaScript sem cobertura suficiente.

## Itens deliberadamente não alterados

- Dashboards setoriais, gráficos, filtros, rankings, análises e detalhes existentes.
- Esquema e índices do banco.
- Fórmulas históricas ainda usadas exclusivamente em `/pcm/analises`.
- Layout visual, CSS e JavaScript aprovados.
- Migrações antigas, que permanecem como trilha técnica; a documentação da fase 1 foi marcada como histórica.

## Testes

- Teste do parser para o nome operacional com data confiável fornecida pelo processador.
- Teste do comando cobrindo permanência do arquivo na entrada, cópia arquivada, importação inicial e rejeição por SHA-256 idêntico.
- Teste preexistente de substituição de carteira: snapshots com 100 e 120 OS mantêm 220 registros históricos, mas retornam total atual 120.

Resultados finais:

- PHPUnit: 66 testes e 229 asserções, todos aprovados.
- PHP lint: 106 arquivos, sem erros de sintaxe.
- PHPCS nos arquivos PHP modificados do fluxo e da arquitetura: zero erros; permaneceram 19 avisos de linhas longas preexistentes em `ReportImportService` e `PcmController`.
- PHPCS global: executado; encontrou 209 erros e 185 avisos preexistentes em 49 arquivos, concentrados em templates compactados, docblocks exigidos pela configuração e linhas extensas.
- PHPStan: configurado no repositório, mas o executável não está instalado em `vendor` neste ambiente.

## Pendências conhecidas

- O agendamento é responsabilidade do Agendador de Tarefas/serviço do servidor; a aplicação apenas expõe o comando e a configuração de intervalo recomendada.
- A dívida de estilo preexistente nos templates compactados deve ser tratada incrementalmente com testes visuais, evitando uma reescrita cosmética de alto risco. O PHPCS global ainda não passa por essa dívida histórica.
