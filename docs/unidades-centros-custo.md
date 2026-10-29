# Unidades por centro de custo — 29/10/2026

## Verificação anterior à alteração

Fonte localizada: `C:/Users/maria.goncalves/Desktop/Cadastro de Centro de Custo (4) (1).xlsx`.
Os 155 registros correspondem às contagens informadas no pedido: Administrativo 39,
Fabrica 17, Fabrica - entressafra 6, Usina 31, Usina - entressafra 5, Externo 23,
Setores 34. Todos os códigos originais são textos, com pontos e zeros preservados.

Os 155 códigos compactos correspondentes foram confirmados em CTT010, filial 01,
sem colisões. O catálogo local conserva o código oficial e seu código Protheus
verificado; a comparação utiliza esses códigos completos, nunca prefixos ou nomes.

Na consulta somente leitura de todas as O.S. ativas não canceladas, sem corte
operacional, existem 18 O.S. em centros marcados como entressafra na planilha.
A regra existente de classificação por serviço reconhece 1 como ENTRESSAFRA e
17 como OUTROS (que entra em Safra nos indicadores). Essa divergência foi registrada
antes de alterar o classificador de unidades. **PcmServiceClassifier e as regras
de Safra/Entressafra por serviço serão preservados.** A marcação da planilha será
apenas metadado adicional, sem mover automaticamente esses 17 registros entre cards.

Há 106 O.S. sem centro de custo e 59 O.S. com seis códigos não presentes na planilha:
1201002, 1201005, 1201008, 1201010, 1201011 e 1201012. Serão Não classificados.
As quantidades são diagnósticas, da população total acima, não os KPIs operacionais.

## Cadastro incorporado

O arquivo `resources/data/cost-center-units.php` contém a fotografia dos 155
registros da planilha, incluindo código oficial, código Protheus confirmado,
descrição, grupo original, unidade principal e sinalizador adicional de entressafra.
SHA-256 da fonte: `5bfac50cc79068dbf4485cb63f59d2e897b1120c583ba983c41372ac5d8d90e1`.
O hash foi novamente conferido ao finalizar; a planilha original não foi modificada.

| Unidade principal | Centros cadastrados | Informação adicional |
| --- | ---: | --- |
| Adm | 39 | Administrativo |
| Fábrica | 23 | 17 Fabrica + 6 Fabrica - entressafra |
| Usina | 36 | 31 Usina + 5 Usina - entressafra |
| Externo | 23 | Externo |
| Setores | 34 | Setores |
| Total | 155 | Sem códigos duplicados |

ProtheusUnit é a implementação única para PHP, filtros SQL, nomes exibidos e
metadados de exportação. O cadastro PHP é carregado uma vez por requisição;
nenhuma requisição depende de abrir o Excel. Zeros e pontos permanecem nos
códigos oficiais. Os equivalentes compactos são aliases explícitos verificados
em CTT010; não há remoção indiscriminada de pontuação, aproximação por nome
ou classificação por prefixo. Códigos desconhecidos e vazios são Não classificados.

No SQL, o catálogo é uma relação somente leitura em OPENJSON, usada com EXISTS
ou NOT EXISTS. Isso evita multiplicar O.S. por junções e reduziu o custo da
primeira implementação com listas condicionais extensas. A comparação explicita
COLLATE DATABASE_DEFAULT, evitando o conflito de collation entre as chaves JSON
e os campos Protheus. A lista fechada do driver continua validando as consultas.

## Filtros e persistência

As seis opções Todas, Adm, Fábrica, Usina, Externo e Setores ficam acima do
formulário de equipamento/datas. São links GET que aplicam imediatamente a
unidade e preservam os filtros confirmados, reiniciando a paginação em 1.
O botão Aplicar confirma somente as alterações de equipamento e período,
mantendo a unidade atual em campo oculto.

Alterações ainda não aplicadas são preservadas em sessionStorage apenas durante
a troca de unidade. Elas voltam aos campos sem entrar na consulta da página;
um aviso explica que os dados continuam usando os filtros confirmados da URL.
O estado efetivo nunca depende exclusivamente de JavaScript. Links, paginação,
drilldowns, retornos, atualizações e exportações continuam transportando GET.

