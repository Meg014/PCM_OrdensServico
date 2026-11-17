# Auditoria final antes do deploy — 17/11/2026

**Regra posterior vigente:** [fechadas por TJ_TERMINO S, canceladas excluídas, sem exigência de data sem período](regra-definitiva-fechadas-2026-11-17.md). Esta orientação substitui as referências abaixo ao período automático da TV e à exclusão das 65 O.S. da contagem sem período; os resultados abaixo são o registro da auditoria anterior.

**APROVADO na auditoria local da aplicação**, com as exceções de dados já documentadas e preservadas. Não houve deploy, alterações no Ubuntu ou escrita no TOTVS. Nenhuma funcionalidade ou regra de negócio foi adicionada.

## Problemas encontrados e correções

1. **Bug comprovado:** URLs de atualização automática da TV/dashboard passavam por escape HTML duas vezes, produzindo `&amp;amp;`. Após a decodificação do navegador, parâmetros podiam chegar com nomes como `amp;area`, perdendo filtros. Removido somente o escape duplicado, mantendo o escape padrão do UrlHelper. A mesma correção foi aplicada aos links/refresh legados afetados. A regressão agora verifica a URL renderizada via DOM e a presença dos parâmetros de filtro.
2. Um teste de integração ainda esperava o rótulo antigo “Elétrica e Instrumentação”. Atualizada a expectativa para “Elétrica/Instrumentação”, conforme a regra já homologada. Nenhuma alteração no mapa de áreas.

## Resultados por SELECT no SQL Server

Conferência atual; números não são constantes do código:

| Indicador | Safra | Entressafra |
| --- | ---: | ---: |
| Abertas da carteira ativa | 198 | 1.362 |
| Fechadas por TJ_DTPRFIM, 01/01–17/11/2026 | 2.186 | 52 |

- **Zero divergências** na reconciliação dos quatro cartões nas **12 telas disponíveis**: geral e 11 áreas com registros no universo consultado.
- **12 áreas homologadas**, representadas por 13 códigos de origem, pois ELETRI/INSTRU são agrupados. Códigos e nomes originais continuam rastreáveis; a ausência de movimento não cria artificialmente uma tela de área.
- Dashboard completo e TV retornaram os mesmos indicadores. Tempos de uma medição local: **TV 0,292 s**, **dashboard com detalhes 5,485 s**, dentro dos limites existentes de 5/12 segundos. Não é teste de carga nem medição do Ubuntu.
- Pesquisa de concluídas e Excel completo no recorte: **2.238 O.S. únicas**, com zeros à esquerda preservados. Excel inspecionado no XML, inclusive o cabeçalho “Fim real da manutenção”. Emitidas por origem: **3.354**, sem divergência com sua consulta de contagem; não são o mesmo evento das concluídas.
- Canceladas: consulta histórica disponível, **984 registros**, amostra de 20 contendo exclusivamente situação C. Canceladas permanecem fora das contagens operacionais.
- Datas de execução da pesquisa/detalhes/Excel usam o mapa central de manutenção; o campo geral dos indicadores não foi trocado pelo fim da execução. A O.S. 005810 permanece coberta pela regressão de tela, adaptador e Excel.

## Testes e verificações

- **415 testes PHP / 7.482 asserções: aprovados**, suíte completa. A primeira execução encontrou apenas a expectativa obsoleta de rótulo; a execução final completa passou após os ajustes.
- **42 testes JavaScript: aprovados**. Incluem atualização com filtros, rotação da TV, falhas de rede/sessão, autocomplete, detalhes e Serviço Executado.
- **313 arquivos PHP** com sintaxe válida; **15 arquivos JavaScript não minificados** com sintaxe válida. Os quatro templates corrigidos foram verificados novamente. `git diff --check` sem erros.
- Regressões de descrições de rotas, limpeza do checklist, Serviço Executado, status, cancelamento, filtros combinados, paginação, exportação e proteções SELECT passaram.

Artefatos locais: `tmp/final-audit-php.xml`, `tmp/final-audit-performance.json`, `tmp/tv-default-audit.json`, `tmp/pcm-homologation.json`, `tmp/pcm-homologation-completed.xlsx` e `tmp/final-audit-cancelled.json`. Consultas reproduzíveis: `docs/sql/pcm-tv-default-cycle.sql` e `docs/sql/pcm-tv-reconciliation.sql`.

## Ressalvas preservadas

- **65 O.S. L/S com TJ_DTPRFIM vazio** continuam concluídas no cadastro, consultáveis e fora do indicador por período. Não houve preenchimento, fallback de datas ou reclassificação. O relatório de inconsistências contém a relação individual e os campos alternativos, sem homologá-los como evento administrativo de encerramento.
- A verificação de qualidade mantém sinalizados três fins gerais futuros e 15 origens futuras. Nenhuma correção automática.
- Permanece o aviso do PHP local sobre OpenSSL carregado duas vezes; não impediu testes, SELECTs ou respostas da aplicação. É configuração do runtime local, não alteração autorizada nesta auditoria.
- O parecer cobre a aplicação local contra a base consultada. Configuração do ambiente de destino, validação visual em TV física e publicação não foram executadas.
