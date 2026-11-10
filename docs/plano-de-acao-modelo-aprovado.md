# Plano de Ação — modelo visual aprovado

Atualização de 10/11/2026: [SERVIÇO EXECUTADO passou a utilizar STQ010.TQ_OBSERVA](plano-de-acao-servico-executado-stq.md). O layout permanece; as referências abaixo à fonte ainda não confirmada descrevem a etapa anterior.

Referência fornecida pelo usuário: `plano-de-acao_2026-11-01_2026-11-09 (2).xlsx`, aba **PLANO DE AÇAO 2026**.

SHA-256 da referência: `4e02bef640f27ac6695cf30e047ef37154b0d8a0b3a627e215344896c7ec578c`.

## Modelo usado no download

`resources/templates/PLANO_DE_ACAO_APROVADO.xlsx` é uma cópia sanitizada dessa referência, sem recriar o layout por uma biblioteca. Preserva estilos, títulos, logotipo, cores, bordas, alinhamentos, colunas ocultas, larguras, mesclagens, impressão e zoom. Os cabeçalhos foram mantidos como no arquivo fornecido, inclusive a capitalização de “Status”.

O pacote contém somente a aba principal. Foram removidas as seis abas de rotas, seus relacionamentos, configurações de impressora e nomes internos associados. Os registros antigos foram apagados de todas as células a partir da linha 9 e também dos textos compartilhados não usados. A referência original em Downloads não foi alterada.

`PLANO_DE_ACAO_APROVADO.layout.json` registra as assinaturas de estilos, imagem, desenho, cabeçalhos e componentes visuais da referência para os testes. Não contém registros históricos.

## Preenchimento dinâmico

O gerador mantém a alteração direta de OOXML e o download por stream. Não exige Excel, Office, novas dependências ou novos serviços no servidor.

Consultas, filtros, datas, agrupamentos e classificação das O.S. não foram modificados. As colunas continuam nas posições aprovadas:

| Coluna | Conteúdo |
| --- | --- |
| F:H | PLANO DE AÇÃO, com a mesclagem aprovada |
| I | RESPONSÁVEL DA AÇÃO |
| J | MANUTENÇÃO |
| K | O.S., escrita como texto para preservar zeros |
| L | TAREFAS APONTADAS |
| M | SERVIÇO EXECUTADO |
| N | Status, última coluna da tabela |

Todas as colunas anteriores permanecem. SERVIÇO EXECUTADO continua vazio quando não há fonte confirmada; não se usa a descrição de abertura para inventar execução.

Os filtros e seu nome interno acompanham B7:NúltimaLinha. As cores vermelha para Aberta e verde para Finalizada são preservadas por regras prioritárias, sem mudar bordas, fontes ou alinhamentos. A altura de cada linha é estimada a partir das larguras aprovadas, quebras e extensão do texto atual, respeitando a altura mínima do modelo e o limite do Excel. Uma linha com mais conteúdo pode ficar mais alta que no exemplo, para não truncar o texto. As linhas adicionais herdam os estilos aprovados.

## Arquivos desta revisão

- `src/Service/ActionPlanExcelReport.php`.
- `resources/templates/PLANO_DE_ACAO_APROVADO.xlsx`.
- `resources/templates/PLANO_DE_ACAO_APROVADO.layout.json`.
- `bin/prepare_action_plan_template.php`, importação local única, fora do processamento do download.
- `tests/TestCase/Service/ActionPlanTest.php`.
- `tests/TestCase/Service/ApprovedActionPlanTemplateTest.php`.
- Este documento e notas de atualização nas documentações anteriores.

O modelo antigo permanece como arquivo histórico no repositório; não é mais usado por este download.

## Validação e arquivo para conferência

- **32 testes PHP e 643 assertions aprovados**, abrangendo o relatório, modelo, observações, acesso e consultas somente leitura. Log: `tmp/action-plan-approved-tests.log`.
- Comparação automatizada com as assinaturas da referência: estilos, cabeçalhos, larguras, mesclagens, logotipo, desenho, visualização, impressão e regras herdadas preservados.
- Testes confirmam apenas uma aba e ausência de registros antigos, inclusive strings internas sem uso.
- Testes de planilha vazia, 2.113 registros, extensão até a linha 2.121, filtros, zeros à esquerda, cores e textos extensos.
- Geração local com as consultas SELECT existentes para 01/11/2026–09/11/2026: **63 O.S. atuais**, em aproximadamente **1,02 segundo** nesta máquina; medição pontual, não garantia de desempenho no servidor.
- Microsoft Excel local abriu o arquivo gerado normalmente: uma aba e filtro B7:N71.
- Referência e resultado foram renderizados pelo próprio Excel, abertos somente para leitura e fechados sem salvar. A prévia limita a impressão a B1:N11 apenas em memória para comparação. A faixa superior renderizada (cabeçalho e títulos, primeiros 174 pixels da imagem de 2.400 px) apresentou **zero pixels diferentes**. As diferenças no corpo correspondem ao conteúdo dinâmico e às alturas ajustadas.

Arquivo completo gerado com dados atuais:

`tmp/Plano_de_Acao_modelo_aprovado_2026-11-01_2026-11-09.xlsx`

Prévias locais para comparação:

- `tmp/plano-aprovado-referencia.pdf` e `tmp/plano-aprovado-gerado.pdf`.
- `tmp/plano-aprovado-referencia.png` e `tmp/plano-aprovado-gerado.png`.

Não há migrations nem novas variáveis de ambiente. Nenhum deploy ou acesso ao Ubuntu foi realizado. Não houve escrita no TOTVS nem envio de e-mails.
