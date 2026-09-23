# Histórico de manutenção por equipamento

Implementado em `ProtheusRepository::findEquipmentHistory($equipmentCode,
$branch = null, $page = 1, $limit = 20)`. Retorna `equipment_code`, `branch`,
`page`, `limit`, `has_more`, `orders` e disponibilidade das colunas de usuários.
A conexão/driver existentes foram reutilizados. Nenhuma tela, regra de status,
dashboard, CSV, MariaDB ou migration foi alterada.

## Diagnóstico no PC da empresa

```powershell
php bin/cake.php protheus_health --bem "MEL 80 115" --filial 01
php bin/cake.php protheus_health --bem "MEL 80 115" --filial 01 --pagina 2 --limite 20
```

Sem `--filial`, retorna todas as filiais para o código exato, identificadas em cada
linha. Para este bem, prefira `01`, conforme o cadastro já validado. Não existe
filial padrão implícita. `--os` e `--bem` são mutuamente exclusivos. O modo `--os`
e o diagnóstico `SELECT 1` continuam disponíveis.

A saída JSON resumida informa nomes, códigos, situação/término brutos, data de
referência e datas/horas de manutenção. Não imprime usuários, credenciais, host,
mensagens internas do driver ou descrição integral. Página vazia é sucesso e
retorna lista vazia; erros de parâmetros/consulta retornam código 1. O repositório
retorna a descrição integral convertida para texto e os campos de usuários quando
existem, para futura integração autorizada no detalhe/apresentação.

## Consulta e campos

`ProtheusQueries::equipmentHistory()` produz somente oito variantes fixas de SQL:
com/sem filtro de filial e com/sem cada coluna opcional de usuário. Todos os valores
são parâmetros. `ProtheusReadOnly` aceita somente a correspondência exata a essas
variantes; não há suporte a SQL, tabela, ordenação ou coluna fornecida pelo usuário.

O SELECT explícito contém:

- Identidade: `TJ_FILIAL`, `TJ_ORDEM`, `TJ_CODBEM`, `R_E_C_N_O_` como `record_id`.
- Nomes: `T9_NOME` de ST9010 e `T4_NOME` de ST4010.
- Serviço/tipo/área/centro de custo: `TJ_SERVICO`, `TJ_TIPO`, `TJ_CODAREA`, `TJ_CCUSTO`.
- Situação/término: `TJ_SITUACA`, `TJ_TERMINO`, sem tradução nem regra do PCM.
- Descrição: `CONVERT(VARCHAR(MAX), TJ_OBSERVA)`.
- Origem: `TJ_DTORIGI`.
- Datas/horas: `TJ_DTPPINI/TJ_HOPPINI`, `TJ_DTPPFIM/TJ_HOPPFIM`,
  `TJ_DTPRINI/TJ_HOPRINI`, `TJ_DTPRFIM/TJ_HOPRFIM`,
  `TJ_DTMPINI/TJ_HOMPINI`, `TJ_DTMPFIM/TJ_HOMPFIM`,
  `TJ_DTMRINI/TJ_HOMRINI`, `TJ_DTMRFIM/TJ_HOMRFIM`.
- Candidatos de usuários início/fim: `TJ_USUAINI`, `TJ_USUAFIM`.

Datas/horas originais são preservadas, sem combinar campos nem interpretar códigos.
A consulta `HISTORY_USER_COLUMNS` verifica as duas colunas opcionais usando
`COL_LENGTH`, uma vez por instância do repositório. Ausência/invisibilidade resulta
em null no resultado, sem SQL que referencie uma coluna inexistente. A existência
física não confirma a semântica desses campos: confira no dicionário da empresa.
Não há consulta ao cadastro de usuários. E/T não são interpretados.

## Paginação, ordenação e custo

