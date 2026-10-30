# Gestão de Não Conformidades — implementação

Referência: `docs/plano-nao-conformidades.md`, complementado pelas regras aprovadas de status Não iniciada, Em andamento e Concluída. Nenhuma importação da planilha foi implementada ou executada.

## Etapas e arquivos

1. Infraestrutura: `config/Migrations/20261030000000_CreateNonconformityManagement.php`, cinco modelos em `src/Model/Table/Nonconform*Table.php`, `src/Service/NonconformityService.php`. Cinco tabelas no banco existente do PCM; sem escrita no TOTVS. Dados e auditoria são gravados juntos em transação.
2. Permissão: `src/Controller/UsersController.php`, `src/Model/Table/UsersTable.php`, `templates/Users/form.php`, `index.php`, `nonconformity_manager.php` e rotas explícitas em `config/routes.php`. O cadastro de usuários possui acesso à gestão da permissão. Uma linha fixa de designação é a fonte da permissão, não o perfil ADMIN nem um booleano distribuído entre contas.
3. Módulo: `src/Controller/NonconformitiesController.php`, `templates/Nonconformities/{index,form,view,action}.php`, `webroot/css/pcm-nonconformities.css`. Consulta, filtros GET, indicadores, NC com várias ações, conclusão, eficácia e histórico paginado. Número de NC e item podem ficar vazios; não existe numeração automática. Número preenchido é único dentro de unidade + origem; item preenchido é único dentro da NC.
4. Alertas: `src/Controller/NonconformityNotificationsController.php`, `src/Command/NonconformityAlertsCommand.php`, `templates/element/nonconformity_bell.php`, `webroot/js/pcm-nonconformity-notifications.js`, integração em `templates/layout/default.php`. Sino com contador e atualização a cada minuto, sem interromper uma lista aberta. O componente opcional informa indisponibilidade sem derrubar as páginas de O.S.

Testes: `tests/TestCase/Service/NonconformityServiceTest.php`, `tests/TestCase/Controller/NonconformitiesControllerTest.php`, `tests/js/pcm-nonconformity-notifications.test.cjs`. O inventário de migrations em `PostgresPortabilityTest.php` foi atualizado. `tests/bootstrap.php` repõe a linha única após a limpeza efetuada pelo migrador do banco de teste.

`config/Migrations/schema-dump-default.lock` foi atualizado automaticamente pelo comando de migrations local para refletir as cinco novas tabelas. Não houve edição de `.env`, configuração de autenticação, SQL TOTVS ou dados de O.S.

## Acesso e auditoria

- ADMIN consulta e edita; não recebe alertas sem designação explícita.
- USUARIO consulta todos os registros e históricos; escreve apenas se for o gestor designado.
- TV continua restrito à apresentação, sem aba ou notificações desse módulo.
- Concessão/revogação é exclusiva de ADMIN. Quando já há gestor, a tela identifica quem é e exige confirmação explícita. A revisão da designação impede que uma confirmação antiga substitua um novo titular.
- Todas as alterações passam por autorização no servidor, CSRF e lista explícita de campos. Não existem endpoints de exclusão física.
- A designação é bloqueada no banco durante cada transação de escrita. Alterações de prazo/conclusão, geração de alertas e transferência seguem o mesmo bloqueio. O índice único no banco também impede eventos duplicados.
- O papel e a ativação são revalidados no banco, não apenas na sessão. Inativar o titular ou mudá-lo para TV revoga a designação em transação e mantém a autoria histórica.
- Auditoria inclui ator, data/hora, objeto, operação e valores antes/depois. A alteração é revertida se a auditoria falhar. Edição e exclusão de auditoria pelo ORM de entidade são bloqueadas; não existe API para esses procedimentos. Acesso direto de um administrador ao banco continua sujeito às permissões de infraestrutura.

## Status, datas e alertas

Status da ação: Não iniciada, Em andamento, Concluída. A situação de prazo é calculada separadamente pelo campo Quando? (`due_on`). Uma ação Em andamento pode estar Atrasada. Atraso continua no painel mesmo após ler a notificação.

Conclusão exige data efetiva, não futura; ao reabrir, retirar a data de conclusão. Eficácia é texto livre porque a referência só contém Atende e não define um catálogo completo. Quem? é o nome operacional, independente de conta de login e da permissão de gestão.

Alertas por dia de calendário em America/Sao_Paulo: D-3, D-1, D0 e vencido. Vencido é gerado uma vez por ciclo, não diariamente. Reexecuções são idempotentes. Após indisponibilidade, gerar apenas o evento da situação atual, sem disparar lembretes antigos em lote.

Alterar prazo ou reabrir cria novo ciclo; concluir invalida alertas anteriores. Transferir gestor invalida as notificações antigas e gera as atuais para o novo titular. Os eventos antigos não são apagados. As notificações não lidas aparecem primeiro, até 30 por lista; ao marcar leitura, as próximas pendentes ficam acessíveis. Abrir uma notificação é POST com CSRF e checagem de destinatário, marcando a leitura antes do redirecionamento para a ação.

O comando agendado é necessário para alertas que surgem apenas pela passagem dos dias. O sino consulta os alertas persistidos; não substitui o agendamento. Cadastros/alterações e transferências também atualizam os alertas pertinentes.

## Instalação e publicação

Migration aplicada nesta implementação SOMENTE ao MySQL local (`127.0.0.1`, banco PCM existente). Nenhuma publicação ou migration foi executada em produção. Não importar planilhas nem alterar a conexão TOTVS.

Publicar a revisão completa do módulo, incluindo migration, modelos, serviço, controllers, templates, rotas, CSS/JS e scripts de execução. Antes de liberar o tráfego para o código novo, executar a migration na raiz real da instalação (diretório com `bin/cake.php`). Preservar `.env` e `config/app_local.php`; não copiar `tmp`, senhas, fixtures ou evidências de teste.

