# Backlog separado dos checklists das rotas

Implementação local, sem deploy, sem acesso ao Ubuntu e sem escrita no TOTVS.

## Regras

- O bem é obtido de `STJ010.TJ_CODBEM`; o nome em `ST9010` continua sendo apenas apresentação. Não se usa descrição, tipo PRE ou palavra “preventiva” para identificar checklist.
- Prefixo `PREVELE FAB` corresponde a Elétrica (incluindo a apresentação unificada com Instrumentação). `PREVLUB FAB` e `PREVMEC FAB` correspondem a Mecânica. O prefixo deve ser o código inteiro ou ser seguido de espaço, evitando confusão com outros códigos.
- A classificação também respeita o setor registrado na O.S.; uma rota de lubrificação não se torna checklist de Elétrica.
- Exclusivamente no backlog e nos seus detalhamentos, esses bens pertencem à unidade Fábrica. O catálogo compartilhado de centros de custo e as classificações de outros relatórios permanecem preservados.
- Mantidos os critérios existentes de elegibilidade, exclusão lógica, Safra, filial, setor, filtros manuais e período de início planejado.
- Mantida a idade desde `TJ_DTORIGI` até a data de referência já utilizada no dashboard. As faixas normais continuam 0–7, 8–15, 16–30, 31–60 e mais de 60 dias.
- As faixas dos checklists dependem da família do bem: Mecânica (PREVLUB FAB/PREVMEC FAB), 0–7 EM DIA, 8–14 ATENÇÃO e acima de 14 CRÍTICO; Elétrica (PREVELE FAB), 0–17 EM DIA, 18–20 ATENÇÃO e acima de 20 CRÍTICO. Datas inválidas ou futuras permanecem em cards adicionais, exibidos somente quando houver registros.
- O total geral separa as duas populações. Nenhuma O.S. entra simultaneamente no backlog normal e no total de checklists.

## Consulta e interface

A consulta existente de backlog retorna as duas populações. O número de consultas do setor continua o mesmo (seis), sem consulta por O.S. ou hidratação dos apontamentos.

Os cards dos checklists reutilizam o tema escuro, a grade responsiva e os componentes existentes. Todos têm links para a tabela da própria página, mantendo setor, unidade e período. As cores verde, amarelo e vermelho são indicadores de acompanhamento; não mudam o status no Protheus.

Os indicadores Safra/Entressafra, resumos operacionais e análises históricas continuam com seus escopos anteriores; não devem ser somados ao backlog normal como se fossem a mesma população. As análises existentes ficam em seção separada dos dois backlogs.

## Arquivos desta alteração

- `src/Service/Protheus/RouteChecklist.php`: identificação, faixas e contexto de seleção.
- `src/Service/Protheus/ProtheusSectorQueries.php`: separação no agregado e na paginação/exportação do backlog.
- `src/Service/Protheus/ProtheusSectorService.php`: filtros, totais e visibilidade por setor/unidade.
- `src/Service/Protheus/OrderListingService.php`: compatibilidade do detalhamento com as novas faixas.
- `src/Service/Protheus/OrderEntryExportService.php`: validação das novas faixas nas exportações existentes.
- `templates/Pcm/sector_protheus.php`: estado vazio considera também os checklists.
- `templates/element/protheus_sector_content.php`: apresentação dos backlogs separados e contexto da seleção.
- `templates/element/protheus_general_detail.php`: separação também na visão geral.
- `templates/element/protheus_route_checklists.php`: componente compartilhado dos cards.
- `tests/TestCase/Service/Protheus/RouteChecklistTest.php` e `ProtheusSectorTest.php`: classificação, faixas, partição, filtros e interface.
- `tests/TestCase/Controller/ProtheusOrderAccessTest.php`: contrato atualizado da visão geral, preservando os testes de gráficos e acesso.
- Este documento.

Nenhuma migration ou variável de ambiente é necessária. As alterações anteriores do Plano de Ação foram preservadas.

## Validação

Os testes executam o predicado real, os CASEs, os agregados e o filtro de detalhamento em SQLite com dados artificiais. Cobrem os três prefixos, equipamentos comuns, setores sem checklist, limites 7/8/14/15, datas inválidas/futuras, filtro Fábrica e total geral sem sobreposição. Os testes do repositório verificam a preservação dos parâmetros, períodos e quantidade de consultas. A allowlist continua bloqueando SQL alterado e escrita.

