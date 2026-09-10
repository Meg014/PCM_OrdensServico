# Implantação PCM com MariaDB externo no Windows

O banco pode estar na mesma máquina ou em outro servidor. O projeto usa o datasource `default`, `Cake\Database\Driver\Mysql` e `encoding=utf8mb4` tanto na web quanto na CLI. O MariaDB antigo continua compatível: mudar de instalação exige apenas configurar o destino. Nenhuma migration, importação ou regra de snapshot foi alterada por esta preparação.

## 1. Antes da mudança: backup do ambiente atual

Pause o agendamento de importação durante a migração e registre host, porta, banco, versão do servidor e totais atuais dos indicadores. Preserve os arquivos de configuração locais, relatórios e cópias processadas em local protegido.

Se o banco antigo estiver acessível, use o utilitário de dump compatível com a versão dele (`mysqldump.exe` no XAMPP ou `mariadb-dump.exe`). No PowerShell, preencha os valores solicitados:

```powershell
$dumpExe = Read-Host 'Caminho completo do mysqldump.exe ou mariadb-dump.exe do banco antigo'
$oldDbHost = Read-Host 'Host do banco antigo'
$oldDbPort = Read-Host 'Porta do banco antigo'
$backupUser = Read-Host 'Usuario autorizado para backup'
$backupDirectory = Read-Host 'Diretorio existente e protegido para o backup'
$backupFile = Join-Path $backupDirectory ('pcm-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N') + '.sql')
& $dumpExe --single-transaction --routines --triggers --default-character-set=utf8mb4 --skip-add-drop-table --host=$oldDbHost --port=$oldDbPort --user=$backupUser -p --result-file=$backupFile pcm
if ($LASTEXITCODE -ne 0) { throw 'Backup falhou; nao prossiga com a migracao.' }
Get-Item -LiteralPath $backupFile
```

`-p` solicita a senha sem colocá-la na linha de comando. `--result-file` evita conversões de encoding pelo redirecionamento do Windows PowerShell. Valide a restauração em uma instância separada antes da troca. O dump acima não inclui comandos para apagar tabelas existentes. Não copie ou tente reparar arquivos físicos do MariaDB/XAMPP. Se o banco antigo estiver indisponível, preserve-o e trate a recuperação lógica com a TI; importar novamente os relatórios não recupera automaticamente todo o histórico.

## 2. Instalar MariaDB como serviço independente

1. Baixe o MSI x64 do MariaDB pelo site oficial, em uma versão mantida e homologada pela TI.
2. Execute o instalador como administrador e selecione a criação de uma nova instância e **Install as service**. Neste guia, o nome escolhido é `MariaDB-PCM`.
3. Escolha diretórios próprios para a instalação e os dados, fora do XAMPP. Não selecione o diretório de dados antigo.
4. Configure senha administrativa forte, mantenha o acesso remoto de `root` desabilitado e habilite TCP/IP.
5. Use a porta `3306` se estiver livre. Para manter o banco antigo funcionando em paralelo, escolha outra porta, por exemplo `3307`, e informe esse valor em `DB_PORT`. Nunca inicie as duas instâncias na mesma porta.
6. Configure inicialização automática do novo serviço. Para banco remoto, a TI deve limitar o firewall aos servidores autorizados e configurar TLS conforme a política interna.

No PowerShell elevado, usando o nome escolhido no instalador:

```powershell
$dbService = Read-Host 'Nome do novo servico MariaDB (exemplo: MariaDB-PCM)'
Set-Service -Name $dbService -StartupType Automatic
Start-Service -Name $dbService
Get-Service -Name $dbService
$dbHost = Read-Host 'Host do novo MariaDB'
$dbPort = Read-Host 'Porta do novo MariaDB'
Test-NetConnection -ComputerName $dbHost -Port ([int]$dbPort)
```

Não use o MySQL/MariaDB do XAMPP em produção. O PHP pode continuar sendo o da sua instalação de desenvolvimento; ele não depende do serviço de banco do XAMPP. No servidor, provisione PHP e servidor web conforme o guia de TI.

## 3. Criar banco e usuário

Com o diretório `bin` do novo MariaDB disponível no PATH, conecte-se explicitamente à nova instância:

```powershell
mariadb --protocol=tcp --host=$dbHost --port=$dbPort --user=root -p
```

Execute no cliente SQL, substituindo o placeholder da senha antes de executar:

