# Plano de Ação

Acesso: menu **Plano de Ação**, `/pcm/plano-de-acao`. Download autenticado em
`/pcm/plano-de-acao/excel?date_start=2026-10-01&date_end=2026-10-20`.
Mantém as permissões existentes: ADMIN/USUARIO; TV e visitantes sem acesso.

## Seleção e fontes

Uma consulta nova, parametrizada e autorizada pelo driver ProtheusReadOnly em cada
download. Não usa snapshots, importações, cache, limites de origem de outros
relatórios, classificação Safra/Entressafra ou data de fechamento.

Identidade: filial + número. Seleciona somente `TJ_SITUACA=L` e `TJ_TERMINO=N/S`;
exclui canceladas C, pendentes P e registros excluídos logicamente.

Primeiro calcula a data de referência sobre todo o histórico ativo de STL010:

- Com apontamentos ativos de qualquer tipo: menor data válida `TL_DTINICI`.
- Sem nenhum apontamento ativo: `STJ010.TJ_DTORIGI`.
- Só então filtra o intervalo inclusivo. A mesma data preenche E.
- Se todos os apontamentos ativos tiverem datas inválidas, não usa a abertura
  como alternativa: a referência fica nula e a O.S. não entra no intervalo.

`TJ_DTORIGI` é a data original da O.S., documentada pela TOTVS como data original
de início e relacionada à emissão da OP na abertura da O.S.; não é o início real
da execução (`TJ_DTMRINI`) nem o timestamp técnico de inserção.

Fontes oficiais consultadas:

