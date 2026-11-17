# Regra definitiva dos indicadores de O.S. fechadas

Esta orientação do usuário substitui as limitações anteriores por ciclo automático ou pela presença de data. Alteração local, sem deploy e sem escrita no TOTVS.

## Regra em vigor

- **TJ_TERMINO = S e O.S. não cancelada: fechada.** Não exige situação L, TJ_DTPRFIM, TJ_DTMRFIM ou qualquer data para ser contada sem período.
- TJ_TERMINO = N: não encerrada. As condições já homologadas dos indicadores de abertas permanecem intactas, incluindo elegibilidade e corte da Safra/exceção de Entressafra.
- Cancelamento C tem precedência sobre S/N.
- **Sem período informado:** todas as fechadas do conjunto filtrado por setor, unidade, equipamento, serviço e classificação. Não se aplica ano/ciclo automaticamente.
- **Com período informado:** mantém-se o filtro existente. Nos indicadores gerais/setoriais, fechadas usam a data geral TJ_DTPRFIM e abertas usam origem. A pesquisa de emitidas mantém origem. Um registro sem data não atende a um intervalo explícito, mas continua fechado e conta normalmente sem esse intervalo.
- Nome Serviço contendo ENTRESSAFRA define Entressafra; demais nomes pertencem à Safra. Sem alteração no mapa de áreas.

## Correções

`ProtheusOperationalEligibility::CLOSED`, `closed()` e `isClosed()` centralizam o status. A regra foi aplicada às consultas de TV, setores, equipamentos e plano de ação, às apresentações e às etiquetas de situação. O resolvedor de importação também reconhece S e C, mantendo as formas textuais Sim/Cancelado e registrando versão 5 para novas importações. Não houve reimportação nem alteração de registros existentes.

Removidos o recorte automático de concluídas na TV e o bloqueio visual dos cartões sem período nos setores/apresentação legada. Foram eliminadas as mensagens “Concluídas aguardam...” das telas. As datas originais, a exibição do fim real da manutenção por TJ_DTMRFIM e os alertas de auditoria foram preservados.

## Validação direta no SQL Server — somente SELECT

Valores da consulta realizada, não constantes do código:

| Tela / classificação | Abertas | Fechadas sem período |
| --- | ---: | ---: |
| Mecânica — Safra | 148 | 2.436 |
| Mecânica — Entressafra | 715 | 513 |
| Geral/TV — Safra | 212 | 4.054 |
| Geral/TV — Entressafra | 1.362 | 810 |

As 151 abertas de Safra citadas pelo usuário são uma referência de tela anterior; o SELECT atual e a página consultada retornaram 148. Não se fixou esse número nem se alterou a regra de abertas.

- Consulta independente por TJ_TERMINO S, cancelamento excluído e `COUNT_BIG(DISTINCT filial + separador + número)`, sem qualquer predicado de data, confirmou as fechadas da Mecânica e os totais gerais.
- Reconciliação da TV: **zero divergências nas 13 telas**, geral mais 12 áreas com dados do conjunto sem período. Total de abertas antes/depois do escopo da TV: **1.574**, sem alteração por período automático.
- **005810/01:** aparece na seleção de fechadas de Entressafra/Mecânica sem período, mesmo com TJ_DTPRFIM vazio. Com filtro explícito 01/01–17/11/2026, não atende ao intervalo da data geral e a seleção retorna zero, conforme o filtro existente.
- As 65 O.S. sem TJ_DTPRFIM permanecem inconsistências de preenchimento, mas **contam como fechadas sem período**. Não foi preenchida nenhuma data nem mudado o status na origem.
- A agregação antes dos cadastros, o controle de identidade por filial/número e a rejeição de identidades ambíguas permanecem ativos. O SELECT independente confirma os totais por identidades distintas.

## Testes e evidências

**416 testes PHP / 7.498 asserções passaram**, além de **42 testes JavaScript**. Regressões incluem situação P/S fechada, cancelamento C/S excluído, ausência de campos de data na contagem, importação dos códigos S/N/C, abertas inalteradas, filtros manuais, cartões visíveis sem período, descrições de rotas, Serviço Executado, checklist, pesquisa e Excel.

Artefatos: `tmp/closed-rule-final-tests.xml`, `tmp/closed-rule-live.json`, `tmp/closed-rule-independent-validation.json`, `tmp/tv-default-audit.json` e `docs/sql/pcm-fechadas-por-termino.sql`. O comando `php bin/tv-default-audit.php` foi ajustado para validar a regra sem período; não aplica mais ciclo automático.

Sem deploy, sem alterações no Ubuntu e sem alterações de dados no TOTVS.
