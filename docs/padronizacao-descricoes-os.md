# Padronização das descrições de O.S.

Implementação em 30/10/2026, exclusivamente na apresentação. O módulo de Não Conformidades e as alterações anteriores foram preservados.

## Causa e regra central

`OrderDescriptions::maintenance()` reconhecia apenas o bloco completo das cinco perguntas no final do texto. Além disso, `best()` fornecia descrições sem essa limpeza à listagem geral e as exportações ainda podiam usar `descricao` sem tratamento.

O serviço compartilhado agora reconhece cada uma das cinco perguntas padronizadas no começo, meio ou final da descrição. Aceita variação de caixa, acentos, espaços/quebras de linha, pontuação, NR-12/NR12 e opções vazias/marcadas por parênteses, colchetes ou caixas de seleção. Respostas sem caixa são reconhecidas apenas quando a linha contém opções de resposta, não uma atividade textual adicional. Variações não reconhecidas permanecem no conteúdo.

`OrderDescriptions::display()` é a única regra de apresentação: chama a limpeza e retorna **Descrição da manutenção não informada.** quando não resta texto útil. Não busca novamente o checklist como alternativa. `best()` preserva a precedência de fontes já existente e aplica essa regra ao texto escolhido para o campo de descrição de O.S.; os apontamentos de origem não são modificados.

Somente os trechos reconhecidos são retirados. Não é removido o restante do texto depois do checklist; atividades posteriores e perguntas técnicas diferentes permanecem. Quebras de linha das atividades são preservadas. Os dados originais do banco não são regravados.

## Cobertura das telas e relatórios

- Listagem geral `/pcm/ordens`, incluindo drilldowns que usam a mesma listagem: descrição selecionada no serviço e elemento de exibição compartilhado.
- Detalhe da O.S., incluindo modal do histórico do equipamento: mesma regra no presenter. Original disponível em `<details>` recolhido com o título **Descrição original registrada no TOTVS**. A cópia original deixou de ser repetida entre os cartões principais de descrições adicionais.
- Paradas por oportunidade: elemento compartilhado na tabela e mesma regra no Excel.
- Plano de Ação: descrição final do agrupamento e coluna de descrição do Excel.
- Excel em streaming de listagem, setor, equipamento e apontamentos: callback somente da coluna Descrição da OS. As colunas de apontamentos e demais observações continuam inalteradas.
- Relatórios Excel em memória de O.S. e apontamentos: mesma regra somente na coluna da O.S.
- Dashboard, setores, backlog, rankings e modo TV foram inspecionados: as visualizações atuais desses componentes não renderizam o texto longo de descrição da O.S. Onde direcionam a O.S. para listagem/detalhe, usam as apresentações acima. Não foram adicionadas colunas ou alterados indicadores para aplicar uma regra a campos que não exibiam descrições.

Na listagem geral e em paradas por oportunidade, o novo elemento preserva as quebras de linha. Descrições maiores que 600 caracteres têm prévia limitada visualmente e a opção nativa **Expandir descrição**; o texto expandido e o Excel mantêm todo o conteúdo tratado. A largura e a rolagem responsiva da tabela existente foram preservadas.

## Arquivos de aplicação alterados neste ajuste

1. `src/Service/Protheus/OrderDescriptions.php`: reconhecimento e mensagem centralizados.
2. `src/Service/Protheus/Presentation/OrderDetailPresenter.php`: descrição útil e separação da cópia original para auditoria.
3. `src/Service/ActionPlanService.php`: descrição tratada após seleção da fonte.
4. `src/Service/OrderStreamingExport.php`: callbacks das quatro apresentações de coluna de descrição.
5. `src/Service/OrderExcelReport.php`.
6. `src/Service/OrderEntryExcelReport.php`.
7. `src/Service/OpportunityStopExcelReport.php`.
8. `src/Service/ActionPlanExcelReport.php`.
9. `templates/Pcm/orders.php`.
10. `templates/Pcm/opportunity_stops.php`.
11. `templates/element/order_description.php` (novo).
12. `webroot/css/pcm.css`: quebra de linha e limite visual da prévia.
13. `webroot/js/pcm-protheus-order.js`: título da auditoria e mensagem sem descrição.