Na primeira implementação foi feita consulta local somente leitura pela integração existente. Em 09/11/2026, no filtro Fábrica, foram encontrados 5 checklists em Elétrica, 25 em Mecânica e 30 no geral. Esses valores são um retrato histórico, anterior ao ajuste dos prazos por família, não constantes do sistema.

Validação visual em Chrome local com os templates reais e CSS existente: desktop 1440 px (três cards lado a lado) e celular 390 px (cards empilhados), sem transbordamento horizontal da página. Evidências locais em `tmp/route-checklists-desktop.png`, `tmp/route-checklists-mobile.png` e `tmp/route-checklist-visual-results.json`.

Resultados da primeira implementação (antes do ajuste abaixo):

- Suíte PHP completa: **376 testes, 6.713 assertions, todos aprovados** (`tmp/route-checklist-verified.log`).
- Suíte JavaScript completa: **39 testes aprovados**, nenhuma falha (`tmp/route-checklist-js.log`).
- Sintaxe PHP e `git diff --check` aprovados.
- Nova verificação somente leitura da integração: Elétrica, Mecânica e geral disponíveis, sem erros (`tmp/route-checklist-live-final.log`).

Comandos usados nos testes automatizados locais:

```powershell
$env:DATABASE_TEST_URL='sqlite://./tmp/route-checklist-full.sqlite'
php vendor/bin/phpunit
.\tmp\nc-tools\node.exe --test tests/js/*.test.cjs
```

O bootstrap de testes utiliza transporte de e-mail simulado e banco de testes. Nenhum e-mail real foi enviado.


## Ajuste definitivo dos prazos por família

| Família | EM DIA — verde | ATENÇÃO — amarelo | CRÍTICO — vermelho |
| --- | --- | --- | --- |
| PREVLUB FAB / PREVMEC FAB (Mecânica) | 0 a 7 dias | 8 a 14 dias | Mais de 14 dias |
| PREVELE FAB (Elétrica) | 0 a 17 dias | 18 a 20 dias | Mais de 20 dias |

O CASE SQL identifica a família pelo código do bem, sem usar a descrição ou aplicar o limite mecânico às rotas elétricas. Os intervalos são exclusivos. Datas inválidas e futuras continuam separadas.

Os parâmetros dos cards passam a representar situações: `route_on_time`, `route_attention` e `route_critical`. A mesma situação pode conter idades diferentes, conforme a família. Os links antigos com nomes de intervalos foram substituídos nos templates locais. Nenhuma mudança de banco é necessária.

Cada setor mostra seus limites nos cards. A visão geral mantém três cards por situação, descreve as duas regras e soma cada O.S. uma única vez, após aplicar o prazo da sua família. Mantidos o total, a separação das O.S. normais, a unidade Fábrica, o período e o número de consultas.

Arquivos desta revisão:

- `src/Service/Protheus/RouteChecklist.php`.
- `src/Service/Protheus/ProtheusSectorService.php`.
- `templates/element/protheus_route_checklists.php`.
- `tests/TestCase/Service/Protheus/RouteChecklistTest.php`.
- `tests/TestCase/Service/Protheus/ProtheusSectorTest.php`.
- `tests/TestCase/Controller/ProtheusOrderAccessTest.php`.
- Este documento.

Validados os limites 7, 8, 14, 15, 17, 18, 20 e 21 para as três famílias, agregação geral mista, seleção por situação, títulos, filtros, cores e ausência de duplicidades. Prévia visual com dados artificiais em Chrome a 1440 e 390 px para Elétrica, Mecânica e geral: seis cenários aprovados (`tmp/route-family-visual-results.json`).

Resultados finais desta revisão: **377 testes PHP, 6.758 assertions, todos aprovados**, e **39 testes JavaScript aprovados**. Logs em `tmp/route-family-full.log` e `tmp/route-family-js.log`. Sintaxe PHP e `git diff --check` aprovados. Nenhuma migration, escrita no TOTVS, alteração de SMTP, acesso ao Ubuntu ou deploy.