Se o equipamento não produzir registros na nova unidade e nos demais filtros,
a página conserva a seleção, apresenta os resultados vazios e oferece remover
somente o filtro de equipamento. Não atribui o equipamento a uma unidade por
presunção nem remove silenciosamente a seleção. A mensagem considera os cards
de Safra/Entressafra e o backlog, evitando afirmar ausência quando há registros
em uma dessas populações.

Todas conserva o comportamento anterior de incluir também os não classificados,
para não ocultar dados da carteira. Esses registros ficam identificados nas
tabelas e podem ser consultados pelo filtro Não classificados da listagem geral;
o parâmetro legado `other` continua válido. Portanto, a soma das cinco unidades
mais os não classificados fecha com Todas. Não foi criado um sétimo botão setorial.

## Aplicação global e regras preservadas

- As consultas de listagem geral, setores, indicadores, histórico, rankings,
  backlog, plano de ação, paradas por oportunidade e exportações utilizam o
  classificador central. As antigas expressões por prefixo foram substituídas.
- As populações históricas que antes admitiam somente Fábrica/Usina passam a
  admitir as cinco unidades cadastradas; não classificados continuam fora dessa
  população histórica específica. As regras de data, status, Safra por serviço,
  equipamentos genéricos e cálculos não foram substituídas.
- As tabelas e os detalhes mostram a unidade da O.S. e a informação original do
  centro de custo. A indicação de entressafra do cadastro é informação adicional,
  independente dos cards calculados por PcmServiceClassifier.
- Os relatórios Excel acrescentam ao final Unidade da O.S. e Classificação do
  centro de custo. As colunas anteriores e suas posições foram preservadas.
- Setores executantes continuam sendo TJ_CODAREA. A unidade Setores vem de
  TJ_CCUSTO e não altera Elétrica e Instrumentação, Mecânica ou Caldeiraria.
- A descrição principal, seu checklist, o original de auditoria, apontamentos,
  descrições individuais, insumos, duração de horas e Modo TV foram preservados.

O dashboard completo mantém prazo total limitado a 12 segundos para sua sequência
de consultas, com teto de 5 segundos por consulta SQL e timeout de 15 segundos
no cliente. O Modo TV continua com orçamento total de 5 segundos. A alteração do
prazo do dashboard evita abandonar o detalhamento após concluir os agregados;
as consultas reais foram validadas dentro desses limites.

## Tarefas e etapas

A ausência de atividades identificadas agora mostra somente a mensagem principal
**Nenhuma tarefa ou etapa identificada para esta O.S.**, seguida da indicação
discreta de que o planejamento completo não foi verificado. Falha da fonte é
tratada separadamente, sem afirmar ausência de registros. Havendo atividades,
continuam os agrupamentos dinâmicos de todas as tarefas/etapas comprovadas.

A conexão atual confirma SELECT em TT9010 e TPA010, mas ST5010 permanece com
SELECT=0 e VIEW DEFINITION=0. TPA010 é um cadastro, não uma relação O.S./etapa.
Repetida a conferência: 006264 possui tarefa 000111 em dois apontamentos e nenhum
TL_ETAPA preenchido; 006341 não retorna atividades apontadas. A 006342 está com
situação C e não é exibida pelo detalhe segundo a regra existente; seus
apontamentos também não retornaram tarefas/etapas.

Os cadastros confirmam 000117 e 001992 com as descrições informadas pelo usuário,
mas não foi possível comprovar o vínculo planejado de 001992 com 006342. Não foi
associada a etapa à 006264 ou a outra O.S. O planejamento continua parcial até
ser disponibilizada uma fonte oficial com suas chaves de relacionamento.

## Validação e resultados

- Suíte PHP completa: **273 testes e 5.596 asserções, aprovados**.
- Suíte JavaScript completa: **21 testes, aprovados**.
- Testes executam o predicado SQL sobre cada código oficial e alias, com a função
  JSON equivalente do SQLite; confirmam correspondência com PHP, as cinco unidades,
  ausência de duplicidades, valores vazios/desconhecidos e preservação dos códigos.
- Testes de interface verificam troca de unidade, preservação dos campos pendentes,
  envio somente dos filtros confirmados, estado visual e navegação GET.
