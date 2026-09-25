# Exportação de Ordens de Serviço

## Telas e rotas GET

| Tela | Botão | Rota |
| --- | --- | --- |
| Visão setorial Protheus | Exportar Excel | `/pcm/setor/{code}/excel` |
| Visão setorial Protheus | Exportar apontamentos | `/pcm/setor/{code}/apontamentos/excel` |
| Ordens de Serviço | Exportar Excel | `/pcm/ordens/excel` |
| Ordens de Serviço | Exportar apontamentos | `/pcm/ordens/apontamentos/excel` |
| Histórico do equipamento | Exportar histórico para Excel | `/pcm/equipamento/excel` |

Os links preservam os filtros **aplicados** à tela, inclusive seleções de gráficos, indicadores e backlog. Alterações ainda não submetidas no formulário precisam ser aplicadas antes da exportação. As telas legadas não têm rotas públicas habilitadas e não receberam exportação.

## Arquitetura e filtros

`PcmController` chama os mesmos serviços e filtros usados pelas telas. A exportação percorre o resultado em páginas SQL de 1.000 registros e grava o XML do XLSX incrementalmente em arquivos temporários. O repositório executa as mesmas consultas registradas, com os mesmos filtros, vínculos, ordenação e validações de ambiguidade. A paginação enviada pela interface não controla nem limita a exportação.

- Setor: filial, status, equipamento, serviço, nome exato do serviço, centro de custo, tipo de manutenção, busca textual, datas de início planejado, indicador, status do indicador e faixa de backlog. O setor vem do caminho da rota; `area` na query string não o substitui.
- Ordens: número exato da OS, filial, equipamento, centro de custo e período de referência.
- Equipamento: bem e filial obrigatórios, período de referência, tipo e status.

As diferenças de significado dos períodos e de elegibilidade entre as telas permanecem. Não foram criadas regras paralelas de seleção, status, backlog ou indicadores. Os agregados setoriais continuam sendo consultados e validados, inclusive para preservar a classificação de safra/entressafra.

Todos os lotes são processados até `has_more=false`; não existe limite total funcional de OS. Uma falha em qualquer lote descarta os temporários e não publica um arquivo parcial.

## Conteúdo

O arquivo contém título, setor/área, momento de geração e total de OS. Os filtros efetivamente aplicados aparecem em uma única linha, separados por `|`; valores vazios, `all`, `Todos` e equivalentes não são exibidos. Quando não há filtro específico, a linha de filtros é omitida. Sem filtro, exporta o conjunto permitido pelo contexto. Sem resultados, produz uma planilha com total zero, metadados e cabeçalhos. Equipamento inexistente preserva o HTTP 404 da tela.

Colunas comuns: número da OS, filial, código/nome do equipamento, código/nome do serviço, tipo de manutenção, área/setor e centro de custo.

A descrição real da OS (`STJ010.TJ_OBSERVA`) é exportada separadamente do nome do serviço. Ela é selecionada nas consultas em lote das três visões e não provoca consultas individuais por OS.

- Setor: status operacional já calculado pela consulta, data/hora de início previsto da manutenção e data/hora de início real geral. A consulta setorial não fornece datas de fim; elas não são inventadas.
- Ordens e equipamento: situação e indicador de término nos códigos originais TOTVS, identificados como códigos nos cabeçalhos; data de referência; data de origem; datas e horas de início/fim previstos e reais, tanto gerais quanto da manutenção.
- Equipamento: também descrição da OS.

## Relatório de apontamentos

O relatório setorial de apontamentos contém somente OS que possuem registros não excluídos logicamente em `STL010`. Sua unidade é o apontamento: os dados da OS se repetem quando ela possui vários registros. O cabeçalho informa total de apontamentos e quantidade de OS distintas.

Colunas: número e filial da OS; código/nome do equipamento; descrição da OS; código/nome do serviço; área, centro de custo, tipo de manutenção e status; tipo e código do apontamento; código/nome do responsável; código/nome do material; datas inicial/final; horas inicial/final; quantidade e unidade. `M` é apresentado como `Mão de obra`, `P` como `Material`, e outros códigos são preservados sem inferência.

