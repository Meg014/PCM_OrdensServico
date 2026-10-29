# Ajuste pontual de nomes e filtros dos setores

## Comportamento

- O mapeamento central `MaintenanceAreasTable::FRIENDLY_NAMES` apresenta LABORA como Laboratório e MOEND como Moenda. Os códigos permanecem nos dados, URLs e valores dos filtros.
- Os consumidores existentes de nomes (menu, título, indicadores, gráficos, TV e telas históricas) recebem os novos nomes pelo mesmo mapeamento. Os pontos que ainda exibiam códigos em filtros, detalhes e Excel passaram a consultar esse mapeamento.
- Os botões aparecem na ordem Todas, Fábrica, Usina, Adm, Externo, Setores. Apenas a constante de apresentação BUTTONS foi reordenada.
- Os filtros do setor usam Grid com equipamentos, datas e ações alinhados. No tablet/celular, a grade reorganiza os campos.
- O dropdown fica ancorado ao input, com largura correspondente, rolagem interna e quebra de descrições longas. O painel permite a sobreposição sem recortar a lista. As mensagens ficam fora do cálculo da altura dos campos.
- Não houve alteração de SQL, registros TOTVS, classificação dos centros de custo ou lógica de busca/autocomplete.

## Arquivos de aplicação alterados neste ajuste

- `src/Model/Table/MaintenanceAreasTable.php`: dois nomes no mapeamento central.
- `src/Service/Protheus/ProtheusUnit.php`: ordem de BUTTONS.
- `templates/Pcm/sector_protheus.php`: estrutura dos filtros e âncora do dropdown.
- `webroot/css/pcm.css`: estilos restritos aos filtros do setor.
- `templates/Pcm/orders.php`: rótulos do filtro de área, mantendo valores originais.
- `templates/element/protheus_general_detail.php`: nomes na tabela de detalhamento.
- `src/Service/OrderExcelReport.php`, `src/Service/OrderEntryExcelReport.php`, `src/Service/OrderStreamingExport.php`: nomes na coluna Área/Setor, sem mover colunas ou modificar dados de entrada.
- `src/Service/Protheus/Presentation/OrderDetailPresenter.php`, `src/Service/Protheus/OrderProtheusService.php`, `webroot/js/pcm-protheus-order.js`: campo adicional de apresentação `area_name` no detalhe/histórico, preservando códigos.

Testes ajustados: `OrderDateFiltersTest.php`, `OrderStreamingExportTest.php`, `PcmControllerTest.php` e `ProtheusOrderAccessTest.php`.

## Verificação dos registros

Consulta somente de leitura, sem filtros adicionais, com as regras operacionais atuais do sistema, em 29/10/2026:

| Código | Nome exibido | O.S. antes | O.S. depois | Identidades únicas |
| --- | --- | ---: | ---: | ---: |
| LABORA | Laboratório | 7 | 7 | 7 |
| MOEND | Moenda | 1 | 1 | 1 |

Os hashes SHA-256 dos conjuntos ordenados de identidades foram idênticos antes/depois. Nenhuma duplicação ou omissão foi encontrada nesses conjuntos. Os totais correspondem à carteira operacional exibida pelo sistema, não ao histórico integral do TOTVS.

A listagem disponível contém 13 códigos de setores. Nenhum outro código ficou sem nome padronizado após incluir LABORA e MOEND. Moenda e Caldeiraria permanecem separados.

Evidências locais: `tmp/sector-labels-before.json` e `tmp/sector-labels-after.json`.

## Navegador

Edge headless em 1440, 768 e 390 pixels, usando o template real renderizado com dados reais e respostas controladas de sugestões: vazio, 2 resultados, 40 resultados, descrições longas, mouse, teclado, Escape, clique fora e recarga. Verificados alinhamento e alturas no desktop, posição/largura exata da lista, rolagem interna, ausência de deslocamento do formulário e de overflow horizontal. Um segundo cenário verificou navegação automática entre unidades, parâmetros GET confirmados e preservação de rascunhos.

As respostas controladas permitem testar os estados visuais; não substituem uma consulta real de cada equipamento no TOTVS. A lógica de busca não foi alterada.

## Testes automatizados

- Suíte PHP completa: 273 testes, 5.605 asserções, todos aprovados.
- Suíte JavaScript: 21 testes, todos aprovados.
- `git diff --check`: sem problemas.
- Relatório PHPUnit: `tmp/sector-adjustments-tests.xml`.
- Sistema local respondendo em `http://127.0.0.1:8765` (login HTTP 200).

Scripts/evidências locais: `tmp/browser-sector-layout.cjs`, `tmp/browser-unit-check.cjs` e `tmp/sector-autocomplete-*.png`. As capturas de desktop e celular também foram inspecionadas visualmente.
