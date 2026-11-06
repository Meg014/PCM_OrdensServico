# Não Conformidades — revisão antes da publicação no Ubuntu

Revisão local de 06/11/2026. Nenhum acesso ao servidor de produção, deploy, ativação de cron ou envio real foi executado. O SMTP local foi validado pelo usuário em etapa anterior; nesta revisão, todos os envios são simulados.

Melhorias posteriores de consulta por token, links dos cinco e-mails, responsáveis no formulário e listas fixas estão documentadas em [Consulta por link e cadastro no formulário](nao-conformidades-consulta.md), incluindo os requisitos adicionais de roteamento e proteção dos links no Ubuntu.

## Regras conferidas

| Regra | Resultado da revisão |
|---|---|
| Atribuição ao responsável selecionado | Já implementada; destinatário exclusivo por ação, sem CC/BCC herdados do perfil. |
| Várias ações na mesma NC | Confirmada independência de destinatário, item, prazo e chave do evento. |
| 5 dias, 1 dia e vencimento | Já implementados em dias corridos, pelo campo Quando? (`due_on`). |
| Primeiro atraso e repetição em 7 dias | Já implementados; testados 1 e 8 dias de atraso. Não acumula avisos atrás de um envio pendente/incerto. |
| Conclusão antes do vencimento | Já impede geração de eventos. |
| Conclusão com mensagens pendentes | Já cancela pendentes; status é consultado novamente depois da reclamação da mensagem. |
| Edição durante envio | Reforçada: gravações de ações/responsáveis que afetam o e-mail e o envio compartilham um bloqueio por instalação/banco. Uma conclusão ou troca de responsável não confirma entre a verificação final e o envio SMTP. |
| Reabertura/restauração do prazo | Corrigida: um lembrete já enviado para o mesmo responsável, endereço, tipo e vencimento não é repetido apenas porque o ciclo foi incrementado. Novos vencimentos continuam gerando seus avisos. |
| Troca de responsável/prazo | Já cancela mensagens antigas e registra autoria/antes/depois; o worker também compara destinatário, prazo e ciclos atuais antes do transporte. |
| Cron repetido/concorrente | Chave única e reclamação condicional no banco, `flock` no cron e bloqueio compartilhado com as gravações web. |
| Mensagens acumuladas por uma versão anterior/falha | Reforçada a deduplicação também no despacho; um segundo atraso acumulado é cancelado se outro já foi aceito nos últimos 7 dias corridos. |
| Fuso horário | Explicitamente `America/Sao_Paulo`, independente do fuso padrão do processo. |

O bloqueio novo usa arquivos `tmp/nc-notification-operation-*.lock`. O cron e o PHP web devem usar a mesma instalação, o mesmo `config/.env`, o mesmo diretório `tmp` e permissões compatíveis. Este procedimento contempla um servidor Ubuntu. Em múltiplas instâncias web/containers, seria necessário compartilhar o bloqueio ou adotar coordenação distribuída antes da ativação. Não remova os arquivos de lock durante uma execução.

O bloqueio não mantém uma transação de banco aberta durante SMTP. Gravações podem aguardar até 10 segundos; se não obtiverem o bloqueio, retornam conflito e não confirmam a alteração. Uma mensagem já aceita pelo SMTP não pode ser recolhida. Falhas com aceite incerto continuam exigindo conferência no provedor, sem reenvio automático.

## Arquivos alterados nesta revisão

- `src/Service/NonconformityEmailService.php`: deduplicação após reabertura, verificação adicional de prazo/endereço, coordenação do envio e relatório somente leitura.
- `src/Service/NonconformityService.php`: coordenação entre commits de NC e transporte de e-mail.
- `src/Service/NonconformityOperationMutex.php`: bloqueio compartilhado e reentrante; liberação mesmo após erro.
- `src/Command/NonconformityAlertsCommand.php`: `--dry-run` e resumo de geração/processamento nos logs.
- `deployment/scripts/gerar-cron-nao-conformidades.php`: gera texto de cron com raiz, executável PHP e usuário reais; não instala nem executa a rotina.
- `deployment/cron/pcm-nao-conformidades.example`: indicação de como gerar a configuração com os caminhos reais.
- `tests/TestCase/Service/NonconformityEmailServiceTest.php`: cenários de prazos, repetição, reabertura, destinatários, conclusão e histórico.
- `tests/TestCase/Service/NonconformityOperationMutexTest.php`: reentrância, liberação após erro e disputa com outro processo PHP.
- `tests/TestCase/Command/NonconformityAlertsCommandTest.php`: execução automática sem HTTP, diagnóstico sem efeitos e proteção de `--queue-only`.
- `tests/TestCase/Command/NonconformityCronConfigurationTest.php`: caminhos reais, espaços, usuário de serviço e rejeição de entradas inválidas para o cron.
- `docs/nao-conformidades-producao.md` e referência em `docs/nao-conformidades-smtp.md`.