Uma única consulta parametrizada e allowlisted combina o mesmo escopo/filtros setoriais com `STL010`, `ST1010` e `SB1010`. Não há consulta por OS, profissional, material ou apontamento. O filtro sazonal pode exigir uma leitura agregada adicional já usada para a classificação atual; a quantidade de consultas continua fixa. A exportação também aceita `entry_type` (`M` ou `P`) e `professional` (código exato) como filtros opcionais validados.

Não existe limite total funcional de apontamentos. Cada consulta busca até 1.001 registros, escreve 1.000 e usa o registro adicional apenas para determinar a continuidade. O nome setorial segue `APONTAMENTOS_OS_{SETOR}_AAAA-MM-DD_HHMMSS.xlsx`; a exportação geral usa `APONTAMENTOS_OS_GERAL_AAAA-MM-DD_HHMMSS.xlsx`.

O indicador de término não é apresentado como data. Campos ausentes ou datas/horas inválidas ficam vazios. Datas sem hora representam a data operacional recebida, sem deslocamento de fuso. Datas são células numéricas formatadas como `DD/MM/AAAA`; horas, `HH:mm`; geração, `DD/MM/AAAA HH:mm`. Códigos são sempre texto, preservando zeros à esquerda.

`Gerado em` corresponde ao início da produção do Excel, após a consulta, em `America/Sao_Paulo`. Não modifica a persistência de datas. Não há `Dados atualizados em`: os serviços só informam horário da consulta, que não comprova a última atualização da fonte.

Exemplos de nomes: `OS_ELETRI_2026-09-25_103500.xlsx`, `OS_GERAL_2026-09-25_103500.xlsx`, `HISTORICO_EQUIPAMENTO_001234_2026-09-25_103500.xlsx`. A parte variável permite apenas letras ASCII, números, `_` e `-`, com tamanho limitado.

## Segurança e indisponibilidade

A exportação passa pela autenticação e pela revalidação de usuário ativo, senha, perfil e troca obrigatória de senha do `AppController`. ADMIN e USUARIO têm acesso aos setores, conforme a política atual; TV e anônimos não podem exportar. **Não existe atualmente cadastro de setores permitidos por usuário.** Uma restrição individual por setor exigiria uma mudança separada de autorização também nas telas. O mecanismo já existente de `areaScope` no repositório continua sendo aplicado quando fornecido pelo servidor, inclusive na exportação.

Todos os textos, inclusive filtros e metadados, são escritos com `TYPE_STRING` do PhpSpreadsheet. Valores começando por `=`, `+`, `-` ou `@` não se tornam fórmulas. Datas/horas e total usam tipos numéricos. Uma lista explícita de colunas impede exportar IDs internos, registros físicos, tokens ou campos técnicos.

Consultas continuam parametrizadas e autorizadas pelo driver Protheus somente leitura. Não foram alterados `ProtheusQueries`, `ProtheusSectorQueries`, `ProtheusReadOnly`, permissões, datasources, migrations, transferências de dados ou credenciais.

Falha de conexão, permissão SQL, timeout ou validação de resultado produz HTTP 503 com mensagem pública, sem SQL ou detalhes do servidor. Não utiliza cache antigo nem snapshots locais. Respostas usam `Cache-Control: no-store`.

Após gerar o arquivo, registra no log existente evento `order_excel_generated`, identificador do usuário, data/hora, contexto, setor e quantidade. Não registra filtros, conteúdo de células ou arquivo no banco. O evento significa arquivo gerado, não confirmação de download completo pelo navegador.

## Recursos e limites

Os workbooks unitários continuam usando PhpSpreadsheet, mas as rotas de exportação usam `StreamingXlsxReport`: os dados são consultados em lotes de 1.000, o XML é escrito em disco e o ZIP final é transmitido por stream. O consumo de memória fica relacionado ao lote corrente, não ao total exportado. Não cria cópias integrais do XLSX em strings nem usa autosize.

