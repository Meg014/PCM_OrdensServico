# Exportação de Ordens de Serviço

## Telas e rotas GET

| Tela | Botão | Rota |
| --- | --- | --- |
| Visão setorial Protheus | Exportar Excel | `/pcm/setor/{code}/excel` |
| Ordens de Serviço | Exportar Excel | `/pcm/ordens/excel` |
| Histórico do equipamento | Exportar histórico para Excel | `/pcm/equipamento/excel` |

Os links preservam os filtros **aplicados** à tela, inclusive seleções de gráficos, indicadores e backlog. Alterações ainda não submetidas no formulário precisam ser aplicadas antes da exportação. As telas legadas não têm rotas públicas habilitadas e não receberam exportação.

## Arquitetura e filtros

`PcmController` chama os mesmos métodos `load()` usados pelas telas, com uma opção interna de exportação. Ela redefine página para 1 e limite para 5.000; não é habilitada por parâmetros de requisição nas telas normais. O repositório executa as mesmas consultas registradas, com os mesmos filtros, vínculos, ordenação e validações de ambiguidade. Somente os parâmetros de paginação mudam para `offset=0`, `fetch=5001`.

- Setor: filial, status, equipamento, serviço, nome exato do serviço, centro de custo, tipo de manutenção, busca textual, datas de início planejado, indicador, status do indicador e faixa de backlog. O setor vem do caminho da rota; `area` na query string não o substitui.
- Ordens: número exato da OS, filial, equipamento, centro de custo e período de referência.
- Equipamento: bem e filial obrigatórios, período de referência, tipo e status.

As diferenças de significado dos períodos e de elegibilidade entre as telas permanecem. Não foram criadas regras paralelas de seleção, status, backlog ou indicadores. Os agregados setoriais continuam sendo consultados e validados, inclusive para preservar a classificação de safra/entressafra.

O registro extra detecta excesso. Até 5.000 OS, todas são exportadas numa única consulta de detalhes, sem percorrer páginas nem consultar recursos de cada OS. Acima disso, HTTP 422 solicita refinar os filtros, sem entregar arquivo parcial.

## Conteúdo

O arquivo contém título, setor/área, momento de geração, todos os filtros reconhecidos e total de OS. Sem filtro, exporta o conjunto permitido pelo contexto. Sem resultados, produz uma planilha com total zero, metadados e cabeçalhos. Equipamento inexistente preserva o HTTP 404 da tela.

Colunas comuns: número da OS, filial, código/nome do equipamento, código/nome do serviço, tipo de manutenção, área/setor e centro de custo.

- Setor: status operacional já calculado pela consulta, data/hora de início previsto da manutenção e data/hora de início real geral. A consulta setorial não fornece datas de fim; elas não são inventadas.
- Ordens e equipamento: situação e indicador de término nos códigos originais TOTVS, identificados como códigos nos cabeçalhos; data de referência; data de origem; datas e horas de início/fim previstos e reais, tanto gerais quanto da manutenção.
- Equipamento: também descrição da OS.

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

PhpSpreadsheet mantém as células em memória. A exportação limita a consulta a 5.001 linhas e escreve o arquivo em `tmpfile()`, transmitido como stream e removido ao liberar o recurso. Não cria cópias integrais do XLSX em strings nem usa autosize. A formatação numérica é aplicada por coluna.

Antes de criar a planilha, uma estimativa conservadora considera células, volume textual, memória já usada e 32 MiB de reserva. Se exceder `memory_limit`, HTTP 422 pede filtros mais restritos. O limite de memória do servidor não é aumentado. Essa estimativa reduz risco, mas não garante capacidade sob concorrência; validar a carga e os tempos no ambiente de homologação é necessário. Textos acima de 32.767 caracteres por célula são recusados explicitamente, sem truncamento silencioso.

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

Ensaio sint?tico local da implementa??o final: 5.000 linhas, 30 colunas do hist?rico, datas/horas preenchidas e descri??o de 1.200 caracteres por linha; **26,37 segundos**, **146 MiB de pico de mem?ria**, arquivo de aproximadamente **424 KiB**. A medi??o inclui a gera??o/grava??o, sem consulta SQL, com dados repetidos (boa compress?o). N?o representa consumo sob concorr?ncia ou textos reais distintos. A estimativa preventiva ? mais conservadora que esse ensaio.

Cobertura adicionada: equivalência de filtros isolados/combinados entre tela e exportação, busca, backlog/indicadores, mais de uma página, limite, histórico, escopo parametrizado, tentativa de substituir setor via query string, login/perfis, indisponibilidade, zeros, geração/fuso, vazio, conteúdo XLSX reaberto, fórmulas, nome seguro, exclusão de campos técnicos, memória e textos excessivos.

Os quatro testes JavaScript de setor/detalhe passaram; todos os PHP alterados passaram em `php -l`. Ambiente local: PHP 8.2.12; PHP 8.3/Ubuntu e conexão SQL Server real ainda precisam de homologação. Nenhum deploy, comando de produção, acesso ao banco de produção ou alteração de `.env` foi realizado.
