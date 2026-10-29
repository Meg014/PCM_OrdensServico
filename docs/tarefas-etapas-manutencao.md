# Tarefas e Etapas da Manutenção — entrega parcial, 29/10/2026

## Situação da implementação

A seção está implementada para **atividades comprovadamente registradas nos
apontamentos**. O relacionamento das atividades **planejadas** ainda não foi
comprovado. Esta entrega não representa a recuperação do planejamento completo.

Este relatório atualiza a parte de tarefas/etapas de
`melhorias-setores-e-descricoes.md`. As demais melhorias daquela entrega foram
preservadas. Não houve alterações no projeto PCM-Solicitacao-OS.

## Investigação somente leitura

Foram examinadas as consultas e a montagem do detalhe em ProtheusRepository,
OrderDescriptionQueries, OrderSupplementMapper, OrderDetailPresenter e no
JavaScript da página. A implementação anterior apresentava tarefas de STL010
como texto dentro das descrições adicionais; não recuperava planejamento.

Na conexão atual, foram consultados sys.objects, sys.columns e sys.types:
tabelas com campos de O.S./plano/tarefa/etapa e todas as views visíveis, inclusive
fora do schema dbo. Não apareceu uma view ou tabela acessível que comprovasse
o vínculo de planejamento. A visibilidade de metadados depende da conta; isso
não significa que essas fontes não existam no TOTVS.

Permissões confirmadas por HAS_PERMS_BY_NAME:

| Fonte | SELECT | VIEW DEFINITION verificado |
| --- | --- | --- |
| TT9010 | 1 | não necessário à consulta de dados |
| TPA010 | 1 | não necessário à consulta de dados |
| ST4010 | 1 | não necessário à consulta de dados |
| ST5010 | 0 | 0 |

Metadados também expõem tabelas de outras empresas, como STJ020/STL020 e
cadastros TT9020 etc. Não foram usados como alternativa às tabelas da empresa
010. Não houve leitura de dados de ST5010 nem tentativa de contornar sua restrição.

## Fontes e vínculo implementado

| Fonte | Campos utilizados |
| --- | --- |
| STJ010 | TJ_FILIAL, TJ_ORDEM, TJ_PLANO, TJ_SEQRELA, TJ_SERVICO, TJ_CODBEM; TJ_SITUACA/TJ_TERMINO e exclusão para conferência do cabeçalho |
| STL010 | TL_FILIAL, TL_ORDEM, TL_PLANO, TL_SEQRELA, TL_SEQMAN, TL_SEQTARE, TL_TAREFA, TL_ETAPA, R_E_C_N_O_, D_E_L_E_T_ |
| TT9010 | TT9_FILIAL, TT9_TAREFA, TT9_DESCRI, D_E_L_E_T_ |
| TPA010 | TPA_FILIAL, TPA_ETAPA, TPA_DESCRI, D_E_L_E_T_ |

O cabeçalho é obtido pela consulta existente, que valida a identidade da O.S.
Os apontamentos são selecionados por **TL_FILIAL + TL_ORDEM**, com parâmetros
vinculados e exclusão lógica respeitada. Cada par tarefa/etapa vem do **mesmo
registro STL010**. O plano e as sequências conservam os valores do apontamento;
não são substituídos pelos do cabeçalho nem empregados para inferir planejamento.

TT9010 fornece somente a descrição do código TL_TAREFA; TPA010 fornece somente
a descrição do código TL_ETAPA. A filial exata tem prioridade sobre a filial
compartilhada vazia. Se o cadastro exato for ambíguo, não se escolhe uma descrição
arbitrária ou de outra filial. Não existe associação por nome, serviço ou equipamento.
Consultar uma etapa no catálogo não a vincula a nenhuma O.S.

Cada tarefa aparece uma vez por plano. Repetições dos apontamentos são agrupadas;
as sequências distintas permanecem nos contextos de origem. Cada etapa aparece
uma vez sob sua tarefa, mantendo também seus contextos. Etapa sem tarefa não é
atribuída a outra tarefa: aparece em um agrupamento identificado como tarefa
não informada. A ordem segue as sequências registradas, numericamente quando
possível, com desempate pelo registro. Essa ordem não é anunciada como ordem planejada.

Não há TOP, paginação ou limite fixo na consulta de atividades do detalhe.
A camada de apresentação rejeita toda a população opcional se receber uma
linha de outra filial/O.S. Uma falha nesta consulta não oculta a descrição,
os apontamentos, os materiais ou o restante do detalhe.

## Apresentação

A seção **Tarefas e Etapas da Manutenção** segue os componentes existentes:
título de seção, cards de tarefa e tabelas responsivas com código/descrição
das etapas. Descrições longas e quebras de linha são preservadas. Textos são
inseridos por textContent, sem interpretar HTML vindo do TOTVS.

Há dois blocos separados:

