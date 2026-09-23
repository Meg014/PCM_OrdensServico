# Protheus: integração inicial de leitura

O datasource `default` continua sendo o MariaDB pcm. A conexão `protheus` é
independente e aberta apenas quando utilizada. CSV, dashboards, indicadores,
autenticação e migrations não usam esta integração.

## Configuração

Adicione a seção PROTHEUS dos arquivos de exemplo ao **config/.env existente**,
sem sobrescrever suas configurações atuais. Esse arquivo e `config/app_local.php`
já são ignorados pelo Git. O bootstrap carrega somente `config/.env`, que prevalece
sobre variáveis do processo. Não salve credenciais nos exemplos.

Preencha `PROTHEUS_DB_HOST`, `PROTHEUS_DB_DATABASE`, `PROTHEUS_DB_USERNAME` e
`PROTHEUS_DB_PASSWORD`. Ajuste `PROTHEUS_DB_PORT` (padrão 1433) se necessário.
`PROTHEUS_DB_ENCRYPT=true` e `PROTHEUS_DB_TRUST_SERVER_CERTIFICATE=false` são
os padrões TLS; use certificado confiável e nome de servidor compatível.
É necessário habilitar **pdo_sqlsrv** no PHP CLI/web e instalar o Microsoft ODBC
Driver compatível. O MariaDB continua usando pdo_mysql.

A conta deve ser previamente provisionada pelo DBA com **somente SELECT** em
`dbo.STJ010`, `dbo.ST9010`, `dbo.ST4010`, `dbo.STL010`, `dbo.ST1010` e `dbo.SB1010`.
Não use conta administrativa, proprietária do banco, com escrita, execução de
procedures ou alteração de schema. A aplicação não cria contas nem permissões.
O driver bloqueia SQL fora da lista exata de consultas, incluindo SQL arbitrário,
ORM, INSERT/UPDATE/DELETE, DDL, EXEC, SELECT INTO e lotes de comandos. Também
bloqueia `exec()` e SQL de inicialização configurável. As permissões no servidor
são a proteção complementar obrigatória, inclusive contra acesso PDO direto.
Não há teste de escrita nem migrations no SQL Server.

## Comandos (na raiz do projeto)

```powershell
php bin/cake.php protheus_health
php bin/cake.php protheus_health --os 004893
php bin/cake.php protheus_health --os 004368
```

O primeiro comando executa apenas `SELECT 1`. Os demais retornam JSON com
`dados_principais`, `equipamento`, `servico`, `descricao`, `mao_de_obra`, `materiais`
e `outros_apontamentos`. Código de saída 0 indica sucesso; 1 indica falha ou OS
inexistente. Erros não imprimem credenciais nem mensagens internas do driver.

Os campos originais dos cadastros e apontamentos são preservados (com remoção de
espaços finais), inclusive quantidade, unidade, data e horários: seus nomes e
semântica não foram fornecidos neste mapeamento, portanto não são renomeados nem
convertidos. A descrição usa `CONVERT(VARCHAR(MAX), TJ_OBSERVA)`; o binário original
é removido do resultado. M recebe profissional de ST1010; P recebe produto de
SB1010. E e T permanecem códigos originais, sem classificação funcional.
Cadastros ausentes/excluídos retornam null sem eliminar a OS ou apontamento.

Todos os filtros de chave e JOINs usam RTRIM e todas as tabelas excluem
`D_E_L_E_T_ = '*'`. Os números são parâmetros string, preservando zeros iniciais.
Os cadastros são consultados separadamente pelas chaves confirmadas, evitando
multiplicar apontamentos em JOINs. A etapa usa schema `dbo` e as seis tabelas
informadas, sem descoberta de outros bancos/tabelas.

### Filiais e duplicidades

Como proteção contra mistura de OS de filiais diferentes, a consulta exige uma
OS única por número; se necessário use `--filial CODIGO`. Para os apontamentos,
assume-se a correspondência **TJ_FILIAL = TL_FILIAL**, além do número da OS.
Essa regra adicional e a existência desses campos precisam ser confirmadas no
ambiente, pois não faziam parte dos relacionamentos validados fornecidos.
Não há fallback silencioso para buscar apontamentos de outra filial.
Cadastros com mais de uma linha ativa para a mesma chave interrompem a consulta:
nesse caso será necessário validar o compartilhamento por filial de cada tabela
antes de ampliar o mapeamento. Não se escolhe arbitrariamente o primeiro registro.

Conferência manual após configurar: OS 004893 deve apresentar FTR 30 001,
COROPE, descrição “LAVAR TODOS 3 FILTRO COM SODA” e profissional 008382 / DAMIAO
GONCALVES, 1 H em 18/08/2026, 09:30–10:30. OS 004368 deve apresentar MEL 80 115,
ELEPRE, descrição “TROCA DOS ROLAMENTOS DO MOTOR” e produtos 002075 / ROLAMENTO
6203 e 000110 / ROLAMENTO 6203 ZZ C3, cada um com 1 UN.

## Validação isolada, sem bootstrap de migrations

```powershell
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php tests/TestCase/Service/Protheus/ProtheusIntegrationTest.php
```

Referências: [conexões CakePHP](https://book.cakephp.org/5.x/orm/database-basics.html)
e [ApplicationIntent no SQL Server](https://learn.microsoft.com/en-us/sql/database-engine/availability-groups/windows/listeners-client-connectivity-application-failover?view=sql-server-ver17).
ApplicationIntent serve para roteamento em grupos de disponibilidade; não é
substituto de permissões SELECT nem da restrição do driver implementada aqui.