```sql
SELECT VERSION(), @@hostname, @@port;

CREATE DATABASE pcm
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

CREATE USER 'pcm_app'@'127.0.0.1' IDENTIFIED BY '<SUBSTITUIR_POR_SENHA_FORTE>';
GRANT SELECT, INSERT, UPDATE, DELETE ON pcm.* TO 'pcm_app'@'127.0.0.1';

CREATE USER 'pcm_migrator'@'127.0.0.1' IDENTIFIED BY '<OUTRA_SENHA_FORTE>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
ON pcm.* TO 'pcm_migrator'@'127.0.0.1';
```

O exemplo é para PHP e banco na mesma máquina, conectando por TCP em `127.0.0.1`. Se o MariaDB estiver remoto, substitua a parte `@'127.0.0.1'` nas duas contas pelo IP de origem do servidor PHP autorizado. Teste a autenticação com essa origem. Não conceda privilégios globais ao usuário da aplicação.

Os privilégios de `pcm_migrator` cobrem as migrations atuais de avanço; não autorizam rollback destrutivo. Uma atualização futura deve ter seus privilégios revisados. Migrations sempre usam `default`: configure temporariamente a conta de migrations no mesmo arquivo compartilhado, com o site e o agendamento parados, e depois restaure `pcm_app`.

### Preservar histórico ou iniciar um banco vazio

- **Preservar histórico:** restaure o backup lógico validado no novo `pcm` vazio, antes das migrations. Isso preserva também o histórico de migrations e os hashes de relatórios já importados.
- **Banco vazio:** execute migrations e depois importe relatórios. Isso recria apenas os dados representados pelos arquivos disponíveis; não equivale a migrar o histórico inteiro.

Para restaurar, entre como administrador **no novo servidor**, verifique novamente `@@hostname` e `@@port`, selecione o banco e use o caminho do backup com barras `/`:

```sql
USE pcm;
SOURCE C:/CAMINHO_DO_BACKUP/pcm-backup-validado.sql;
```

Use somente um banco de destino vazio para essa restauração. Não execute esses procedimentos no banco antigo.

## 4. Configuração única de CLI, web e agendador

O bootstrap lê exclusivamente `config/.env`, usando o caminho absoluto do projeto, antes de carregar `app.php` e `app_local.php`. Não lê `.env` na raiz. Os três arquivos de exemplo têm o mesmo formato; copie apenas um para `config/.env`.

**Precedência:** valores presentes em `config/.env` substituem os valores do ambiente dos processos. Valores ausentes vêm do ambiente; depois são aplicados os padrões de `app.php`. Configurações explícitas em `app_local.php` continuam sendo overrides locais do CakePHP. Os arquivos locais entregues não redefinem `Datasources.default`.

Para garantir o mesmo destino em todos os processos, preencha **todos os cinco `DB_*`** no arquivo compartilhado e não recrie overrides de `Datasources.default` em `app_local.php`. Não configure `DATABASE_URL`: ele não é mais usado pelo datasource `default`, evitando sobrepor host, porta, driver ou charset. Migre seus componentes para `DB_*`. `DATABASE_TEST_URL` permanece exclusivo dos testes.

Sem `config/.env`, a TI pode usar variáveis do Windows, mas precisa provisionar exatamente os mesmos valores para PHP web, CLI e tarefa agendada e reiniciar esses processos após alterações. Uma variável definida apenas com `$env:DB_HOST` numa janela PowerShell não configura o IIS/Apache.

Na raiz do projeto:

```powershell
if (!(Test-Path -LiteralPath 'config/app_local.php')) {
    Copy-Item -LiteralPath 'config/app_local.example.php' -Destination 'config/app_local.php'
}
if (!(Test-Path -LiteralPath 'config/.env')) {
    Copy-Item -LiteralPath 'deployment/config/env.example' -Destination 'config/.env'
}
notepad config/.env
```

