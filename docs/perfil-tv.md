# Perfil TV

O perfil TV permite somente GET `/pcm/apresentacao`, GET `/pcm/apresentacao/data`
e POST `/logout`, além do login. As demais rotas, inclusive aliases, retornam 403.
ADMIN e USUARIO mantêm a política normal de sessão.

## Instalação e uso

1. Aplique as migrations no ambiente de implantação: `php bin/cake.php migrations migrate`.
   A migration nova é `20260921120000_CreateTvDevices.php`; depende da migration
   existente `20260918140000_CreateUsers.php`. Cria somente `tv_devices`, sem
   modificar tabelas operacionais. Foi validada no banco de testes e aplicada no
   ambiente local em 21/09/2026, ao iniciar o sistema.
2. Entre como ADMIN, abra **Usuários → Cadastrar usuário**, selecione **TV**,
   informe nome, e-mail e senha (12 a 72 caracteres) e mantenha o usuário ativo.
   Setor é opcional e não amplia permissões.
3. Na máquina dedicada, abra `/login` e autentique esse usuário uma vez.
   O navegador será redirecionado para `/pcm/apresentacao`.
4. Configure a página inicial/abertura automática do navegador para
   `/pcm/apresentacao`. Use um perfil de navegador persistente, que conserve cookies
   ao fechar. A autenticação não configura a inicialização do navegador ou do Windows.

## Persistência e manutenção

O cookie `pcm_tv_device` contém um token aleatório de 256 bits. O banco armazena
somente SHA-256 desse token, o usuário e a expiração. Cookie HttpOnly, SameSite=Lax,
path=/ e Secure em requisições HTTPS. Use HTTPS na implantação; em um proxy TLS,
configure corretamente o esquema HTTPS percebido pela aplicação.

A validade é de 90 dias, renovada durante o uso (atualização no banco no máximo
diária). A atualização automática da apresentação mantém essa validade. Após
90 dias sem renovação, cookies apagados ou revogação, é necessário novo login.
Nenhuma senha é persistida no navegador ou incluída no código.

Em **Usuários**, o ADMIN pode escolher **Revogar dispositivos TV**, invalidando
todos os dispositivos da conta na próxima requisição, mesmo com sessão PHP ativa.
Editar a conta para desativá-la, mudar o perfil ou redefinir a senha também revoga
os tokens; reativar a conta não restaura tokens anteriores. O **Logout** discreto
na apresentação revoga o dispositivo atual. Não há botão de saída para o sistema,
nem navegação por Escape para outras áreas.

## Arquivos desta alteração

- `config/Migrations/20260921120000_CreateTvDevices.php`
- `config/routes.php`
- `src/Application.php`
- `src/Middleware/TvDeviceMiddleware.php`
- `src/Service/TvDeviceService.php`
- `src/Model/Table/TvDevicesTable.php`
- `src/Model/Table/UsersTable.php`
- `src/Controller/AppController.php`
- `src/Controller/AuthController.php`
- `src/Controller/UsersController.php`
- `templates/Users/form.php`
- `templates/Users/index.php`
- `templates/layout/default.php`
- `templates/Pcm/presentation.php`
- `webroot/js/pcm-presentation.js`
- `tests/TestCase/Controller/TvAuthenticationTest.php`
- `docs/perfil-tv.md`

## Verificação

`php vendor/phpunit/phpunit/phpunit tests/TestCase/Controller/TvAuthenticationTest.php tests/TestCase/Controller/AuthUsersControllerTest.php tests/TestCase/Controller/PcmControllerTest.php`

`node tests/JavaScript/pcm-presentation.test.cjs`

Os testes usam o banco de testes e fixtures sintéticas, sem executar importações
ou modificar dados operacionais do ambiente.