- Testes de Excel verificam as colunas adicionais sem deslocar as anteriores,
  tipos de texto, datas e proteção contra fórmulas. Testes existentes de tarefas
  continuam cobrindo múltiplas etapas, textos longos e indisponibilidade de fontes.
- Sintaxe PHP, sintaxe JavaScript e git diff --check aprovados.

Conferência real da carteira operacional unificada ELETRI/INSTRU:

| Filtro | O.S. |
| --- | ---: |
| Todas | 946 |
| Adm | 9 |
| Fábrica | 419 |
| Usina | 456 |
| Externo | 0 |
| Setores | 37 |
| Não classificados | 25 |

A soma fecha em 946, com 946 identidades distintas filial/número. Cards de Safra,
Entressafra e classificação também fecham na partição dessas unidades. Para cada
unidade foram validadas consultas reais de dashboard/gráficos, listagem geral e
exportação de apontamentos; as linhas retornadas respeitam o mesmo catálogo.
Essas quantidades são uma fotografia da consulta, não valores fixos do sistema.

Foi usado Edge headless para validar desktop (1440px) e celular (390px), com
prévias geradas pelos templates e dados reais. A navegação foi interceptada
no navegador de teste, sem utilizar a sessão pessoal do usuário. O teste confirmou
seis botões, navegação imediata, URL com filtros confirmados, restauração de
rascunhos, destaque da unidade e ausência de erros JavaScript. O backend foi
validado separadamente pelo teste HTTP autenticado. Capturas em
`tmp/unit-filters-desktop.png` e `tmp/unit-filters-mobile.png`.

## Arquivos desta solicitação

Implementação e dados:

- `resources/data/cost-center-units.php` (novo)
- `src/Service/Protheus/ProtheusUnit.php`
- `src/Service/Protheus/CostCenterUnitQueries.php` (novo, diagnóstico somente leitura)
- `src/Service/Protheus/ActionPlanQuery.php`
- `src/Service/Protheus/OpportunityStopService.php`
- `src/Service/Protheus/ProtheusQueries.php`
- `src/Service/Protheus/ProtheusRepository.php`
- `src/Service/Protheus/ProtheusDashboardService.php`
- `src/Service/Protheus/Presentation/OrderSupplementMapper.php`
- `src/Service/Protheus/Presentation/OrderDetailPresenter.php`
- `src/Service/OrderExcelReport.php`
- `src/Service/OrderEntryExcelReport.php`
- `src/Service/OrderStreamingExport.php`

Interface:

- `templates/Pcm/sector_protheus.php`
- `templates/Pcm/orders.php`
- `templates/Pcm/equipment.php`
- `templates/element/protheus_sector_content.php`
- `templates/element/protheus_unit_label.php` (novo)
- `webroot/js/pcm-sector-unit-filter.js` (novo)
- `webroot/js/pcm-protheus-order.js`

Testes e documentação:

- `tests/TestCase/Controller/PcmControllerTest.php`
- `tests/TestCase/Controller/ProtheusOrderAccessTest.php`
- `tests/TestCase/Service/ActionPlanTest.php`
- `tests/TestCase/Service/OrderExcelReportTest.php`
- `tests/TestCase/Service/OrderEntryExcelReportTest.php`
- `tests/TestCase/Service/OrderStreamingExportTest.php`
- `tests/TestCase/Service/Protheus/ProtheusUnitTest.php`
- `tests/TestCase/Service/Protheus/ProtheusSectorTest.php`
- `tests/TestCase/Service/Protheus/ProtheusDashboardTest.php`
- `tests/TestCase/Service/Protheus/ProtheusOperationalEligibilityTest.php`
- `tests/TestCase/Service/Protheus/OrderListingTest.php`
- `tests/TestCase/Service/Protheus/OrderEntryExportServiceTest.php`
- `tests/TestCase/Service/Protheus/EquipmentHistoryTest.php`
- `tests/TestCase/Service/Protheus/OpportunityStopServiceTest.php`
- `tests/js/pcm-protheus-order.test.cjs`
- `tests/js/pcm-sector-unit-filter.test.cjs` (novo)
- Este relatório.

Os scripts e resultados de diagnóstico e o navegador portátil de teste estão em
tmp, fora do controle de versão. Nenhum registro, estrutura ou permissão do TOTVS
foi alterado. Nenhum arquivo do PCM-Solicitacao-OS foi modificado.
