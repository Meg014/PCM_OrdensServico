# Compatibilidade de snapshots com MariaDB 10.11

## Causa e correção

`ROW_NUMBER` é reservado no MariaDB 10.11.16. Na instância local, `SELECT row_number FROM work_order_snapshots LIMIT 0` falhou com SQLSTATE `42000`; a mesma consulta com crases funcionou. A lista oficial confirma o nome reservado: [MariaDB Reserved Words](https://mariadb.com/docs/server/reference/sql-structure/sql-language-structure/reserved-words).

A migration `20260910000000_RenameSnapshotSourceRowNumber.php` usa `renameColumn()` para renomear `work_order_snapshots.row_number` para `source_row_number`. O adapter MySQL gera `ALTER TABLE ... CHANGE COLUMN`, protegendo os identificadores e preservando a definição original (`INT UNSIGNED NOT NULL`). Não há exclusão, recriação de tabela, atualização de payload, STATUS ou histórico. As seis migrations anteriores permanecem intactas. Em uma instalação nova, a migration de fundação cria a coluna original e a nova migration a renomeia em seguida.

`source_row_number` mantém exatamente o número da linha de origem, inclusive o deslocamento de cabeçalho/preâmbulo considerado pelos leitores atuais. A chave foi atualizada nos leitores CSV/XLSX, mapper, serviço de importação e dados de teste. `raw_payload` e `row_hash` continuam sendo calculados da mesma maneira.

Tables e Entities não tinham referências explícitas ao campo antigo; usam o schema do banco e o acesso genérico já existente. Não foi encontrado índice ou constraint envolvendo o campo antigo. As demais colunas foram verificadas com consultas sem quoting e sem leitura de registros (`LIMIT 0`): 129 colunas, nenhum outro conflito após a renomeação.

## Arquivos da correção

- `config/Migrations/20260910000000_RenameSnapshotSourceRowNumber.php` (novo).
- `config/Migrations/schema-dump-default.lock` (schema regenerado).
- `src/Service/Import/ReportImportService.php`.
- `src/Service/Import/TotvsCsvReader.php`.
- `src/Service/Import/TotvsWorkbookReader.php`.
- `src/Service/Import/TotvsRowMapper.php`.
- `tests/TestCase/Support/PcmSnapshotFixture.php`.
- `tests/TestCase/Service/CurrentPortfolioReplacementTest.php`.
- `tests/TestCase/Service/Import/TotvsCsvReaderTest.php`.
- `tests/TestCase/Service/Import/TotvsWorkbookReaderTest.php`.
- `tests/bootstrap.php` (desativa cache de metadata exclusivamente no datasource `test`).
- `tests/Integration/MariaDbSourceRowNumberTest.php` (novo).
- `fase-1-analise-relatorio-os.md` (nome atual da coluna).
- Este documento.

Não há mais uso da chave antiga no código de execução em `src` nem nos testes funcionais atuais. O nome antigo permanece intencionalmente na migration histórica, na operação de renomeação da nova migration e no teste de regressão que simula a coluna antiga. Logs e artefatos temporários de diagnóstico podem registrar o erro antigo.

## Implantar em outra máquina

Com a importação/agendamento e o site pausados durante a atualização, copie o código e execute na raiz, usando as credenciais locais autorizadas para migrations:

```powershell
php bin/cake.php migrations migrate --connection default
if ($LASTEXITCODE -ne 0) { throw 'Migration falhou.' }
php bin/cake.php migrations status --connection default
php bin/cake.php schema_cache build --connection default
if ($LASTEXITCODE -ne 0) { throw 'Falha ao renovar metadata.' }
php bin/cake.php migrations dump --connection default
php bin/cake.php pcm_health
```

Renovar o cache é necessário para que o ORM reconheça imediatamente o nome novo, inclusive em produção com cache de longa duração. O dump deve ser regenerado após a atualização desse cache. Reinicie processos PHP persistentes que mantenham metadata em memória e retome a operação após o diagnóstico.

Na máquina atual, esses comandos já foram executados. A importação de relatórios **não** foi executada no datasource real. Quando desejar importar, execute explicitamente:

```powershell
php bin/cake.php import_pending_reports
```

## Teste de regressão em MariaDB/MySQL

```powershell
php vendor/bin/phpunit --no-configuration --bootstrap config/bootstrap.php tests/Integration/MariaDbSourceRowNumberTest.php
```

Esse teste optativo usa as credenciais de `default` em uma conexão privada, não persistente, com `quoteIdentifiers=false`. Requer permissão `CREATE TEMPORARY TABLES`.

Ele cria uma tabela temporária com o schema de snapshots, remove somente as foreign keys da cópia temporária e simula a coluna antiga com uma linha de exemplo. Executa a migration real nessa cópia e compara todos os valores e a definição da coluna antes/depois. Em seguida, salva uma entidade `WorkOrderSnapshot` com `source_row_number=57` pelo ORM e confere sua leitura, payload e hash. Não cria registros pais nem executa DML na tabela permanente; a cópia temporária desaparece ao desconectar. O teste fica fora da suíte padrão para não exigir MariaDB em toda execução.

A suíte padrão continua usando `DATABASE_TEST_URL`, com banco exclusivo para testes. Nunca aponte essa variável para `pcm` operacional, pois o bootstrap de testes gerencia e limpa suas tabelas.

## Validação realizada em 10/09/2026

- MariaDB 10.11.16: teste de migration com dados e INSERT ORM aprovado, 9 assertions, antes e depois da aplicação no banco real.
- Migration aplicada em `default`; todas as sete migrations estão `UP`.
- Cache e schema dump atualizados; `pcm_health` retornou sucesso.
- Definições das colunas e índices preservadas, exceto o nome solicitado. O banco real tinha zero snapshots e continuou com zero.
- SHA-256 dos seis arquivos de migrations históricas conferidos: nenhum foi alterado.
- Suíte padrão em SQLite novo: 81 testes, 79 passaram; dois casos dependem do arquivo ausente `relatorios_teste/Relatorio_OS_2026-08-28.csv`.
- Sintaxe dos 11 PHP alterados/criados aprovada. PHPCS da nova migration, teste MariaDB e bootstrap de testes aprovado; o lint geral continua apresentando problemas anteriores nos demais arquivos.