Página começa em 1; limite padrão 20, máximo 100. `OFFSET/FETCH` usa parâmetros
inteiros e busca `limite + 1` registros, removendo o excedente em PHP para informar
`has_more`. Não há consulta de total geral. A CTE pagina os IDs antes de enriquecer
com nomes e converter o varbinary da descrição. STL010, ST1010 e SB1010 não são
consultadas; findOrder() não é chamado para cada linha.

Ordenação adotada: **TJ_DTORIGI decrescente**, convertida de `YYYYMMDD` com
`TRY_CONVERT(date, NULLIF(..., ''), 112)`. Datas vazias/inválidas tornam-se null e
ficam por último no DESC do SQL Server, sem substituir por hoje, 1900 ou outra
data inventada. `R_E_C_N_O_ DESC` desempata, inclusive para OS sem data. `reference_date`
retorna a data válida em ISO ou null. A origem não significa necessariamente
execução real nem data de criação: confirmar se é o critério desejado de recência.

Filtros de código/filial usam igualdade e CAST no **parâmetro**, sem funções sobre
as colunas de busca. As comparações SQL Server de CHAR/VARCHAR consideram espaços
finais por padding; valores retornados passam pelo tratamento existente em read().
Todas as tabelas aplicam `D_E_L_E_T_ <> '*'`. Não há NOLOCK ou escrita.

Os agregados correlacionados dos cadastros retornam uma linha por OS. Usam a filial
da OS; na ausência de cadastro local, admitem cadastro compartilhado de filial
vazia. Nunca buscam uma filial diferente não vazia. Mais de um cadastro na filial
escolhida causa erro seguro. Cadastro ausente/excluído deixa o nome null, sem
eliminar a OS. Essa prioridade local/compartilhado precisa ser confirmada para
ST9 e ST4 no ambiente (compartilhamento por empresa/unidade pode exigir outro mapa).

Um COUNT por janela, restrito ao equipamento/filial selecionado, detecta OS repetida
pela chave `(TJ_FILIAL, TJ_ORDEM)`, mesmo se as linhas caírem em páginas diferentes.
Duplicidade na página ou linha extra interrompe o resultado, sem escolher uma OS
arbitrária. Isso pressupõe R_E_C_N_O_ único e identidade filial+número; validar
essas propriedades no ambiente antes de ativar a interface.

A ordenação por data convertida e a janela de duplicidades exigem processamento
do conjunto filtrado. Não é possível prometer ausência de scan sem verificar o
plano real e os índices existentes. OFFSET também fica mais caro em páginas muito
profundas; avaliar paginação por cursor se o volume justificar. Atualizações no
Protheus entre chamadas podem deslocar páginas; determinismo não é snapshot entre
requisições. Nenhum índice será criado por esta integração.

## Validação e pendências

Testes isolados com mocks, sem bootstrap de migrations nem SQL Server:

```powershell
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php tests/TestCase/Service/Protheus/EquipmentHistoryTest.php
```

O teste real de conexão/OS informado pelo usuário confirma a infraestrutura, mas
não a nova consulta. Conferir esta consulta no PC da empresa: presença dos campos
explícitos, nomes, filial 01, usuários opcionais, datas inválidas/vazias, desempates,
última página e tempo de resposta. Os campos adicionais não foram obtidos do JSON
real nesta etapa. A interface de apresentação futura e seus links continuam
desativados; o método aqui retorna uma página bruta isolada do MariaDB.

Referências para os campos padrão e comparação de espaços (não substituem a
validação do dicionário local):

- [TOTVS — campos da alteração de OS](https://tdn.totvs.com/pages/viewpage.action?pageId=805979494).
- [TOTVS — datas da OS](https://centraldeatendimento.totvs.com/hc/pt-br/articles/1500004649001-Manufatura-Linha-Protheus-MNT-Composi%C3%A7%C3%A3o-das-datas-da-ordem-de-servi%C3%A7o).
- [Microsoft — igualdade de strings e espaços finais](https://learn.microsoft.com/en-us/sql/t-sql/language-elements/string-comparison-assignment).