Não foram alterados templates/layouts de e-mail, URL do botão, SMTP, O.S., importação TOTVS ou outros módulos.

## Migrations

Esta revisão não cria migrations. A instalação precisa das três migrations anteriores do módulo:

1. `20261030000000_CreateNonconformityManagement`.
2. `20261030010000_ExpandNonconformityMeasure`.
3. `20261106000000_AddNonconformityResponsibleEmail`.

As três estão aplicadas no banco local. O estado de produção não foi consultado: verifique depois da atualização. Não execute rollback para apagar tabelas ou registros. Antes de aplicar pendências, preserve o backup pelo procedimento já utilizado pela TI e revise a conexão `default`, que é o banco PCM. Não use a conexão `protheus` para migrations.

## Comandos que o usuário executará depois da atualização

Os documentos anteriores só traziam caminhos de exemplo; o caminho físico do Ubuntu não foi presumido. Na raiz REAL da aplicação atualizada — a pasta que contém `bin/cake.php`, `composer.json`, `config` e `webroot` — execute:

```bash
cd '/RAIZ_REAL_DO_PCM'
PCM_ROOT="$(pwd -P)"
PCM_PHP="$(command -v php)"
PCM_USER=www-data
```

`www-data` é um exemplo: ajuste para o usuário efetivo do PHP-FPM/Apache. Utilize o PHP CLI correspondente à versão da aplicação (PHP 8.2 ou superior compatível com `composer.lock`). Se necessário, defina `PCM_PHP=/usr/bin/php8.3` ou o caminho real escolhido. Em instalação com releases, use a raiz estável/symlink configurada para o site em vez de fixar uma release antiga no cron.

Não sobrescreva `config/.env`, `config/app_local.php`, `logs` ou `tmp` com cópias locais. Preserve a configuração de produção e os arquivos de bloqueio. Publique a revisão completa do módulo pelo procedimento habitual e instale as dependências do lock, sem atualizar versões:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u "$PCM_USER" test -w "$PCM_ROOT/tmp"
sudo -u "$PCM_USER" test -w "$PCM_ROOT/logs"
sudo -u "$PCM_USER" "$PCM_PHP" "$PCM_ROOT/bin/cake.php" migrations status --connection default
```

Garanta que o PHP web carregue o código novo, seguindo o procedimento de atualização/OPcache do servidor. Se a instalação mantém OPcache sem validação de arquivos, faça o reload do serviço PHP-FPM/Apache real previsto nesse procedimento. Não foi presumido um nome de serviço nem executado qualquer reload nesta revisão.

Confira as permissões existentes dos arquivos dentro de `tmp` e `logs`, especialmente os locks, para o usuário web/cron. Se houver migrations de NC pendentes, com backup e credencial de migrations já provisionada para `default`, execute:

```bash
sudo -u "$PCM_USER" "$PCM_PHP" "$PCM_ROOT/bin/cake.php" migrations migrate --connection default --target 20261106000000
sudo -u "$PCM_USER" "$PCM_PHP" "$PCM_ROOT/bin/cake.php" schema_cache clear
sudo -u "$PCM_USER" "$PCM_PHP" "$PCM_ROOT/bin/cake.php" migrations status --connection default
```

**Pule o migrate se as três já estiverem aplicadas.** Não execute esse target se houver migrations posteriores já aplicadas; nesse caso, não há pendência destas três a resolver. Não conceda DDL à conta da aplicação apenas para rodar a rotina; use o processo de migrations existente e restaure a conta normal depois.

## Configuração SMTP preservada

No `config/.env` de produção, mantenha os demais valores existentes e confira:

```dotenv
NC_MAIL_ENABLED=false
NC_MAIL_PROVIDER=smtp
NC_MAIL_PROFILE=nonconformity
NC_MAIL_FROM=qualidade@biolasa.com.br
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USER=qualidade@biolasa.com.br
MAIL_PASSWORD="credencial-aprovada-preenchida-somente-no-servidor"
MAIL_ENCRYPTION=tls
APP_FULL_BASE_URL=https://pcm.biolasa.com.br
```

O arquivo carregado é `config/.env`; o `.env` da raiz não é utilizado. Não copie senha no comando, no cron ou em logs. O botão dos cinco modelos continua fixo em `https://pcm.biolasa.com.br/pcm/nao-conformidades`.

Para validar configuração pública, data e contadores **sem gerar eventos nem enviar**:

```bash
sudo -u "$PCM_USER" "$PCM_PHP" "$PCM_ROOT/bin/cake.php" nonconformity_alerts --dry-run
sudo -u "$PCM_USER" "$PCM_PHP" "$PCM_ROOT/bin/cake.php" nonconformity_mail_test --event assigned
```