Edite o arquivo existente sem perder os valores locais. Nesta instalação, o caminho anterior dos relatórios foi preservado em `config/.env`; complete os outros campos seguindo o exemplo:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pcm
DB_USERNAME=pcm_app
DB_PASSWORD=
PCM_REPORT_PATH='\\servidor\caminho\relatorios'
DEBUG=false
SECURITY_SALT=
APP_FULL_BASE_URL=https://pcm.example.invalid
APP_DEFAULT_TIMEZONE=UTC
APP_DISPLAY_TIMEZONE=America/Sao_Paulo
```

Preencha `DB_PASSWORD`, `SECURITY_SALT`, o domínio real e o compartilhamento correto. A senha vazia no exemplo é apenas um campo a preencher; não é uma credencial de produção. Os padrões de desenvolvimento, sem configuração, são `127.0.0.1:3306`, banco `pcm`, usuário `root`, sem senha fornecida. Nenhuma senha real é incluída nos arquivos versionáveis.

Gere um salt e transfira o resultado para a configuração local protegida:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Use aspas simples para preservar barras de caminhos UNC, espaços e `#`. Se a senha contiver aspas, respeite o formato do parser dotenv e valide a configuração. Não compartilhe o arquivo com credenciais. Restrinja sua leitura às contas de administração, serviço web e agendador; `config/.env` e `app_local.php` são ignorados pelo Git.

`PCM_REPORT_PATH` continua sendo uma fonte somente leitura. As contas do servidor web e agendador precisam conseguir listar e ler esse compartilhamento. Use uma conta de domínio autorizada e UNC, não unidade de rede mapeada numa sessão interativa. `logs`, `tmp`, processados, erro e staging precisam de escrita. Sem overrides `PCM_REPORTS_*`, os diretórios locais são relativos ao projeto.

## 5. Comandos na máquina de desenvolvimento

Abra PowerShell na raiz do projeto. PHP 8.2+ e Composer devem estar no PATH; habilite as extensões exigidas pelas dependências, incluindo `pdo_mysql`, `intl`, `mbstring`, `zip` e `gd`.

```powershell
php -v
php -m
composer install
if ($LASTEXITCODE -ne 0) { throw 'Falha no Composer.' }
composer check-platform-reqs
```

Após configurar o destino correto e temporariamente `DB_USERNAME=pcm_migrator` com sua senha em `config/.env`:

```powershell
php bin/cake.php migrations status --connection default
php bin/cake.php migrations migrate --connection default
if ($LASTEXITCODE -ne 0) { throw 'Falha nas migrations; nao prossiga.' }
php bin/cake.php migrations status --connection default
```

Retorne `DB_USERNAME=pcm_app` e a senha correspondente no mesmo arquivo. Para o servidor local, use `DEBUG=true` e `APP_FULL_BASE_URL=http://localhost:8765`.

```powershell
php bin/cake.php pcm_health
if ($LASTEXITCODE -ne 0) { throw 'Diagnostico falhou; revise a configuracao.' }
php bin/cake.php import_pending_reports
if ($LASTEXITCODE -ne 0) { throw 'Importacao falhou; consulte os logs.' }
php bin/cake.php server -p 8765
```

Abra `http://localhost:8765` e compare indicadores e última importação com os valores esperados. `php bin/cake.php pcm_health` equivale a `bin\cake pcm_health` e usa explicitamente o PHP encontrado no PATH.

## 6. Comandos e publicação pela TI

Com o site e a tarefa agendada parados, na raiz da implantação:

```powershell
php -v
php -m
composer install --no-dev --optimize-autoloader
if ($LASTEXITCODE -ne 0) { throw 'Falha no Composer.' }
composer check-platform-reqs --no-dev
```

Configure o arquivo compartilhado conforme a seção 4. O parser dotenv é uma dependência de produção e está disponível com `--no-dev`. Use `DEBUG=false`, salt exclusivo e `APP_FULL_BASE_URL` real. Não sobrescreva configurações existentes com exemplos.

Com a conta temporária de migrations configurada em `default`:

```powershell
php bin/cake.php migrations status --connection default
php bin/cake.php migrations migrate --connection default
if ($LASTEXITCODE -ne 0) { throw 'Falha nas migrations; nao publique.' }
php bin/cake.php migrations status --connection default
```

Restaure `pcm_app` e sua senha no arquivo antes de iniciar a operação:

```powershell
php bin/cake.php pcm_health
if ($LASTEXITCODE -ne 0) { throw 'Diagnostico falhou; nao publique.' }
php bin/cake.php import_pending_reports
if ($LASTEXITCODE -ne 0) { throw 'Importacao falhou; consulte os logs.' }
```

Configure IIS/FastCGI com URL Rewrite ou Apache/PHP, apontando exclusivamente para `webroot`. Reinicie o serviço/pool PHP identificado pela TI para carregar a configuração e publique somente na rede interna/controles corporativos. Não use `cake server` em produção. Para Apache instalado como serviço, por exemplo:

