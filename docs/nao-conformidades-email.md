# Responsáveis e e-mails de Não Conformidades

**Configuração atual:** consulte [SMTP Microsoft 365 / Biolasa](nao-conformidades-smtp.md). A integração usa `smtp.office365.com:587` com STARTTLS e `MAIL_USER=qualidade@biolasa.com.br`. E-mail é obrigatório nos novos cadastros e edições; cadastros antigos são preservados e precisam de endereço válido antes de novas atribuições. As orientações abaixo sobre e-mail opcional e SMTP genérico descrevem a entrega anterior.

## Implantação controlada

Nenhuma migration ou configuração de produção é executada por esta implementação. Faça backup do banco PCM e valide primeiro em homologação, usando a mesma versão de PHP e o mesmo banco da produção. A integração TOTVS não é utilizada para gravações.

Migration nova: `config/Migrations/20261106000000_AddNonconformityResponsibleEmail.php`.
Cria `nonconformity_responsibles`, `nonconformity_email_queue` e os campos opcionais `responsible_id` e `assignment_cycle` nas ações. Não apaga ou associa nomes antigos. A aplicação deve ser atualizada junto com a migration; execute em janela de manutenção para não atender requisições com esquema incompleto. Não reverta a migration depois de cadastrar responsáveis ou enviar e-mails sem um plano de preservação dessas tabelas.

Em homologação, depois do backup e da conferência da conexão PCM:

```bash
sudo -u www-data php bin/cake.php migrations status
sudo -u www-data php bin/cake.php migrations migrate
sudo -u www-data php bin/cake.php schema_cache clear
sudo -u www-data php bin/cake.php nonconformity_alerts --queue-only
```

Em produção, repetir apenas após validar o resultado e as configurações. Os comandos de teste usam banco SQLite separado; nunca apontar `DATABASE_TEST_URL` para produção.

## SMTP

O projeto possuía o transporte padrão CakePHP (PHP mail), sem configuração SMTP explícita no arquivo versionado. As configurações locais existentes são preservadas. Para reutilizar um perfil SMTP já configurado, use `NC_MAIL_PROFILE=default` (ou o nome do perfil adequado). Confira remetente e transporte desse perfil em `config/app_local.php`.

Para usar o novo perfil, adicione em `config/.env` (arquivo não versionado), com permissão de leitura restrita ao serviço:

```dotenv
NC_MAIL_ENABLED=false
NC_MAIL_PROFILE=nonconformity
MAIL_HOST=smtp.suaempresa.com.br
MAIL_PORT=587
MAIL_USERNAME=conta-smtp
MAIL_PASSWORD="definir-no-servidor"
MAIL_FROM_ADDRESS=pcm@suaempresa.com.br
MAIL_FROM_NAME="PCM"
MAIL_ENCRYPTION=tls
APP_FULL_BASE_URL=https://pcm.suaempresa.com.br
```

`tls` usa STARTTLS, `ssl` usa TLS implícito (normalmente porta 465); `none` desativa criptografia, apenas para um relay interno apropriado. Os nomes e portas devem seguir o provedor. Não desabilitar verificação de certificado. Sem `MAIL_HOST`, o perfil `nonconformity` usa o transporte padrão. Credenciais nunca devem ser incluídas no Git. O bootstrap existente carrega `config/.env` para web e CLI.

Valide DNS, firewall, TLS, credenciais e autorização do remetente em homologação. Cadastre um responsável com endereço de teste autorizado e crie uma ação para ele. Confira a fila em **Não Conformidades → Responsáveis**. Habilite `NC_MAIL_ENABLED=true` e execute o comando somente quando estiver pronto para enviar as mensagens pendentes. Verifique recebimento, HTML, versão de texto e link autenticado. Depois valide com o administrador do serviço SMTP a configuração de SPF/DKIM/DMARC da empresa.

Desativar `NC_MAIL_ENABLED` impede o worker de enviar; gravações continuam gerando a fila. Antes de habilitar em produção, revise as mensagens pendentes. Ações concluídas, destinatários inativos, endereços alterados e ciclos antigos são cancelados/revalidados. Avisos de 5/1/0 dias que perderam a data são cancelados no processamento.

## Cadastro e associação assistida

