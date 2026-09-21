# Autenticação e usuários

O PCM exige login em suas páginas e endpoints, inclusive TV/apresentação. A integração usa `cakephp/authentication` 3.3.7, compatível com CakePHP 5, conforme a [documentação oficial](https://legacybook.cakephp.org/authentication/3/en/index.html).

## Banco e primeiro acesso

A migration `20260918140000_CreateUsers` cria apenas `users`, com nome, e-mail único, hash da senha, perfil, vínculo opcional com `maintenance_areas`, ativo e timestamps. Não altera as tabelas operacionais. Em outras instalações, confira `php bin/cake.php migrations status` antes de aplicar `php bin/cake.php migrations migrate --target 20260918140000`, para não executar inadvertidamente migrations antigas pendentes.

No PowerShell, na raiz do projeto:

```powershell
.\bin\create-admin.ps1
```

O script pede nome, e-mail e senha oculta; não grava a senha em arquivo ou no histórico de comandos. Ele passa as variáveis de ambiente `PCM_ADMIN_NAME`, `PCM_ADMIN_EMAIL` e `PCM_ADMIN_PASSWORD` ao comando `php bin/cake.php create_admin` e as remove ao terminar. A senha precisa ter entre 12 e 72 caracteres e no máximo 72 bytes. O comando recusa execução quando já existe ADMIN. Não há usuário ou senha padrão.

- Login: `/login`.
- Administração: `/usuarios` (ADMIN).
- Logout: formulário POST `/logout`, no botão Sair.

ADMIN cadastra, edita, ativa/desativa e redefine senhas. USUARIO consulta todos os setores, relatórios, análises e histórico de importações. Importação manual é exclusiva de ADMIN. O setor cadastrado no usuário é informativo, sem restrição de acesso nesta versão. O administrador não pode desativar a si mesmo ou retirar seu próprio perfil ADMIN.

Senhas usam o hasher padrão do plugin (bcrypt neste ambiente); não são serializadas em respostas nem preenchidas nos formulários. A sessão é renovada na autenticação, protegida por HttpOnly, SameSite=Lax e modo estrito; o CakePHP aplica cookie Secure em HTTPS. O timeout de inatividade é de oito horas. CSRF permanece ativo. Cada requisição consulta novamente o usuário: inativação, exclusão e alteração da senha invalidam a sessão, e a autorização usa o perfil atual do banco. Os controladores verificam permissões também pelas rotas alternativas. Respostas autenticadas usam `Cache-Control: no-store`.

## Setores e detalhamento

Os nomes cadastrados em `maintenance_areas.display_name` têm prioridade. Quando vazios ou iguais ao código, a apresentação usa os nomes amigáveis conhecidos. Códigos do TOTVS e registros no banco permanecem intactos.

A ordem padrão das listagens é `maintenance_planned_start DESC, id DESC`. Datas nulas ficam ao final no MariaDB e SQLite. O ID do snapshot desempata datas iguais sem interpretar o número da OS como data. O usuário pode escolher outra ordenação pelos cabeçalhos.

`date_start` e `date_end` filtram apenas o detalhamento de setores e o relatório geral por P. In. Man., combinados com os demais filtros. O limite final é exclusivo à meia-noite do dia seguinte, incluindo todos os horários do último dia. Datas inválidas e intervalos invertidos retornam HTTP 400. Os filtros seguem nos links de paginação. Os indicadores e gráficos não recebem esse filtro adicional de datas.

Permanecem as regras de abertas a partir de 01/01/2026, fechadas de todos os anos, exclusão de canceladas, última importação bem-sucedida e classificações existentes. Não há importação automática nesta entrega.

## Testes

Use um banco SQLite exclusivo para testes:

```powershell
$env:DATABASE_TEST_URL = 'sqlite://127.0.0.1/' + (($PWD.Path + '/tmp/test-auth.sqlite') -replace '\\', '/')
php vendor/phpunit/phpunit/phpunit
Remove-Item Env:DATABASE_TEST_URL
```

Os testes anteriores do leitor CSV dependem do arquivo externo `../relatorios_teste/Relatorio_OS_2026-08-28.csv`. A ausência desse arquivo causa duas falhas independentes da autenticação. Não substitua esse relatório por dados de produção nem execute importação para rodar os testes.

### Resultado da validação em 18/09/2026

- Suíte completa: 114 testes, 764 asserções; 112 testes aprovados, uma falha e um erro nos dois testes do CSV externo ausente.
- Controllers e filtros de datas: 49 testes e 234 asserções aprovados na execução direcionada.
- Comando do primeiro ADMIN: os três testes foram incluídos e passaram na suíte completa.
- PHP_CodeSniffer dos arquivos novos de código e testes: aprovado. A verificação global ainda aponta pendências de estilo em arquivos existentes.
- `git diff --check`: aprovado.
- Servidor local: `/login` retorna HTTP 200; `/pcm` sem sessão redireciona para `/login`.
- Migration de usuários aplicada; nenhum usuário real ou senha padrão foi criado.
- Comparação antes/depois dos indicadores gerais e por setor: idêntica. Total 4.178, abertas 212, fechadas 3.966; importação atual ID 2, relatório de 14/09/2026. Nenhuma importação executada.
- Auditoria Composer: alerta em `composer/composer`, dependência preexistente sem mudança nesta entrega (`CVE-2026-84361`). O pacote de autenticação instalado não aparece nesse alerta.

## Arquivos criados ou alterados

- [README.md](../README.md)
- [bin/create-admin.ps1](../bin/create-admin.ps1)
- [composer.json](../composer.json)
- [composer.lock](../composer.lock)
- [config/Migrations/20260918140000_CreateUsers.php](../config/Migrations/20260918140000_CreateUsers.php)
- [config/app.php](../config/app.php)
- [config/routes.php](../config/routes.php)
- [docs/autenticacao-usuarios.md](../docs/autenticacao-usuarios.md)
- [src/Application.php](../src/Application.php)
- [src/Command/CreateAdminCommand.php](../src/Command/CreateAdminCommand.php)
- [src/Controller/AppController.php](../src/Controller/AppController.php)
- [src/Controller/AuthController.php](../src/Controller/AuthController.php)
- [src/Controller/PcmController.php](../src/Controller/PcmController.php)
- [src/Controller/UsersController.php](../src/Controller/UsersController.php)
- [src/Model/Entity/MaintenanceArea.php](../src/Model/Entity/MaintenanceArea.php)
- [src/Model/Entity/User.php](../src/Model/Entity/User.php)
- [src/Model/Table/MaintenanceAreasTable.php](../src/Model/Table/MaintenanceAreasTable.php)
- [src/Model/Table/UsersTable.php](../src/Model/Table/UsersTable.php)
- [src/Service/PcmPresentationService.php](../src/Service/PcmPresentationService.php)
- [src/Service/SectorDashboardService.php](../src/Service/SectorDashboardService.php)
- [src/View/AppView.php](../src/View/AppView.php)
- [src/View/Helper/MaintenanceAreaHelper.php](../src/View/Helper/MaintenanceAreaHelper.php)
- [templates/Auth/login.php](../templates/Auth/login.php)
- [templates/Pcm/movement.php](../templates/Pcm/movement.php)
- [templates/Pcm/order.php](../templates/Pcm/order.php)
- [templates/Pcm/quality.php](../templates/Pcm/quality.php)
- [templates/Pcm/sector.php](../templates/Pcm/sector.php)
- [templates/ReportImports/index.php](../templates/ReportImports/index.php)
- [templates/Users/form.php](../templates/Users/form.php)
- [templates/Users/index.php](../templates/Users/index.php)
- [templates/Users/password.php](../templates/Users/password.php)
- [templates/layout/default.php](../templates/layout/default.php)
- [tests/TestCase/Command/CreateAdminCommandTest.php](../tests/TestCase/Command/CreateAdminCommandTest.php)
- [tests/TestCase/Controller/AuthUsersControllerTest.php](../tests/TestCase/Controller/AuthUsersControllerTest.php)
- [tests/TestCase/Controller/PagesControllerTest.php](../tests/TestCase/Controller/PagesControllerTest.php)
- [tests/TestCase/Controller/PcmControllerTest.php](../tests/TestCase/Controller/PcmControllerTest.php)
- [tests/TestCase/Controller/PcmEmptyControllerTest.php](../tests/TestCase/Controller/PcmEmptyControllerTest.php)
- [tests/TestCase/Controller/PcmHistoryControllerTest.php](../tests/TestCase/Controller/PcmHistoryControllerTest.php)
- [tests/TestCase/Controller/ReportImportsControllerTest.php](../tests/TestCase/Controller/ReportImportsControllerTest.php)
- [tests/TestCase/Service/OrderDateFiltersTest.php](../tests/TestCase/Service/OrderDateFiltersTest.php)
- [tests/TestCase/Service/PcmOperationalRevisionTest.php](../tests/TestCase/Service/PcmOperationalRevisionTest.php)
- [tests/TestCase/Support/AuthenticatedUserTrait.php](../tests/TestCase/Support/AuthenticatedUserTrait.php)
