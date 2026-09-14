# PCM — regra operacional global 2026+

## Regra implementada

O finder `WorkOrderSnapshotsTable::findOperational()` centraliza `maintenance_planned_start >= 2026-01-01` e `treated_status IN (EM ABERTO, FECHADA)`. O campo é P. In. Man., mapeado das colunas de data/hora pelo `TotvsRowMapper`. Datas nulas são excluídas pela comparação SQL. `CurrentSnapshotService` combina esse finder com a última importação bem-sucedida, mantendo a seleção existente pelo maior ID.

Safra é o complemento da classificação ENTRESSAFRA retornada pelo classificador existente. A normalização e a precedência existentes foram preservadas. Aberta/fechada continuam usando o status tratado de Situação/Término; os campos de execução real não determinam esse status.

Nenhuma migração, reimportação ou alteração de dados de produção foi executada. Snapshots, raw_payload, source_row_number e importações antigas permanecem intactos. A conferência no MariaDB foi executada em transação somente leitura, encerrada com rollback. Os testes usam SQLite isolado em tmp/operational2026-tests.sqlite.

## Telas e rotas revisadas / checklist manual

| Tela/ação | Aplicação e conferência manual |
|---|---|
| `/pcm` — Visão Geral | Aviso no cabeçalho; quatro cards Safra/Entressafra; PRE/COR/MEL e classificações no universo operacional. Clicar em cada card e conferir o total da listagem. |
| `/pcm/apresentacao` — TV | Aviso visível, quatro indicadores principais em duas linhas, seis cards menores; rotação por setores em 15 segundos e atualização em 30 segundos mantidas. Conferir em TV e celular. |
| `/pcm/apresentacao/data` | Mesmo serviço de indicadores da tela normal; inclui os quatro novos campos, sem carregar tabelas de OS. |
| `/pcm/setor/{code}` — todos os setores | Regra dinâmica, inclusive áreas futuras; quatro indicadores, tipos, classificações, gráficos, rankings, atenção, resumo por serviço, filtros, tabela e paginação usam o escopo operacional. |
| `/pcm/ordens` — listagem geral | Mesmo serviço dos setores. Filtros de Safra/Entressafra e links dos cards são aplicados no backend e preservados na paginação. |
| `/pcm/analises` — Visão Gerencial | Quatro indicadores, qualidade de dados e comparativo/ranking por setor usam a importação atual. Os painéis que acumulavam importações anteriores deixam de alimentar essa tela operacional. |
| `/pcm/analises/qualidade/{type}` | Contadores, percentuais e listagem usam a mesma consulta operacional; conferir o total do card contra a tabela. |
| `/pcm/os/{id}` — detalhes | Acesso direto bloqueado com 404 para OS fora do universo operacional. A seção explicitamente identificada como auditoria preserva o histórico da identidade da OS. |
| `/pcm/movimentacao/{type}` | URLs antigas só podem listar OS válidas da importação atual; ausentes e canceladas ficam vazias. Dados anteriores servem apenas ao contexto de comparação, sem entrar no universo listado. |
| `/pcm/current-version` | Mantém somente metadados da última importação bem-sucedida, usados na atualização automática. |
| Exportações | Não existem ações de exportação operacional no PcmController/rotas revisadas. Importações de CSV/XLSX são entrada de dados e foram preservadas. |
| Histórico/auditoria | Métodos históricos continuam disponíveis no PcmHistoryService e snapshots permanecem armazenados. Não alimentam os dashboards operacionais. |

Todos os cabeçalhos das páginas operacionais exibem “DADOS REFERENTES ÀS OS CRIADAS A PARTIR DE 2026”.

Os links usam restrições adicionais (`indicator` e `within`), preservando inclusive filtros contraditórios, para que um card com zero não abra uma lista com registros. Rankings de equipamentos/serviços agrupam pelo mesmo código usado no link da investigação.

## Conferência no MariaDB

Importação bem-sucedida **1**, relatório **2026-09-10**, conclusão **2026-09-10T13:05:17+00:00** (10/09/2026 às 10:05 em São Paulo).