ADMIN e o usuário designado para Gerenciar Não Conformidades podem administrar responsáveis sem criar contas de login. Nome obrigatório; e-mail e setor opcionais. Nome normalizado impede duplicidade por caixa, acentos e espaços. Inativação mantém histórico e vínculos.

O seletor de Quem? pesquisa por nome e permite escolher cadastros ativos. Um responsável inativo já vinculado pode permanecer no registro, mas não recebe e-mail. Outros usuários veem os nomes associados e não têm acesso à administração; TV permanece bloqueado.

Na área **Associação assistida**, abra cada ação antiga, confira a identidade e selecione o responsável correto. Nenhum vínculo é inferido pelo nome. `responsible_name` conserva o texto importado; o nome exibido vem do cadastro quando há vínculo. Trocas são registradas na auditoria da ação com valores anteriores e posteriores. Os 30 últimos eventos de cadastro são mostrados na administração; o histórico integral permanece na tabela de auditoria.

## Regras

- Nova ação ou troca de responsável: evento `assigned` na mesma transação da gravação; envio somente por worker após commit.
- Datas no fuso `America/Sao_Paulo`, em dias corridos. Alertas em exatamente 5, 1 e 0 dias. A tabela destaca também 4, 3 e 2 dias.
- Atraso: primeiro evento no primeiro dia; se o job não executou, gera no próximo processamento. Novos eventos têm intervalo mínimo de 7 dias, considerando também o último envio confirmado.
- A atribuição já comunica o prazo e suprime outro aviso no mesmo dia. Ações concluídas não geram nem enviam novos lembretes.
- Troca de prazo/reabertura incrementa o ciclo; mensagens antigas pendentes são canceladas. Troca de responsável incrementa o ciclo da atribuição e agenda nova mensagem. Não há reenvio para simples edição de observações.
- O sino continua restrito ao titular da permissão de gerenciamento. Avisos antigos de 3 dias permanecem como histórico e novos avisos usam 5 dias.
- O link não autentica o destinatário nem concede permissões. Responsáveis sem login podem receber a informação no próprio e-mail.

## Cron no Ubuntu

Use o mesmo ambiente/configuração da aplicação e garanta escrita pelo usuário do serviço em `logs` e `tmp`. O comando é descoberto automaticamente pelo CakePHP:

```bash
sudo -u www-data php /var/www/PCM_OrdensServico/bin/cake.php nonconformity_alerts --queue-only
sudo -u www-data php /var/www/PCM_OrdensServico/bin/cake.php nonconformity_alerts --limit 100
```

