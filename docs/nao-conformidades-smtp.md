# Não Conformidades — SMTP Microsoft 365 / Biolasa

Para a revisão mais recente das regras, diagnóstico `--dry-run` e geração do cron com a raiz/usuário/PHP reais, consulte [Preparação de produção](nao-conformidades-producao.md). A ativação continua dependendo de autorização do usuário.

## Estado da entrega

Integração local implementada com o transporte SMTP do CakePHP, sem PHPMailer ou nova dependência. A TI confirmou SMTP para `qualidade@biolasa.com.br`. A implementação usa AUTH LOGIN somente após STARTTLS e não troca de autenticação automaticamente. Graph/OAuth não são necessários para esta configuração confirmada.

**Nenhum envio real foi executado nesta entrega. Nenhum cron foi instalado. As notificações automáticas permanecem desabilitadas.** A entrega real depende da credencial e da validação controlada com a TI.

Não há nova migration nesta etapa. A migration anterior `20261106000000_AddNonconformityResponsibleEmail` continua necessária em instalações que ainda não a aplicaram. O e-mail permanece nullable no banco para preservar os cadastros antigos; a obrigatoriedade é validada nos novos cadastros e nas edições, no navegador e no serviço. Nomes e vínculos antigos permanecem intactos. Responsáveis antigos sem e-mail válido não podem receber novas ações; um vínculo já existente pode ser mantido ao atualizar andamento ou conclusão.

Não foram alterados registros de NC, responsáveis, usuários, O.S. ou TOTVS na validação desta etapa. Os testes automatizados utilizam banco separado, transportes simulados e sockets falsos.

## Variáveis no servidor

O projeto carrega **`config/.env`**, não o `.env` da raiz. CLI, Apache e cron leem esse mesmo arquivo. Preserve as demais configurações de banco e aplicação. Configure:

```dotenv
NC_MAIL_ENABLED=false
NC_MAIL_PROVIDER=smtp
NC_MAIL_PROFILE=nonconformity
NC_MAIL_FROM=qualidade@biolasa.com.br
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USER=qualidade@biolasa.com.br
MAIL_PASSWORD="preencher-diretamente-no-servidor"
MAIL_ENCRYPTION=tls
APP_FULL_BASE_URL=https://pcm.suaempresa.com.br
```

Substitua a URL pelo endereço real que os usuários acessam, incluindo eventual subdiretório da aplicação. A senha deve ser a credencial SMTP aprovada pela TI, sem presumir que qualquer senha de login do Outlook seja aceita. O novo nome é **MAIL_USER**, não MAIL_USERNAME. O remetente é fixado também no serviço: alterações de formulário ou de perfil não podem trocar a caixa de envio.

Opcionalmente, limite o comando de teste a um endereço previamente aprovado:

```dotenv
NC_MAIL_TEST_RECIPIENT=destinatario-de-teste@biolasa.com.br
```

`config/.env` já é ignorado pelo Git. Nunca anexar esse arquivo a chamados, imprimir senha ou registrar uma sessão SMTP detalhada. No Ubuntu, defina dono/grupo adequados e modo `640`, permitindo leitura apenas pelo responsável e pelo serviço. Garanta escrita do usuário `www-data` em `tmp` e `logs`.

Para simulação local, `NC_MAIL_PROVIDER=debug` dispensa credenciais e rede. Nos testes automatizados, todos os transportes são substituídos por Debug/Test. Mantenha `NC_MAIL_ENABLED=false` durante a validação, inclusive o teste SMTP manual: o comando de teste é independente do worker.

## Conferência com a TI

No Microsoft 365, confira **Usuários ativos → Qualidade → Email → Gerenciar aplicativos de email → SMTP autenticado**. No Exchange Online PowerShell, as consultas são:

```powershell
Get-CASMailbox -Identity qualidade@biolasa.com.br | Format-List SmtpClientAuthenticationDisabled
Get-TransportConfig | Format-List SmtpClientAuthenticationDisabled
```

Na caixa, `False` habilita, `True` bloqueia e vazio herda o tenant. Políticas de autenticação e Security Defaults também podem impedir SMTP AUTH. A TI deve validar as políticas efetivas; este projeto não altera o tenant. Se houver exigência de OAuth ou rejeição do método autorizado, interrompa a ativação e defina o método com a TI antes de implementar outra autenticação. [Referência Microsoft sobre SMTP AUTH](https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission).