| Área | Safra abertas | Safra fechadas | Entressafra abertas | Entressafra fechadas | Total |
|---|---:|---:|---:|---:|---:|
| GERAL | 147 | 1104 | 48 | 0 | 1299 |
| CALDEI | 7 | 133 | 1 | 0 | 141 |
| ELETRI | 30 | 193 | 0 | 0 | 223 |
| INSTRU | 2 | 45 | 0 | 0 | 47 |
| MECANI | 102 | 617 | 47 | 0 | 766 |
| OPERAC | 4 | 11 | 0 | 0 | 15 |
| TERCEI | 0 | 0 | 0 | 0 | 0 |
| USINAG | 2 | 105 | 0 | 0 | 107 |

Abertas por tipo/classificação: Preventivas **2**, Corretivas **192**, Melhorias **1**, Emergenciais **0**, Programadas **17**, Entressafra **48**. Listagem geral: **1.299 OS**. As classificações complementam os tipos; não devem ser somadas como grupos independentes. TV conferida contra o geral e cada setor.

## Testes e limitações

- 88 testes PHP e 513 asserções passaram na suíte executável, incluindo serviços, rotas e importação sem dependência do CSV externo.
- Teste JavaScript da TV passou: quatro novos contadores, rotação de 15 segundos e temporizador de atualização de 30 segundos.
- Cobertura da regra: anos 2024/2025, limite 01/01/2026, data 2027, data nula, canceladas, quatro grupos, complemento da Safra, PRE/COR/MEL, última importação versus importação falha, áreas futuras, filtros e contagem de links, acesso direto a OS antiga e aviso nos cabeçalhos.
- Dois testes preexistentes de `TotvsCsvReaderTest` não puderam passar porque `E:/projetos/PCM/relatorios_teste/Relatorio_OS_2026-08-28.csv` não existe. Foram executados na tentativa da suíte completa e excluídos da rodada executável. Nenhum dado foi inventado para substituí-lo.
- No refinamento visual posterior, a apresentação foi validada com Chromium/Playwright em 1920×1080 e 1366×768: altura do documento igual à janela, sem rolagem. Em 390×844, os cards ficam em uma coluna, sem transbordamento horizontal. Letras A/F removidas, números centralizados e espaços compactados. A rotação permanece inalterada.

Comando de teste: `php vendor/bin/phpunit --filter '^(?!.*TotvsCsvReaderTest)'`, com DATABASE_TEST_URL apontando para o SQLite isolado acima. JavaScript: `node --test tests/JavaScript/pcm-presentation.test.cjs`.

## Arquivos modificados

- `src/Controller/PcmController.php`
- `src/Model/Table/WorkOrderSnapshotsTable.php`
- `src/Service/CurrentSnapshotService.php`
- `src/Service/DataQualityService.php`
- `src/Service/PcmHistoryService.php`
- `src/Service/PcmIndicatorService.php`
- `src/Service/PcmPresentationService.php`
- `src/Service/PcmServiceClassifier.php`
- `src/Service/SectorDashboardService.php`
- `templates/Pcm/analyses.php`
- `templates/Pcm/index.php`
- `templates/Pcm/movement.php`
- `templates/Pcm/order.php`
- `templates/Pcm/presentation.php`
- `templates/Pcm/quality.php`
- `templates/Pcm/sector.php`
- `templates/element/pcm_indicator_cards.php`
- `templates/element/pcm_sector_comparison.php`
- `templates/element/pcm_service_cards.php`
- `tests/JavaScript/pcm-presentation.test.cjs`
- `tests/TestCase/Controller/PcmControllerTest.php`
- `tests/TestCase/Controller/PcmEmptyControllerTest.php`
- `tests/TestCase/Controller/PcmHistoryControllerTest.php`
- `tests/TestCase/Service/CurrentPortfolioReplacementTest.php`
- `tests/TestCase/Service/CurrentSnapshotServiceTest.php`
- `tests/TestCase/Service/DataQualityServiceTest.php`
- `tests/TestCase/Service/PcmOperationalRevisionTest.php`
- `webroot/css/pcm.css`
- `webroot/js/pcm-presentation.js`
- `templates/element/pcm_operational_notice.php`
- `docs/regra-operacional-2026.md`