Exemplo em `/etc/cron.d/pcm-nao-conformidades` (ajuste a pasta real):

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * www-data cd /var/www/PCM_OrdensServico && /usr/bin/flock -n /var/www/PCM_OrdensServico/tmp/nc-email-worker.lock /usr/bin/php bin/cake.php nonconformity_alerts --limit 100 >> /var/www/PCM_OrdensServico/logs/nc-email-cron.log 2>&1
```

A frequência a cada 5 minutos permite tratar atribuições e retentativas. O cálculo das datas é explicitamente de São Paulo e independe do fuso do cron. Configure rotação do log. Sucessos e falhas também usam os logs CakePHP (`cli-debug`/`cli-error`, conforme bootstrap). Não é necessário alguém abrir a página.

## Idempotência, retentativas e operação

A chave única inclui ação, ciclo de prazo, ciclo de atribuição e tipo de evento; atrasos incluem a data. Produtores compartilham o bloqueio transacional de NC. Workers disputam cada registro por UPDATE condicional (`pending` → `sending`), sem transação aberta durante SMTP. Uma execução concorrente não reclama o mesmo registro. `flock` reduz sobreposição no servidor; a proteção por banco também cobre workers independentes.

A fila registra destinatário, NC/ação, tipo, datas, status, tentativas e erro. Falhas claramente anteriores à conexão geram até 5 tentativas, com espera de 5, 10, 20 e 40 minutos. Falhas ambíguas ficam em `uncertain`; quedas do processo após reclamar um registro deixam `sending`. Não há recuperação automática desses estados: SMTP pode aceitar uma mensagem e a conexão cair antes da resposta. Nenhum sistema SMTP comum garante exatamente uma entrega nessa situação. O Message-ID estável ajuda a conferência, mas não garante deduplicação pelo destinatário.

Na tela de responsáveis, confira a fila e correlacione o ID/Message-ID com os logs do SMTP. Mensagens já em trânsito podem terminar após uma edição/conclusão; as seguintes são revalidadas antes do envio. Para estados `sending`/`uncertain`, interrompa o worker antes da reconciliação e confirme no provedor se houve aceite. Se entregue, marque `sent` com a data confirmada; se comprovadamente não enviada, retorne **somente o ID verificado** para `pending`, com `available_at` atual e tentativas menores que 5. Se não for possível determinar, mantenha `uncertain`. Faça essa manutenção controlada no banco PCM, com registro operacional; nunca reative em massa. `failed` indica limite de retentativas atingido. Corrija o SMTP e confira a causa antes de liberar nova tentativa.

## Validação

```bash
DATABASE_TEST_URL=sqlite://./tmp/nc-email-tests.sqlite php vendor/bin/phpunit
node --test tests/js/*.test.cjs
```

O bootstrap de testes substitui transportes por Debug/Test; não envia e-mails reais. Testes cobrem cadastro, normalização, legados, auditoria/rollback, atribuição, prazos, conclusão, troca, retries, reclamação concorrente e template HTML/texto. Valide também visualmente desktop/tablet/celular e, antes da implantação, execute os testes de integração no mesmo tipo de banco usado em produção.

## Resultado da validação em 06/11/2026

- Suíte PHP completa em SQLite isolado: **311 testes, 5.999 asserções, aprovados** (`tmp/nc-email-phpunit.log` e `tmp/nc-email-phpunit.xml`).
- Suíte JavaScript: **29 testes aprovados** (`tmp/nc-email-js-tests.log`).
- Chrome headless: planilha, formulário e administração de responsáveis em **1440, 768 e 390 pixels**, sem transbordamento da página. Contêiner desktop de 1368 px em viewport de 1440 px (95%); rolagem própria da tabela. Capturas e medidas em `tmp/nc-visual/`.
- Interação no navegador: pesquisa por nome, inclusão dinâmica do segundo item e envio do ID selecionado em `actions[1][responsible_id]` pelo FormData (`tmp/nc-visual/interactions.json`). Fluxos de gravação e permissões validados também pelos testes HTTP de integração.
- Sintaxe PHP dos arquivos alterados, `git diff --check` e descoberta/ajuda do comando CLI aprovados.

Nenhum e-mail real foi enviado. SMTP externo, entrega em clientes de e-mail reais e migrations no banco de produção permanecem para validação de implantação. Os testes desta entrega não executaram um servidor PostgreSQL/MySQL; a migration foi executada em SQLite e auditada quanto à portabilidade. O PHP local emite aviso preexistente de extensão OpenSSL carregada duas vezes, sem impedir os testes.

## Arquivos desta entrega

- `.env.example`
- `config/app.php`
- `config/Migrations/20261106000000_AddNonconformityResponsibleEmail.php`
- `config/routes.php`
- `docs/nao-conformidades-email.md`
- `docs/nao-conformidades.md`
- `src/Command/NonconformityAlertsCommand.php`
- `src/Controller/NonconformitiesController.php`
- `src/Model/Table/NonconformityEmailQueueTable.php`
- `src/Model/Table/NonconformityResponsiblesTable.php`
- `src/Service/NonconformityEmailService.php`
- `src/Service/NonconformityService.php`
- `templates/element/nonconformity_action_fields.php`
- `templates/element/nonconformity_bell.php`
- `templates/element/nonconformity_responsible_select.php`
- `templates/element/nonconformity_table.php`
- `templates/email/html/nonconformity.php`
- `templates/email/text/nonconformity.php`
- `templates/layout/default.php`
- `templates/layout/email/html/nonconformity.php`
- `templates/layout/email/text/nonconformity.php`
- `templates/Nonconformities/action.php`
- `templates/Nonconformities/index.php`
- `templates/Nonconformities/responsibles.php`
- `templates/Nonconformities/view.php`
- `tests/bootstrap.php`
- `tests/js/pcm-nonconformities.test.cjs`
- `tests/TestCase/Controller/NonconformitiesControllerTest.php`
- `tests/TestCase/Migrations/PostgresPortabilityTest.php`
- `tests/TestCase/Service/NonconformityEmailServiceTest.php`
- `tests/TestCase/Service/NonconformityServiceTest.php`
- `webroot/css/pcm-nonconformities.css`
- `webroot/js/pcm-nonconformities.js`