- [Alteração de O.S. — campos](https://tdn.totvs.com/pages/viewpage.action?pageId=805979494).
- [O.S. e OP — data original/emissão](https://tdn.totvs.com/pages/viewpage.action?pageId=567750522).

Validação somente leitura em 20/10/2026: 914 O.S. elegíveis sem apontamentos,
todas com TJ_DTORIGI válido; nenhuma TL_DTINICI inválida nos apontamentos ativos.

## Preenchimento aprovado

| Coluna | Fonte |
| --- | --- |
| B | TJ_CCUSTO → CTT010.CTT_DESC01, por filial |
| C | TJ_CODBEM → ST9010.T9_NOME |
| D e I | Todos os executantes ativos M de STL010, TL_CODIGO → ST1010.T1_CODFUNC/T1_NOME por filial |
| E | Data de referência acima |
| H | TJ_OBSERVA; se vazia, primeira TL_OBSERVA ativa legível do histórico; se ambas vazias, nome do serviço já consultado em ST4010.T4_NOME |
| J | TJ_SERVICO → ST4010.T4_NOME |
| K | TJ_ORDEM como texto, preservando zeros |
| L | Aberta para L/N; Finalizada para L/S |

A, F e G ficam vazias. D e I usam todos os apontamentos M, inclusive fora do
intervalo, por decisão explícita do usuário; não representam uma inferência de
quem relatou a anomalia. Ordenação: data, hora inicial, R_E_C_N_O_. Funcionários
e nomes idênticos são deduplicados, separados por ` / `. Ausência de mão de obra
deixa as duas células vazias. Cadastros ambíguos interrompem o download; dados
ausentes não são inventados. Equipamento e serviço seguem a preferência já
existente por filial local, com cadastro compartilhado quando não há local.

## Preservação do modelo

A alternativa de H usa `STL010.TL_OBSERVA`, confirmado no banco como varbinary.
Busca qualquer tipo de apontamento ativo por filial/número, sem limitar o período,
na ordem TL_DTINICI, TL_HOINICI e R_E_C_N_O_. Ignora textos vazios após normalização,
Não concatena descrições. Se as duas fontes estiverem vazias, usa o serviço;
somente deixa H vazia quando nenhuma das três fontes contém texto útil.
A obtenção dos mecânicos permanece restrita aos registros M.

Atualização: o nível de tarefa permanece pendente por decisão do usuário até
autorização da TI. Nenhum relacionamento não confirmado foi incorporado ao SQL.
O tratamento de texto rejeita UTF-8 inválido, espaços Unicode e conteúdo sem
letras/números; excesso de bytes de controle é tratado como conteúdo binário.
Um controle isolado é removido sem descartar a descrição útil ao redor dele.
Assim, descrição específica válida sempre tem prioridade sobre o serviço.

Auditoria e solicitação de leitura para TI: [plano-de-acao-tarefas-ti.md](plano-de-acao-tarefas-ti.md).

Validação desse ajuste: 13 testes/307 asserções; SELECTs com CTEs no SQL Server
comprovam prioridade, ordem por data/hora/registro, exclusão lógica, texto de
apontamento não M fora do período e isolamento por filial. Comparação antes/depois
de 5509 O.S. reais confirmou igualdade de todos os demais campos e da população.
No banco atual, 2710 apontamentos ativos possuem payload em TL_OBSERVA, mas nenhum
contém texto válido após remover NUL e espaços. Por isso o cenário positivo da
alternativa foi validado com dados controlados, sem gravações no TOTVS, e no Excel.

Modelo obrigatório: `resources/templates/PLANO DE AÇÃO 2026 ATUALIZADO.xlsx`.
PhpSpreadsheet carrega o modelo e preenche as células com tipos explícitos.
Para evitar perdas em uma regravação completa, o pacote XLSX original é copiado
para um temporário; os valores das linhas de dados da primeira aba são
reescritos no XML original. Para os status, são acrescentados dois estilos
diferenciais a styles.xml, preservando todos os estilos existentes. As outras
partes do ZIP permanecem byte a byte iguais, incluindo seis abas de rotas,
shared strings, logotipo e relacionamentos.
O modelo nunca é salvo. O temporário é removido; o stream do download é temporário.

Preserva cabeçalhos 1–8, mesclagens, larguras, alturas existentes, colunas ocultas,
impressão e regras condicionais. Limpa todos os valores antigos a partir da linha
9, mantendo as linhas formatadas vazias do original. Atualiza o autofiltro; quando
ultrapassa a linha 2120, clona o estilo e altura da linha 10, expande a dimensão e
estende os finais dos intervalos condicionais de dados, preservando as regras.
Mantém as regras condicionais antigas e sua ordem relativa. Acrescenta regras
prioritárias, com stopIfTrue, exclusivamente no intervalo L9:Lúltima exportada:
Aberta com fundo vermelho C00000; Finalizada com fundo verde 008000; ambas com
texto branco. As regras não alteram fonte, tamanho, bordas ou alinhamento e
impedem que as condições antigas sobrescrevam essas cores.

O formulário usa rótulos em bloco, form-control-lg e colunas Bootstrap iguais.
Datas ficam lado a lado a partir de 576px; o botão fica na mesma linha a partir
de 992px. Em telas menores os controles se empilham, mantendo largura integral,
altura igual e as cores do tema existente.

## Validação

`php vendor/phpunit/phpunit/phpunit tests/TestCase/Service/ActionPlanTest.php tests/TestCase/Controller/ProtheusOrderAccessTest.php tests/TestCase/Service/Protheus/ProtheusIntegrationTest.php`

Resultado: 18 testes, 312 asserções aprovadas. Sintaxe PHP dos arquivos envolvidos
e `git diff --check` também passaram.

Após o ajuste visual: 12 testes e 284 asserções aprovados nos arquivos
ActionPlanTest e ProtheusOrderAccessTest. Comparação estrutural confirma que
somente as novas regras/diferenciais de cor são acrescentadas aos estilos; o
restante do modelo permanece preservado. Testes incluem expansão até a linha
2121 e população vazia. Excel real de setembro/2026 gerado e conferido com 441
abertas vermelhas e 292 finalizadas verdes. Formulário conferido no Chrome
headless em larguras de desktop, tablet e tela estreita, sem overflow.

Testes cobrem datas, lista fechada de SQL, agrupamento por filial/número, nomes
repetidos, ausência de executantes, status, zeros, texto começando por `=`,
remoção dos registros antigos, população vazia e expansão além da linha 2120.
Comparam todas as partes do ZIP e o XML da aba principal após normalizar apenas
valores/autofiltro; verificam que o hash do template não muda.

Também foram executados SELECTs com oito O.S. sintéticas em CTEs no SQL Server,
sem criar tabelas: primeiro apontamento fora do intervalo com apontamento posterior
dentro, abertura sem apontamentos, C/P, mecânicos posteriores ao intervalo,
duplicatas, filiais distintas, data inválida sem fallback e exclusão lógica.

Comparação independente da seleção SQL com cálculo em PHP dos dados reais:
2026 inteiro: 2865 O.S.; setembro/2026: 733; 01–20/10/2026: 408;
20/10/2026: 9; 01/01/2000: 0. Cada período coincidiu integralmente nas identidades
e datas. Valores são evidência daquela consulta, não resultados fixos do sistema.

## Arquivos envolvidos

- Novos: `src/Service/Protheus/ActionPlanQuery.php`,
  `src/Service/ActionPlanService.php`, `src/Service/ActionPlanExcelReport.php`,
  `templates/Pcm/action_plan.php`, `tests/TestCase/Service/ActionPlanTest.php`
  e este documento.
- Integração: `config/routes.php`, `src/Controller/AppController.php`,
  `src/Controller/PcmController.php`, `src/Service/Protheus/ProtheusQueries.php`,
  `src/Service/Protheus/ProtheusRepository.php`, `templates/layout/default.php`
  e `tests/TestCase/Controller/ProtheusOrderAccessTest.php`.
- Recurso obrigatório: template original em `resources/templates/`, fornecido pelo
  usuário, mantido sem alteração.
