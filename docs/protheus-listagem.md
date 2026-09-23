# Listagem direta de Ordens de Serviço

`GET /pcm/ordens` consulta o SQL Server em cada acesso, através de
`OrderListingService → ProtheusRepository::findOrders() → ProtheusQueries::orders()`.
Não consulta snapshots, não sincroniza com MariaDB e não precisa de uma importação
para encontrar uma OS. Login/permissões continuam usando o banco operacional PCM.
O menu **Ordens de Serviço** abre essa listagem.

## Consulta e campos

Filtros GET `os`, `filial`, `bem`: igualdade exata, parâmetros string, mantendo zeros
à esquerda. Campos vazios na pesquisa significam ausência de filtro; sem filial,
os resultados de todas as filiais são identificados individualmente.
O SQL usa oito variantes fechadas na allowlist do ProtheusReadOnly.
Comparações VARCHAR do SQL Server respeitam espaços finais sem aplicar RTRIM nas
colunas indexadas. Todos os registros consultados respeitam `D_E_L_E_T_ <> '*'`.

A página é obtida de STJ010 antes de consultar nomes em ST9010/ST4010.
Os cadastros usam filial da OS, depois filial compartilhada em branco. Quatro
OUTER APPLY agregam cada cadastro sem multiplicar as OS; ambiguidades de identidade
ou cadastro provocam fallback seguro, sem escolher silenciosamente outro registro.
Não há STL010, hidratação por linha, SELECT *, leitura de descrição binária ou
consulta de usuários opcionais na listagem.

Campos selecionados: `R_E_C_N_O_`, `TJ_FILIAL`, `TJ_ORDEM`, `TJ_CODBEM`, `T9_NOME`,
`TJ_SERVICO`, `T4_NOME`, `TJ_TIPO`, `TJ_CODAREA`, `TJ_CCUSTO`, `TJ_SITUACA`,
`TJ_TERMINO`, `TJ_DTORIGI`, datas/horas `TJ_DTPPINI/HOPPINI/DTPPFIM/HOPPFIM`,
`TJ_DTPRINI/HOPRINI/DTPRFIM/HOPRFIM`, `TJ_DTMPINI/HOMPINI/DTMPFIM/HOMPFIM`,
`TJ_DTMRINI/HOMRINI/DTMRFIM/HOMRFIM`. O template compartilhado também projeta
TJ_USUAINI/TJ_USUAFIM como NULL na listagem; eles não são consultados nem interpretados.
Tipo, situação e término são códigos brutos; COR não é traduzido.

`page` começa em 1 e `limite` em 20 (máximo 100). OFFSET/FETCH traz limite + 1
para detectar “Ver mais”, sem COUNT global nem total de páginas.
Ordenação: primeira data válida entre DTMRFIM, DTMRINI e DTORIGI, decrescente;
datas ausentes/inválidas no final; R_E_C_N_O_ DESC desempata. A lógica de cadastros
e datas é compartilhada com o histórico previamente validado.
Páginas refletem o estado do servidor a cada requisição; alterações concorrentes
podem deslocar registros entre páginas. Validar tempo de resposta da consulta sem
filtros com a carteira real; o timeout de consulta web é limitado a cinco segundos.

## Detalhe e fallback

`/pcm/protheus/os/004368?filial=01` abre o detalhe sem ID local. A filial é
obrigatória nessa rota (inclusive `filial=` para uma filial realmente em branco).
O endpoint `/pcm/protheus/os/004368/dados?filial=01` reutiliza OrderProtheusService
e a interface já existente: manutenção, múltiplos apontamentos, materiais e histórico
paginado, carregados sob demanda. As rotas locais `/pcm/os/{id}` permanecem intactas.
Todas as rotas mantêm autenticação e restrição do perfil TV; respostas não são cacheadas.

Falhas exibem somente aviso genérico. A listagem oferece **Consultar legado Excel**
em `/pcm/ordens/legado`, deixando claro que ele pode estar desatualizado. Não há troca
silenciosa de fonte nem erro técnico exposto. Dashboards e seus links de detalhamento
continuam usando o legado e as mesmas regras. Nenhuma tabela, migration, conexão,
importação ou regra de indicadores foi alterada. Nenhum agendamento foi criado.

## Validação no PC da empresa

1. Logado no PCM, abrir `/pcm/ordens?os=004368&filial=01`, inclusive sem a OS no Excel.
2. Confirmar MEL 80 115, MOTOR ROSCA RO-02 - SILO 01, ELEPRE / PREVENTIVA ELETRICA,
   referência 2026-08-18 e código bruto COR. Abrir o detalhe e conferir descrição,
   mão de obra, materiais 002075/000110 e paginação do histórico.
3. Pesquisar 004893, conferir sua filial real e abrir o detalhe; validar profissional
   008382 / DAMIAO GONCALVES, 1 H, 18/08/2026, 09:30–10:30.
4. Limpar filtros, testar equipamento, filiais e “Ver mais”. Confirmar desempenho.
5. Com Protheus indisponível em ambiente controlado, conferir aviso genérico e acesso
   ao legado. Verificar que cards, TV, importações e detalhe local seguem funcionando.

Testes automatizados direcionados usam mocks, sem bootstrap de migrations ou conexão
real: OrderListingTest, EquipmentHistoryTest, OrderProtheusServiceTest e
ProtheusOrderAccessTest. O teste legado PcmControllerTest teve apenas URLs ajustadas;
sua suíte com banco não foi executada nesta etapa.