```sh
php bin/cake.php migrations status --connection default
php bin/cake.php migrations migrate --connection default
php bin/cake.php nonconformity_alerts
```

Depois, designar o gestor em Usuários > Permissão Não Conformidades. Sem gestor, ADMIN continua cadastrando e todos os leitores continuam consultando, mas ninguém recebe notificações. O ambiente local usa `http://127.0.0.1:8765/pcm/nao-conformidades`. A configuração canônica de URL da instalação deve corresponder ao endereço onde ela é acessada; essa configuração existente não foi sobrescrita nesta implementação.

### Agendamento na hospedagem Linux

Configurar no agendador da aplicação, usando o usuário de serviço e o PHP corretos, sem agendar em duplicidade:

```cron
*/15 * * * * cd /CAMINHO_REAL/PCM_OrdensServico && /CAMINHO_REAL/php bin/cake.php nonconformity_alerts >> logs/nc-alerts.log 2>&1
```

Substituir os dois caminhos pela instalação real. O intervalo de 15 minutos recupera falhas temporárias e respeita a idempotência; datas do comando usam America/Sao_Paulo independentemente do fuso do agendador. Configurar retenção do log pela infraestrutura.

### Agendamento Windows

Script fornecido: `deployment/scripts/executar-alertas-nao-conformidades.cmd`. Na raiz local, execução manual:

```powershell
& .\deployment\scripts\executar-alertas-nao-conformidades.cmd C:\xampp\php\php.exe
```

No Agendador de Tarefas, criar tarefa exclusiva PCM_NaoConformidades_Alertas, com conta de serviço já provisionada, execução não interativa, repetição a cada 15 minutos, sem instâncias sobrepostas. Programa `CAMINHO_REAL_DO_PHP\php.exe`; argumentos `"CAMINHO_REAL\bin\cake.php" nonconformity_alerts`. Diretório inicial: raiz da aplicação. Não inserir senhas em script ou reconfigurar tarefas de outros módulos. A execução direta do PHP e o wrapper CMD não exigem mudar a política de scripts PowerShell, que está restrita neste computador.

Nenhuma tarefa recorrente foi instalada automaticamente nesta sessão: o usuário de serviço, a política de execução e o ambiente definitivo devem ser definidos na implantação. O comando e o script estão prontos; verificar o histórico de execução do agendador e executar manualmente após eventual falha.

## Verificação por etapa

Etapa inicial: cinco testes de serviço passaram (17 asserções). Integração ampliada: 14 testes de NC passaram (82 asserções), cobrindo fluxos, alertas, controle de destinatário, rollback de auditoria, revisão concorrente e inativação. Um teste antigo de renderização de dashboard foi revalidado junto com o módulo: 15 testes, 142 asserções. A suíte JavaScript tem 24 testes aprovados, incluindo três novos do sino.

Teste concorrente no MySQL local: duas requisições/processos tentaram conceder a permissão na mesma revisão; uma confirmou e a outra recebeu conflito. Somente uma linha e uma revisão foram persistidas. Evidência local em `tmp/nc-local-browser.cjs` e `tmp/nc-local-check.php`; as contas e registros temporários são removidos ao final da validação.

Limites: testes de portabilidade verificam migrations e SQLite; não houve execução em servidor PostgreSQL nesta sessão. Importação com prévia/confirmação é uma etapa futura e não está disponível. Não há upload/importação automática nem geração de números para registros sem NC.

## Resultado final em 30/10/2026

- Suíte PHP completa: **287 testes, 5.697 asserções, todos aprovados**. Evidência: `tmp/nc-full-tests.xml` e `tmp/nc-full-tests.log`.
- Suíte JavaScript: **24 testes aprovados**.
- Navegador Edge: login, cadastro real local de NC e ação, badges independentes Em andamento/Atrasada, painel, detalhes, acesso ao formulário de atualização e ausência de overflow horizontal em 1440, 768 e 390 pixels. Capturas desktop do painel e celular dos detalhes inspecionadas visualmente. Evidências: `tmp/nc-browser-validation.log`, `tmp/nc-dashboard-*.png`, `tmp/nc-details-*.png`.
- Na validação do navegador, apenas o redirecionamento do login existente foi remapeado no teste para a porta 8765, evitando o Apache da porta 80. A tela inicial geral após login foi substituída por uma página neutra no teste para não consultar o TOTVS; as páginas/formulários de NC usaram o servidor e o banco reais. Os redirecionamentos novos de NC, ações, permissão e notificações usam caminhos relativos à instalação, preservando domínio/porta, sem remapeamento no teste dos formulários de NC. Não foi alterada a configuração de URL da aplicação.
- Teste de corrida no MySQL com processos separados: uma concessão confirmada e uma rejeitada por revisão concorrente. Contas, NC, ações e eventos gerados exclusivamente por esses testes locais foram removidos; não foram removidos registros de usuários ou de módulos preexistentes.
- Migration local confirmada como aplicada. Comando PHP e wrapper CMD de alertas executados com sucesso. A tentativa inicial de usar um wrapper PowerShell encontrou a política restrita do computador; foi substituída pelo CMD, sem alterar políticas de segurança.
- `git diff --check` e verificações de sintaxe dos controllers/serviço/templates aprovados.

O código e os fluxos do módulo estão implementados e validados localmente. Permanecem como tarefas de implantação: publicar a revisão completa, aplicar a migration no ambiente de destino e instalar/verificar o agendamento recorrente conforme os caminhos e a conta de serviço reais. A importação da planilha continua deliberadamente fora desta entrega.