- **Atividades planejadas:** mensagem “Não foi possível consultar todas as tarefas
  e etapas planejadas desta O.S. com as permissões atuais do TOTVS.”
- **Atividades apontadas:** origem STL010 identificada; descrições de TT9010 e
  TPA010 quando disponíveis. Cada tarefa apresenta plano e vínculos de sequência
  em uma área recolhível. Ausência de registros é descrita somente como ausência
  nos apontamentos disponíveis, sem afirmar ausência de planejamento.

Os antigos textos de tarefas foram retirados da seção de descrições adicionais,
evitando duplicidade. A descrição principal, a limpeza do checklist, o original
para auditoria, mão de obra, descrições individuais, insumos, horas, filtros,
exportações e Modo TV continuam no fluxo existente.

## Conferência na conexão atual

| O.S. / filial | Resultado comprovado |
| --- | --- |
| 006264 / 01 | Cabeçalho PREVEN, ROTA05, plano 000000, sequência relacionada 000. Dois apontamentos contêm tarefa 000111, descrição MANUTENCAO CORRETIVA, plano 000000, sequências relacionadas 1 e 2, sequência de manutenção 0. TL_ETAPA e TL_SEQTARE estão vazios. A tela apresenta uma tarefa, com os dois contextos e sem inventar etapa. |
| 006342 / 01 | Cabeçalho plano 000005, sequência 001, serviço PREELE. Situação atual C: a regra existente de exclusão de canceladas impede abrir o detalhe. Uma conferência direta somente leitura não retornou tarefas/etapas nos apontamentos. |
| 006341 / 01 | Plano 000005, sequência 001. Nenhuma tarefa/etapa retornou dos apontamentos; a seção preserva a distinção entre ausência de registros e planejamento inacessível. |
| 006955 / 01 | Tarefa 000111 recuperada e descrita pelo catálogo, sem etapa informada. |

Foi confirmada isoladamente, no cadastro da filial 01, a tarefa **000117 —
CHECKLIST CONF. DOC. FML-MM-001-02/03** e a etapa **001992 — REALIZAR CHECKLIST
CONFORME O DOCUMENTO FML-MM-001-02/03**. Isso confirma as descrições informadas
pelo usuário, **não o vínculo delas com 006342**. A impressão foi descrita pelo
usuário; não foi disponibilizado um arquivo para comparação visual direta.

## Testes

- Novos testes PHP: múltiplas tarefas/etapas, duplicidades, planos distintos,
  etapas sem tarefa, ausência de registros, textos longos, 1.500 etapas sem
  limite, isolamento filial/O.S., catálogos sem permissão e falha da consulta
  preservando o detalhe. Resultado: **9 testes, 81 asserções, aprovados**.
- Todos os testes JavaScript existentes e atualizados: **17 aprovados**, incluindo
  TV, dashboard, setores, autocomplete, horas, insumos, histórico e nova seção.
  A apresentação foi exercitada com 120 etapas e textos longos usando o DOM de teste.
- Sintaxe PHP e git diff --check aprovados.
- Suíte PHP completa `php vendor/phpunit/phpunit/phpunit`: **276 testes,
  4.430 asserções, todos aprovados**.

Para executar JavaScript foi preparado um Node portátil em `tmp/node-runtime`,
sem instalação global. O arquivo foi conferido com SHA-256 do manifesto oficial.
Os scripts de diagnóstico estão em tmp e não fazem parte do controle de versão.
Não foi realizada validação visual interativa em navegador.

## Arquivos desta etapa

- `src/Service/Protheus/OrderTaskQueries.php` (novo)
- `src/Service/Protheus/OrderDescriptionQueries.php`
- `src/Service/Protheus/ProtheusQueries.php`
- `src/Service/Protheus/ProtheusRepository.php`
- `src/Service/Protheus/Presentation/OrderTaskActivities.php` (novo)
- `src/Service/Protheus/Presentation/OrderSupplement.php`
- `src/Service/Protheus/Presentation/OrderSupplementMapper.php`
- `src/Service/Protheus/Presentation/OrderDetailPresenter.php`
- `webroot/js/pcm-protheus-order.js`
- `tests/TestCase/Service/Protheus/OrderTaskActivitiesTest.php` (novo)
- `tests/js/pcm-protheus-order.test.cjs`
- Este relatório (novo).

## Pendência para concluir o planejamento

É necessário disponibilizar uma fonte oficial de leitura que permita comprovar
a relação filial/O.S./plano/sequências/tarefa/etapa: por permissões apropriadas
de consulta e metadados ou por uma view oficial. Depois, o vínculo deve ser
validado contra a impressão da 006342 e outras O.S. com múltiplas tarefas/etapas.
Liberar somente o catálogo TPA010 não supre esse relacionamento.

Até essa comprovação, `planning_state` permanece `unavailable`; os registros
de STL010 jamais são apresentados como planejamento completo. Nenhum dado,
estrutura ou permissão do TOTVS foi alterado nesta implementação.
