# Plano de Ação — STATUS na última coluna

Atualização posterior: o download agora utiliza o [modelo visual aprovado pelo usuário](plano-de-acao-modelo-aprovado.md) e contém somente a aba principal. As referências abaixo à preservação das seis abas de rotas descrevem a etapa anterior e foram substituídas pelo requisito de removê-las.

Alteração somente local. Não houve deploy, consulta nova ou escrita no TOTVS.

## Layout exportado

As colunas anteriores até K continuam na mesma ordem. O trecho final fica:

1. PLANO DE AÇÃO (mesclagem original F:H).
2. RESPONSÁVEL DA AÇÃO (I).
3. MANUTENÇÃO (J).
4. O.S. (K).
5. TAREFAS APONTADAS (L).
6. SERVIÇO EXECUTADO (M).
7. STATUS (N).

O layout é fixo inclusive quando não há registros ou não há tarefas preenchidas. SERVIÇO EXECUTADO continua vazio quando a fonte não foi confirmada, sem inferir execução a partir da descrição da abertura.

Mantidos os estilos das células ao mover as colunas: bordas, alinhamento, quebra de texto e largura do status. Tarefas e execução têm largura 65 e quebra de texto; a altura considera as linhas de ambos os textos. STATUS conserva as regras de vermelho para Aberta e verde para Finalizada em N.

O filtro automático e seu nome interno `_xlnm._FilterDatabase` terminam em N e acompanham o número de registros. Mesclagens e referências herdadas de formatação condicional foram ajustadas. A regra antiga sobre K9:L9 foi separada em K9 e N9, evitando colorir as colunas de tarefas e execução. O modelo não contém tabela estruturada nem fórmulas de células na aba principal; as fórmulas existentes de formatação condicional foram preservadas e suas referências ajustadas.

Preservados o modelo original, as outras seis abas, imagens, configurações de impressão e o fluxo de alteração direta de OOXML, sem carregar a planilha inteira na geração.

## Arquivos desta revisão

- `src/Service/ActionPlanExcelReport.php`.
- `tests/TestCase/Service/ActionPlanTest.php`.
- `tests/TestCase/Service/ActionPlanObservationTest.php`.
- Este documento.

Exemplo com três O.S. fictícias, sem consulta ao TOTVS: `tmp/Plano_de_Acao_exemplo_STATUS_final.xlsx`. A coluna de execução foi mantida vazia. O arquivo inclui situações Aberta e Finalizada e tarefas com múltiplas linhas.

Nenhuma migration ou alteração de configuração é necessária.

Validação: **29 testes e 607 assertions, todos aprovados**, incluindo planilha vazia, 2.113 registros (última linha 2.121), ordem dos cabeçalhos, larguras, mesclagens, quebra de texto, filtro e nome interno, cores de status, preservação das colunas anteriores, demais abas e texto de execução separado da abertura. Log local: `tmp/action-plan-status-verified.log`. Sintaxe PHP e `git diff --check` também aprovados.