Os lotes reutilizam o `OFFSET/FETCH` já consolidado nas consultas e uma ordenação determinística que termina em `STJ010.R_E_C_N_O_`; nos apontamentos, `STL010.R_E_C_N_O_` completa a ordenação. Essa escolha evita introduzir SQL paralelo às regras atuais. Como a fonte Protheus é consultada ao vivo e a conexão somente leitura não abre uma transação snapshot longa, alterações concorrentes de ordenação durante a exportação continuam sendo uma limitação técnica da fonte; não há como prometer um snapshot temporal sem suporte/configuração transacional no SQL Server.

Textos acima de 32.767 caracteres por célula são recusados explicitamente, sem truncamento silencioso. O limite de memória do servidor não é aumentado.

Permanece apenas o limite técnico do formato XLSX de 1.048.576 linhas por planilha (incluindo cabeçalhos). Se o resultado ultrapassá-lo, nenhum arquivo parcial é entregue; para esse patamar será necessária uma exportação CSV dedicada ou divisão explícita em múltiplas planilhas/arquivos.

Os orçamentos e timeouts SQL existentes permanecem. Exportações maiores podem ser recusadas por timeout; não se contornam as permissões externas em tratamento pela TI.

## Arquivos

Criados: `src/Service/OrderExcelReport.php`, `tests/TestCase/Service/OrderExcelReportTest.php` e este documento.

Alterados: `config/routes.php`; `src/Controller/{AppController,PcmController}.php`; `src/Service/Protheus/{OrderListingService,EquipmentHistoryService,ProtheusSectorService,ProtheusRepository}.php`; `templates/Pcm/{orders,equipment,sector_protheus}.php`; `tests/TestCase/Controller/ProtheusOrderAccessTest.php`; `tests/TestCase/Service/Protheus/{OrderListingTest,EquipmentHistoryTest,ProtheusSectorTest,ProtheusScopeTest}.php`.

## Verificação local

Testes executados sem bootstrap de banco, com conexões simuladas e SQLite em memória nas verificações já existentes:

```text
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php <arquivo-de-teste>
```

Resultados finais: su?te `tests/TestCase/Service/Protheus` com **51 testes e 1.165 assertions aprovados**; `OrderExcelReportTest` com **6 testes e 37 assertions**; `ProtheusOrderAccessTest` com **7 testes e 74 assertions**. Total: **64 testes PHP e 1.276 assertions aprovados**.

Na primeira execu??o foram encontrados dois problemas anteriores nos testes: expectativa SQL sem o alias `j.` da consulta atual e configura??o repetida do cache `_cake_translations_`. A expectativa foi atualizada e a configura??o dos testes tornou-se condicional, sem mudan?as no SQL. A su?te conjunta passou ap?s essas corre??es. N?o foi executada a su?te que reconstr?i banco por migrations.

Ensaio sintético do escritor incremental após validação OOXML: 50.000 linhas e 25 colunas textuais com caracteres especiais em 50 lotes; **12,91 segundos**, **14 MiB de pico de memória** e XLSX de aproximadamente **4,69 MiB**. A medição inclui geração e compactação local, sem consulta SQL, concorrência ou latência de rede.

O writer segue a ordem canônica da worksheet (`sheetPr`, `dimension`, `sheetViews`, `sheetFormatPr`, `cols`, `sheetData`, `autoFilter`, `mergeCells`). Textos são normalizados para UTF-8, têm somente caracteres proibidos pelo XML 1.0 removidos e são escapados dentro de `c/is/t` com `t="inlineStr"`. Testes extraem o ZIP, validam partes e relationships, verificam a estrutura da worksheet e reabrem o XLSX com PhpSpreadsheet.

Cobertura adicionada: equivalência de filtros isolados/combinados entre tela e exportação, busca, backlog/indicadores, mais de uma página, limite, histórico, escopo parametrizado, tentativa de substituir setor via query string, login/perfis, indisponibilidade, zeros, geração/fuso, vazio, conteúdo XLSX reaberto, fórmulas, nome seguro, exclusão de campos técnicos, memória e textos excessivos.

Os quatro testes JavaScript de setor/detalhe passaram; todos os PHP alterados passaram em `php -l`. Ambiente local: PHP 8.2.12; PHP 8.3/Ubuntu e conexão SQL Server real ainda precisam de homologação. Nenhum deploy, comando de produção, acesso ao banco de produção ou alteração de `.env` foi realizado.