```powershell
$webService = Read-Host 'Nome do servico Apache homologado pela TI'
Restart-Service -Name $webService
```

No IIS, use o módulo WebAdministration, com o nome do pool configurado:

```powershell
Import-Module WebAdministration
$pcmPool = Read-Host 'Nome do application pool PCM'
Restart-WebAppPool -Name $pcmPool
```

Escolha apenas a alternativa correspondente ao servidor instalado. Valide a página de importações e os indicadores no navegador antes de reativar a tarefa. A tarefa deve executar `php.exe bin\cake.php import_pending_reports`, com a raiz do projeto como diretório de trabalho e a identidade autorizada no compartilhamento. Os exemplos em `deployment/scripts` recebem os caminhos como parâmetros, sem depender do XAMPP.

Exemplos de chamada, na raiz do projeto (o primeiro executa uma importação; o segundo apenas prepara o exemplo de registro):

```powershell
$pcmProject = $PWD.Path
$pcmPhp = (Get-Command php.exe).Source
& .\deployment\scripts\importar-relatorios.cmd $pcmProject $pcmPhp
$pcmTaskUser = Read-Host 'Conta de dominio para o agendador'
& .\deployment\scripts\registrar-tarefa-exemplo.ps1 -ProjectPath $pcmProject -PhpPath $pcmPhp -TaskUser $pcmTaskUser
```

## 7. Diagnóstico e testes

`pcm_health` consulta apenas o banco, sem importar ou migrar dados. Mostra datasource, classe do driver, host, porta, banco, conexão, versão do servidor, leitura da pasta configurada e última importação. Não imprime senha, DSN ou texto bruto de exceções. Falhas retornam código diferente de zero, mas não interrompem o diagnóstico da pasta. Banco sem tabelas gera orientação para revisar migrations.

O comando `import_pending_reports` usa `ReportFileProcessor` e `ReportImportService`; este obtém `ConnectionManager::get('default')`. As tabelas ORM também usam `default`, sem conexão própria ou credenciais hardcodadas. Snapshot, deduplicação e regras de importação permanecem os existentes.

Em desenvolvimento, execute a suíte com um SQLite **exclusivo para testes**, já suportado pelo projeto. Não defina `DATABASE_TEST_URL` no arquivo de produção:

```powershell
$env:DATABASE_TEST_URL = 'sqlite://127.0.0.1/' + ((Join-Path $PWD ('tmp/test-mariadb-preparation-' + [guid]::NewGuid().ToString('N') + '.sqlite')) -replace '\\', '/')
composer test
composer cs-check
Remove-Item Env:DATABASE_TEST_URL
```

O bootstrap de testes cria alias de `default` para `test` antes das migrations de teste. SQLite não é configurado como banco da aplicação.

## 8. Checklist de migração

- [ ] Pausar importação automática e registrar os indicadores atuais.
- [ ] Fazer backup lógico do banco antigo e testar a restauração.
- [ ] Preservar configurações locais, relatórios e histórico de arquivos.
- [ ] Instalar MariaDB externo em diretório e serviço próprios.
- [ ] Testar o serviço e a porta sem conflito com o XAMPP.
- [ ] Criar banco `pcm` com `utf8mb4` e usuários restritos.
- [ ] Restaurar o backup no destino vazio se for preciso preservar histórico.
- [ ] Apontar aplicação, CLI e agendador para o destino no mesmo `config/.env`.
- [ ] Executar Composer e validar extensões PHP.
- [ ] Rodar migrations em `default` no novo servidor.
- [ ] Restaurar credenciais de aplicação após as migrations.
- [ ] Executar `pcm_health` com sucesso, inclusive leitura do UNC pela conta do serviço.
- [ ] Importar relatórios com `import_pending_reports`.
- [ ] Validar indicadores, snapshot atual, datas, histórico e duplicidade por hash.
- [ ] Publicar em `webroot`, com `DEBUG=false` e domínio configurado.
- [ ] Reativar e validar agendamento; configurar backup do novo serviço.
- [ ] Manter o banco antigo preservado até o aceite da TI.

Para voltar ao ambiente anterior antes do aceite, pare site/agendamento, restaure o destino anterior no arquivo local e reinicie os processos. Dados gravados apenas no novo banco exigem reconciliação; não faça sincronização física dos diretórios.

Referências oficiais: [instalação MSI como serviço Windows](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/installing-mariadb/binary-packages/installing-mariadb-msi-packages-on-windows) e [backup lógico com mariadb-dump](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).