O segundo comando só cria prévia HTML/texto. Não acrescente `--send` para responsáveis. `--queue-only` também não envia, mas gera registros na fila; use apenas quando quiser preparar esses eventos conscientemente.

## Preparar o cron, sem ativar

O gerador abaixo verifica a raiz e os executáveis fornecidos e escreve somente a configuração em stdout. Não carrega `.env`, não instala cron e não chama o worker:

```bash
"$PCM_PHP" "$PCM_ROOT/deployment/scripts/gerar-cron-nao-conformidades.php" \
  --root="$PCM_ROOT" --php="$PCM_PHP" --user="$PCM_USER" \
  > /tmp/pcm-nao-conformidades.cron
cat /tmp/pcm-nao-conformidades.cron
```

Revise o arquivo e guarde-o para autorização. Ele contém `SHELL`, `PATH` e uma linha a cada cinco minutos, com `flock -n`, o executável real do PHP e redirecionamento para `logs/nc-email-cron.log`. Se `flock` estiver em outro local, informe `--flock=/caminho/real/flock`. Caminhos com espaços são delimitados corretamente; caminhos com `%` ou quebras de linha são rejeitados.

**Pare aqui enquanto não houver autorização para ativação.** Nenhum destes passos preparatórios depende de alguém abrir o PCM.

## Ativação futura — somente depois da autorização do usuário

Revise os eventos pendentes e os estados `sending`, `uncertain` e `failed` antes de habilitar. Confira se já existe outro agendador de NC para evitar duas configurações. O usuário decidirá quando definir `NC_MAIL_ENABLED=true` em `config/.env` e instalar o arquivo revisado:

```bash
sudo install -o root -g root -m 0644 /tmp/pcm-nao-conformidades.cron /etc/cron.d/pcm-nao-conformidades
sudo systemctl is-active cron
```

A configuração é para `/etc/cron.d/` e inclui a coluna do usuário. Não cole a mesma linha em `crontab -e` sem retirar essa coluna. O comando final executado pelo cron é `php bin/cake.php nonconformity_alerts --limit 100`, na raiz correta, com exclusão mútua por `flock`.

## Confirmar processamento e verificar erros

Depois da ativação autorizada:

```bash
sudo -u "$PCM_USER" "$PCM_PHP" "$PCM_ROOT/bin/cake.php" nonconformity_alerts --dry-run
tail -n 100 "$PCM_ROOT/logs/nc-email-cron.log"
tail -n 100 "$PCM_ROOT/logs/cli-debug.log"
tail -n 100 "$PCM_ROOT/logs/cli-error.log"
```

O log registra `NC rotina: alertas=... agendados=...`, contadores `sent`, `failed`, `cancelled` e o ID das mensagens aceitas pelo provedor. A tela Responsáveis → Fila de e-mails mostra destinatário por ação, tipo, agendamento, envio, status e tentativas. Compare o relatório antes/depois e o rastreamento do Microsoft 365; aceite SMTP não prova entrega na caixa final.

Falhas de conexão/transitórias têm até cinco tentativas com espera progressiva. Autenticação rejeitada interrompe o lote e registra erro seguro. `uncertain` e `sending` não são reenviados automaticamente: confira o aceite no provedor antes de liberar um registro específico. Não reative a fila em massa.

O diagnóstico novo não revela senha, usuário de banco, tokens ou conteúdo dos e-mails. Os testes automatizados continuam substituindo todos os transportes por Debug/Test e usam banco isolado.

## Resultado dos testes

- **347 testes PHP, 6.222 asserções, todos aprovados**. Relatórios: `tmp/nc-production-final.log` e `tmp/nc-production-final.xml`.
- A suíte completa inclui os **69 testes específicos de Não Conformidades**, cobrindo os cenários solicitados, concorrência, CLI, destinatários, falhas e deduplicação. A execução específica registrou 448 asserções em `tmp/nc-production-focused.log`.
- **30 testes JavaScript aprovados**. Relatório: `tmp/nc-production-js.log`.
- Sintaxe PHP dos arquivos desta revisão e `git diff --check` aprovados.
- Teste com **dois processos PHP locais** confirmou exclusão mútua e liberação após erro; nenhum SMTP real foi utilizado.
- O comando real `nonconformity_alerts --dry-run` foi executado localmente: informou Brasília, SMTP, envio automático `false` e 1 mensagem pendente. Essa mensagem não foi processada ou alterada pela verificação.
- As 13 migrations locais estão `up`, incluindo as três do módulo. Não foi aplicada migration nesta revisão e o banco de produção não foi consultado.

Os testes de aplicação usaram SQLite isolado. Não houve execução em servidor Ubuntu nem teste de cron instalado; o gerador POSIX foi validado por testes de conteúdo, caminhos, usuário e entradas inválidas. A verificação de permissões e dos caminhos reais será feita pelo usuário com os comandos deste roteiro após atualizar o servidor.
