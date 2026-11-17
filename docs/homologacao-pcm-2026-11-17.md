# Homologação PCM — 17/11/2026

**Correção posterior de exibição:** a [pesquisa e o Excel exibem o fim real da manutenção por TJ_DTMRFIM](correcao-fim-real-manutencao-2026-11-17.md), como os detalhes. Isso substitui a mudança de exibição descrita abaixo; o campo TJ_DTPRFIM permanece no critério homologado dos indicadores.

**Atualização da apresentação:** a [correção do período padrão da TV](tv-periodo-padrao-2026-11-17.md) substitui o bloqueio das concluídas sem filtro manual por um ciclo operacional automático e configurável, preservando a carteira de abertas. Os demais critérios de data e status abaixo continuam válidos.

## Divergências corrigidas

- Concluídas eram filtradas por início planejado. O campo confirmado no importador, no adaptador Protheus e na documentação existente é **Real. Fim = TJ_DTPRFIM = general_actual_end**. `TJ_DTMRFIM` corresponde a R. Fim Man., campo adicional. Conclusão ausente/inválida não recebe substituição por origem, planejamento ou R. Fim Man.
- O recorte dos indicadores agora usa **Real. Fim para situação L/término S** e **origem para abertas**. A pesquisa de emitidas continua usando exclusivamente `TJ_DTORIGI`. Os links do dashboard conservam o critério dos indicadores sem converter silenciosamente o período em filtro de emissão.
- A origem mínima de 2025 nas consultas setoriais excluía O.S. antigas ainda abertas e concluídas dentro do período. Esse limite foi removido da carteira setorial. A seção explicitamente histórica/analítica do dashboard conserva seu universo próprio, identificado na interface; ela não é o total operacional da TV.
- O filtro de período era repetido no JOIN de hidratação da pesquisa/exportação, reutilizando parâmetros nomeados e provocando erro no driver SQL Server. Agora entra uma vez, antes da paginação.
- A coluna Fim real da pesquisa e o fechamento no Excel geral apontavam para R. Fim Man. Agora usam Real. Fim. Textos de ajuda e metadados dos relatórios identificam os critérios corretos.

Status continua determinado pelos controles do sistema, não pela presença de datas: L/N aberta, L/S fechada, C cancelada e excluída dos indicadores. As abertas de Entressafra permanecem na carteira sem corte de ano quando não há intervalo, conforme decisão anterior do usuário. Sem período definido, os cartões sazonais de concluídas continuam sem um total histórico apresentado como atual.

## Resultados com SELECT no SQL Server

Janela técnica escolhida para conferir a implementação: **01/01/2026 a 17/11/2026**, inclusiva. Não define calendário agrícola oficial. Consultas realizadas pela aplicação local, com o driver `ProtheusReadOnly` e consultas registradas na lista permitida.

| Indicador | Safra | Entressafra |
| --- | ---: | ---: |
| Abertas no recorte de origem | 194 | 1.362 |
| Concluídas no recorte de Real. Fim | 2.186 | 52 |

- **Zero divergências** entre consulta de reconciliação, indicadores da TV, dashboard e os cartões das **12 telas** geral/setoriais, por Safra/Entressafra, Área de Manutenção e status.
- Pesquisa vinculada ao dashboard: **2.238 concluídas**, igual à soma da TV.
- Excel completo: **2.238 linhas, 2.238 números de O.S. distintos**, conferidos também no XML do XLSX. Datas de fechamento dentro do intervalo; números textuais e zeros à esquerda preservados.
- Emitidas por Data de origem: **3.350**, com pesquisa e SELECT de contagem equivalentes. Não se espera igualdade desse total com concluídas, pois os critérios medem eventos diferentes.
- **208 testes, 5.598 asserções passaram**, cobrindo períodos, limites inclusivos, origem anterior ao período com conclusão dentro dele, ausência de conclusão sem fallback, status/cancelamento, áreas, filtros, paginação, exportações, Serviço Executado, rotas, checklist de segurança e acesso da TV.
- PHP lint: **47 arquivos**, sem erros. Dois JavaScripts alterados passaram em `node --check`; `git diff --check` sem erros.

Artefatos locais:

- `docs/sql/pcm-tv-reconciliation.sql`: três instruções exclusivamente SELECT/CTE, com o recorte técnico preenchido — reconciliação por safra/área/status, inconsistências e emitidas por origem.
- `bin/pcm-homologate.php`: prepara o SQL e, com `--live`, compara as consultas e gera o Excel completo.
- `tmp/pcm-homologation.json`: totais e inconsistências da comparação efetiva.
- `tmp/pcm-homologation-completed.xlsx`: Excel completo usado na conferência.
- `tmp/homologacao-tests.xml`: resultado JUnit.

Reprodução:

```powershell
php bin/pcm-homologate.php 2026-01-01 2026-11-17 --live
```

## Pendências de dados e alcance

A verificação da base completa, independentemente da janela técnica, retornou **83 O.S. para conferência**: 65 concluídas sem Real. Fim válido, três com Real. Fim posterior a 17/11/2026 e 15 com origem futura. Nenhuma identidade duplicada nessa verificação. Os registros sem conclusão válida ficam fora de um indicador de concluídas com período; continuam disponíveis para consulta histórica. Nada foi corrigido automaticamente.

A definição das datas de um calendário agrícola oficial permanece decisão operacional. A regra técnica de data de conclusão está definida e homologada. A conferência descrita é da implementação local contra a base consultada naquele momento, não uma validação visual em TV física nem publicação de versão.

**Sem deploy, sem alterações no Ubuntu e sem INSERT, UPDATE, DELETE ou alteração de estrutura no TOTVS.**