A Microsoft anunciou mudança do padrão de SMTP AUTH Basic ao final de dezembro de 2026; a TI deve acompanhar a disponibilidade no tenant. [Cronograma oficial do Exchange](https://techcommunity.microsoft.com/blog/exchange/updated-exchange-online-smtp-auth-basic-authentication-deprecation-timeline/4489835).

## Testar um único destinatário

Na raiz do projeto, primeiro gere uma prévia **sem conexão de rede**:

```bash
php bin/cake.php nonconformity_mail_test --event assigned
```

O comando cria `tmp/nc-email-preview-assigned.html` e `.html.txt`. Estão disponíveis os cinco modelos: `assigned`, `five_days`, `one_day`, `today` e `overdue`. O HTML usa o cabeçalho Biolasa, cores PCM/Qualidade, tabela compatível com Outlook, botão de consulta e todos os dados da ação; cada modelo tem título, texto e alerta próprios. A versão texto contém as mesmas informações essenciais. As mensagens não incluem senhas ou tokens. O link não concede acesso ao sistema.

Após configurar a credencial e autorizar um destinatário de teste, execute manualmente:

```bash
php bin/cake.php nonconformity_mail_test --to destinatario-de-teste@biolasa.com.br --event assigned --send
```

No Windows local:

```powershell
php bin/cake.php nonconformity_mail_test --to destinatario-de-teste@biolasa.com.br --event assigned --send
```

Este comando envia **uma única mensagem**, com `[TESTE CONTROLADO]` no assunto e dados ilustrativos. Não lê a lista de responsáveis, não gera atribuições, não processa a fila e não habilita notificações automáticas. Sem `--send`, apenas gera a prévia. Sem `--to`, o envio é bloqueado. Para um segundo modelo, execute outro comando manualmente.

Confirme recebimento, remetente, HTML/texto, prazo, link e rastreamento com a TI. Aceite SMTP não garante entrega na caixa final; verifique spam, quarentena e Message Trace no Exchange. Se o resultado do comando for incerto, confira o rastreamento antes de repetir o envio.

## Worker e cron Ubuntu

O comando existente permanece responsável pelos alertas internos, geração dos lembretes e processamento da fila:

```bash
cd /var/www/PCM_OrdensServico
sudo -u www-data /usr/bin/php bin/cake.php nonconformity_alerts --queue-only
sudo -u www-data /usr/bin/php bin/cake.php nonconformity_alerts --limit 100
```

O primeiro não envia. O segundo só envia quando `NC_MAIL_ENABLED=true`. **Não altere esse valor nem instale o cron antes da aprovação do teste controlado.** A fila pode conter mensagens pendentes; revise-as antes de ativar.

Após a aprovação, use `/etc/cron.d/pcm-nao-conformidades`, com a instalação em `/var/www/PCM_OrdensServico`:

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * www-data cd /var/www/PCM_OrdensServico && /usr/bin/flock -n /var/www/PCM_OrdensServico/tmp/nc-email-worker.lock /usr/bin/php bin/cake.php nonconformity_alerts --limit 100 >> /var/www/PCM_OrdensServico/logs/nc-email-cron.log 2>&1
```

Esse arquivo já está preparado em `deployment/cron/pcm-nao-conformidades.example`. Se a aplicação estiver em outra pasta, ajuste os três caminhos absolutos. Em `crontab -u www-data -e`, retire a coluna `www-data`; ela só pertence a `/etc/cron.d/`. Configure rotação do arquivo de log. Não execute o worker simultaneamente com o agendador antigo do mesmo módulo sem necessidade.

As datas são calculadas explicitamente em `America/Sao_Paulo`. Atribuições entram na fila na transação de gravação, com envio depois do commit. Lembretes ocorrem em 5, 1 e 0 dias; o primeiro atraso ocorre no primeiro processamento após o vencimento, e os próximos respeitam pelo menos 7 dias corridos. Não acumula vários atrasos atrás de um lembrete ainda pendente ou incerto. Ações concluídas, prazos antigos e responsáveis anteriores deixam de receber lembretes futuros. Uma mensagem já aceita pelo servidor não pode ser retirada por uma edição posterior.

## Histórico, falhas e duplicidades

Consulte **Não Conformidades → Responsáveis → Fila de e-mails**: destinatário, NC/ação, evento, agendamento, envio, status, tentativas e erro. O worker usa a chave única de evento e reclama cada registro com UPDATE condicional. O `flock` evita sobreposição local; a reclamação no banco protege também processos independentes.

- **Conexão/STARTTLS:** verificar resolução DNS, saída TCP 587, firewall, certificados, extensão OpenSSL e relógio do servidor. As tentativas transitórias usam backoff de 5, 10, 20 e 40 minutos, até 5 tentativas.
- **Autenticação:** registro `failed` com mensagem segura. O lote é interrompido após falha de conexão, configuração ou autenticação, preservando os demais itens pendentes e evitando tentar a mesma credencial em toda a fila. Conferir credencial, SMTP AUTH, bloqueio da conta e políticas com a TI. Não repetir indefinidamente a mesma credencial rejeitada nem desabilitar controles do tenant automaticamente.
- **Rejeição SMTP explícita:** respostas 4xx permitem retentativa controlada; 5xx indicam falha definitiva. O serviço não armazena a resposta bruta, senha ou comandos AUTH nos logs.
- **Queda durante DATA:** `uncertain`; não repete automaticamente, pois o servidor pode ter aceitado a mensagem. Um processo interrompido pode deixar `sending`. Correlacione o Message-ID estável com o Exchange antes de liberar novamente um ID específico.
- **Falha de QUIT após DATA aceito:** contabiliza o aceite e impede um segundo envio da mesma mensagem.

Logs CakePHP: `logs/cli-error.log` e `logs/cli-debug.log`, conforme a configuração do projeto. Cron: `logs/nc-email-cron.log`. Para acompanhar:

```bash
tail -n 100 /var/www/PCM_OrdensServico/logs/cli-error.log
tail -f /var/www/PCM_OrdensServico/logs/nc-email-cron.log
```

Um código 535 ou mensagem de autenticação rejeitada exige conferência com a TI. Uma senha comum ou uma senha de aplicativo não resolve necessariamente um bloqueio de política. Nenhum método alternativo é ativado por este código.

## Arquivos principais desta etapa

- Configuração: `config/app.php`, `.env.example`.
- Transporte/erros: `src/Mailer/Transport/NonconformitySmtpTransport.php`, `src/Mailer/Exception/NonconformityDeliveryException.php`.
- Fila/modelos: `src/Service/NonconformityEmailService.php`.
- Validação de responsáveis: `src/Service/NonconformityService.php`, `templates/Nonconformities/responsibles.php`, `templates/element/nonconformity_responsible_select.php`, `webroot/js/pcm-nonconformities.js`.
- Template HTML e texto: `templates/email/{html,text}/nonconformity.php`, `templates/layout/email/{html,text}/nonconformity.php`.
- Teste manual: `src/Command/NonconformityMailTestCommand.php`.
- Agendamento: `deployment/cron/pcm-nao-conformidades.example`; reaproveita `src/Command/NonconformityAlertsCommand.php`.
- Testes: `tests/TestCase/Mailer/Transport/NonconformitySmtpTransportTest.php`, `tests/TestCase/Command/NonconformityMailTestCommandTest.php`, `tests/TestCase/Service/NonconformityEmailServiceTest.php`, `tests/TestCase/Controller/NonconformitiesControllerTest.php`, `tests/js/pcm-nonconformities.test.cjs`, `tests/bootstrap.php`.
- Documentação: `docs/nao-conformidades-smtp.md` e indicação da configuração atual em `docs/nao-conformidades-email.md`.

## Validação local concluída em 06/11/2026

- **330 testes PHP e 6.125 asserções aprovados**. Relatórios: `tmp/nc-smtp-phpunit-complete.log` e `tmp/nc-smtp-phpunit-complete.xml`.
- **30 testes JavaScript aprovados**, incluindo validação obrigatória do e-mail. Relatório: `tmp/nc-smtp-js-tests.log`.
- Oito testes específicos do transporte usam sockets falsos e verificam STARTTLS antes de AUTH, autenticação rejeitada, falha de conexão, rejeição temporária, timeout após DATA, QUIT após aceite, remetente indevido e senha ausente.
- Testes de serviço e CLI validam fila, duplicidade, cancelamento, troca de prazo/responsável, dados legados, permissões, cinco modelos HTML/texto, ausência de CC/BCC herdados e exatamente um destinatário no teste controlado.
- Chrome headless: os cinco modelos em 640 e 390 pixels, sem transbordamento horizontal; formulário bloqueia e-mail ausente/inválido e aceita válido. Evidências em `tmp/nc-visual/email-validation.json` e `tmp/nc-visual/email-*.png`.
- Os cinco previews foram gerados pelo comando real, sem `--send`, sem credenciais e sem rede SMTP. Exemplo: `tmp/nc-email-preview-assigned.html` e `tmp/nc-email-preview-assigned.html.txt`.
- Sintaxe PHP e `git diff --check` aprovados. A configuração efetiva local confirmou provedor `smtp`, host `smtp.office365.com`, porta 587, STARTTLS ativo, remetente oficial e **envio automático desativado**. Senha não configurada; seu valor não foi exibido.

Produção, credenciais e entrega externa permanecem sob validação da TI. Não houve envio real nem alteração de dados de produção/TOTVS. Após corrigir uma falha definitiva, revise o ID correspondente antes de liberar nova tentativa na fila; nunca reative registros em massa sem conferir o aceite no provedor.