Testes: novo `tests/TestCase/Service/Protheus/OrderDescriptionPresentationTest.php`; atualizados `SectorImprovementsTest.php` e `ActionPlanTest.php` para as novas regras de perguntas reconhecidas individualmente e mensagem obrigatória no lugar do vazio.

Nenhum SQL, filtro, classificação de O.S., tarefa, etapa, mão de obra, registro TOTVS ou arquivo do módulo de Não Conformidades foi alterado neste ajuste. Não houve migration, importação ou alteração de configuração. O texto original usado em auditoria não é substituído pelo resultado da limpeza.

## Validação das referências reais

Consultas somente de leitura, filial 01:

| O.S. | Descrição principal tratada |
| --- | --- |
| 007042 | INSPECIONAR REDUTOR E ACOPLAMENTO; ELIMINAR VAZAMENTO DO REDUTOR; REVISAR MANCAIS E ROLETES; REVISÃO DOS RASPADORES — cada atividade em sua linha |
| 007229 | FAZER MANUTENÇAO |
| 007228 | FAZER MANUTENÇAO |

As duas últimas possuem esse texto real antes do checklist; portanto ele foi mantido, em vez de mostrar a mensagem de ausência. A mensagem foi validada separadamente com descrições contendo apenas o checklist.

As três referências apresentaram o mesmo conteúdo útil na listagem, no detalhe e em arquivos XLSX efetivamente gerados e reabertos. O XML do Excel normaliza CRLF para LF; as linhas e todo o texto útil permanecem iguais.

Os hashes do original na consulta inicial coincidiram com os obtidos posteriormente para auditoria nas três O.S. Evidências locais: `tmp/order-description-reference.json`, `tmp/order-description-check-results.json`.

## Testes e navegador

Resultado final: **292 testes PHP, 5.849 asserções, todos aprovados; 24 testes JavaScript aprovados**. A classe nova de apresentação cobre 152 asserções. Sintaxe PHP dos arquivos principais e `git diff --check` aprovados.

- Novos testes cobrem checklist puro/misto, perguntas parciais reconhecidas, atividades antes/depois, ausência de checklist, perguntas técnicas, incerteza, caixa, acentos, espaços, quebras de linha, pontuação e respostas marcadas/sem caixa.
- Teste de consistência verifica detalhe e colunas de streaming de listagem/setor/equipamento/apontamentos. Verifica também que original e textos independentes de mão de obra/descrições adicionais permanecem intactos.
- Edge em 1440, 768 e 390 pixels com templates reais renderizados a partir dos dados consultados. Verificados texto tratado, quebras de linha, ausência de overflow horizontal da página e auditoria recolhida/expansível. Cenários controlados adicionais verificam mensagem de ausência e expansão de texto longo.
- A validação visual usa HTML renderizado e resposta de detalhe capturada do serviço real, servidos por rotas controladas do teste. Não usa a sessão do usuário e não comprova publicação em produção. Captura desktop inspecionada visualmente.

Scripts/evidências locais (não publicar): `tmp/check-order-descriptions.php`, `tmp/render-order-description-check.php`, `tmp/browser-order-descriptions.cjs`, `tmp/order-description-list-*.png`, `tmp/order-description-detail-1440.png`, `tmp/description-full-tests.xml` e `tmp/description-js-tests.log`.

## Publicação

Nenhuma publicação foi executada. Publicar os 13 arquivos de aplicação listados acima como uma revisão coerente, incluindo o elemento novo; não basta publicar apenas a view. Não há build, migration ou limpeza de banco necessária. As referências CSS e do JavaScript de detalhe usam o versionamento de assets existente; confirmar a versão carregada e recarregar o navegador após a publicação.
