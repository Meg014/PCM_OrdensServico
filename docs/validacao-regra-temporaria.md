# Validação da regra temporal temporária — 15/09/2026

A implementação já presente no commit `3ebc724` foi revisada e preservada. Esta validação substitui a descrição da regra global em `regra-operacional-2026.md`.

## Regra centralizada

`WorkOrderSnapshotsTable` define separadamente `operationalOpenConditions()` (EM ABERTO e P. In. Man. >= 01/01/2026) e `operationalClosedConditions()` (FECHADA, sem restrição de data). Os finders `operationalOpen` e `operationalClosed` reutilizam essas condições. O finder `operational` combina as duas com OR, sem incluir CANCELADA. Fechadas com data nula também são elegíveis.

`CurrentSnapshotService::query()` restringe a consulta à última importação bem-sucedida e seleciona o finder correspondente ao status. Indicadores, setores, TV, listagens, filtros, rankings e comparativo gerencial reutilizam esse escopo. Qualidade e movimentações operacionais usam o mesmo finder com a restrição de importação. Consultas de histórico permanecem intactas.

Arquivos da implementação revisada:

- `src/Model/Table/WorkOrderSnapshotsTable.php`
- `src/Service/CurrentSnapshotService.php`
- `tests/TestCase/Service/PcmOperationalRevisionTest.php`

O aviso visual removido anteriormente permanece ausente. Classificações e regras de status não foram alteradas.

## Totais reais

Consulta no banco da aplicação em transação `READ ONLY`, encerrada com rollback. Última importação bem-sucedida: **2**, relatório **14/09/2026**, concluída em **14/09/2026 às 13:22:19**, horário de São Paulo.

| Setor | Safra abertas 2026+ | Safra fechadas todos os anos | Entressafra abertas 2026+ | Entressafra fechadas todos os anos |
|---|---:|---:|---:|---:|
| GERAL | 164 | 3208 | 48 | 758 |
| CALDEI | 7 | 332 | 1 | 36 |
| ELETRI | 30 | 609 | 0 | 111 |
| INSTRU | 2 | 350 | 0 | 0 |
| MECANI | 119 | 1779 | 47 | 483 |
| OPERAC | 4 | 11 | 0 | 0 |
| TERCEI | 0 | 0 | 0 | 4 |
| USINAG | 2 | 127 | 0 | 124 |

- Fechadas anteriores a 2026 incluídas pela mudança: **2849**.
- Abertas anteriores a 2026 que continuam ocultas: **212**.
- Abertas antigas presentes no escopo operacional: **0**.
- Canceladas presentes no escopo operacional: **0**.
- Fechadas com P. In. Man. nulo na importação: **0**.
- Total operacional: **4178**, sendo **212 abertas** e **3966 fechadas**.
- PRE: **2**, COR: **209**, MEL: **1**, Emergenciais: **0**, Programadas: **44**; todos são indicadores de abertas 2026+.

Passaram **384 comparações reais** entre cards, contagens das listagens com filtros e TV, no geral e em todos os setores. A auditoria reproduzível está em `tmp/verify-temporary-scopes.php` (arquivo local, fora do versionamento).

## Testes

Suíte completa executada com `php vendor/phpunit/phpunit/phpunit`, usando `DATABASE_TEST_URL` apontando para SQLite isolado e exclusivo em `tmp/temporary-scopes-full-*.sqlite`.

Resultado: **91 testes, 667 asserções; 89 testes passaram**. Os dois testes de `TotvsCsvReaderTest` não passaram (uma falha e um erro) porque falta o arquivo externo `E:/projetos/PCM/relatorios_teste/Relatorio_OS_2026-08-28.csv`. Nenhum teste foi removido ou ignorado para ocultar essa limitação.

Cobertura relacionada: abertas de 2024/2025 e data nula excluídas; abertas de 2026/2027 incluídas; fechadas antigas, atuais, futuras e com data nula incluídas; canceladas excluídas inclusive com Término Sim; importações antigas/falhas excluídas; quatro cards de Safra/Entressafra; PRE/COR/MEL; filtros compostos e contraditórios; igualdade entre cards e listagens; paginação de fechadas antigas no geral e setor; detalhes de fechada antiga acessíveis; aberta antiga bloqueada; totais da TV por setor e geral; resumo, gráficos de status, qualidade e comparativo gerencial.

`node --test tests/JavaScript/pcm-presentation.test.cjs`: **1 teste passou**, verificando contadores e rotação da TV. `git diff --check`: sem erros.

Nenhuma alteração no banco da aplicação, migrations, importação, raw_payload ou snapshots. Nenhuma nova importação foi executada.
