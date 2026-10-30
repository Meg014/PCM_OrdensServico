# Plano — Gestão de Não Conformidades

Data: 30/10/2026. Etapa: inspeção e proposta, antes de alterações estruturais.

Atualização após autorização de implementação: o texto abaixo preserva o planejamento original. O estado entregue, os arquivos alterados e a validação estão em `docs/nao-conformidades.md`. Os status definitivos aprovados são **Não iniciada, Em andamento e Concluída**, substituindo a proposta inicial “Pendente”. A implementação foi validada localmente, sem importação ou publicação em produção; o agendamento recorrente deve ser instalado na implantação.

## Estrutura inspecionada

- CakePHP 5.4, plugin Authentication, login por e-mail/senha e sessão existentes em `src/Application.php`.
- CSRF já está habilitado globalmente. Não criar autenticação paralela.
- `AppController::beforeFilter()` consulta novamente o usuário ativo a cada requisição, valida senha/perfil e aplica a troca obrigatória de senha. A nova autorização aproveitará esse usuário atualizado, sem confiar em permissões antigas da sessão.
- Perfis: ADMIN, USUARIO e TV. Administração de usuários já é exclusiva de ADMIN no backend.
- `UsersController::saveUser()` aceita somente uma lista explícita de campos e salva em transação. A nova concessão não será um campo livre de mass assignment.
- Tabela `users` confirmada no banco local: id, nome, email, password, role, maintenance_area_id, ativo, created, modified e must_change_password. Não existe permissão específica para Não Conformidades.
- Banco local inspecionado somente por metadata: MySQL. A configuração suporta MySQL/PostgreSQL e os testes usam SQLite; migrations devem respeitar essa portabilidade. Nenhuma escrita operacional foi feita.
- Tabelas atuais: cake_migrations, cost_centers, equipment, maintenance_areas, report_imports, services, tv_devices, users, work_order_snapshots, work_orders.
- Não existem tabelas de Não Conformidades, sino de notificações persistentes ou trilha genérica de auditoria. Os históricos de O.S. são específicos e não devem ser reutilizados como histórico desse módulo.
- Menu e tema centralizados em `templates/layout/default.php`, Bootstrap e `webroot/css/pcm.css`.
- TOTVS usa uma conexão separada de leitura. O novo módulo gravará exclusivamente no banco próprio do PCM.

## Decisões pendentes antes de implementação

1. Arquivo recebido e inspecionado em 30/10/2026: `C:\Users\maria.goncalves\Desktop\FML-GQ-018-02 Masterplan - Gestão de Não conformidades..xlsx`. O mapeamento abaixo passa a orientar os campos. Nenhum registro foi importado.
2. Decisão confirmada pelo usuário em 30/10/2026: ADMIN e USUARIO acessam o novo módulo; TV permanece restrito à apresentação, sem menu, notificações ou acesso aos endpoints de Não Conformidades. Não alterar as permissões de TV em `AppController.php`.

As duas pendências de referência foram resolvidas: planilha disponível e TV restrito. Este documento apresenta a proposta atualizada antes de qualquer alteração estrutural.

## Mapeamento da planilha recebida

Abas: `Planilha1` (masterplan, colunas A:N, cabeçalho na linha 3) e `Dados` (lista de origens). A identificação interna do formulário é `FML-GQ-018-00/00`, diferente da revisão indicada no nome do arquivo; preservar essa informação como referência, sem presumir equivalência de revisões. A unidade/setor do cabeçalho é Servlasa: será um dado do cadastro, não uma restrição de acesso nem uma classificação por centro de custo TOTVS.

| Coluna | Campo original | Destino proposto |
| --- | --- | --- |
| A | ID | Referência da linha de origem, caso uma importação seja autorizada futuramente; não substituir a chave interna do sistema |
| B | NC n° | Número da NC, preservado como texto |
| C | Item NC | Identificação do item/ação dentro da NC, preservada como texto |
| D | Origem | Origem da NC, com catálogo inicial da aba Dados |
| E | Data NC | Data da NC |
| F | Não conformidade (Se aplicável) | Descrição da NC, separada da ação |
| G | Medida | Tipo de medida da ação |
| H | O Que? (What) | Descrição da ação |
| I | Quem? (Who) (nome) | Responsável operacional da ação |
| J | Quando? (When) (dd/mm/aa) | Prazo da ação; referência dos alertas |
| K | Data conclusão | Data efetiva de conclusão da ação |
| L | Realizado | Status da ação |
| M | Verificação Da Eficácia | Resultado da verificação de eficácia da ação |
| N | Observação | Observação da ação |

A proposta é uma NC com várias ações/itens. Não agrupar apenas pelo texto da descrição ou supor que o número é único globalmente entre unidades/origens. A regra de numeração deverá usar um escopo explícito e uma chave interna independente.

Origens da aba Dados: Auditoria Interna; Auditoria Externa; Inspeção BPF; Meio Ambiente; Inspeção vidros; Reclamação de cliente; Não conformidade interna; Análise crítica; MOC; Fiscalização. A validação Excel de origem aponta para `Dados!$A$2:$A$11`.

Valores observados (não são listas completas de estados permitidos): Medida contém Correção e Ação corretiva; Realizado contém Concluído e Em andamento; Eficácia contém Atende. Há campos vazios. Não inferir “Não atende”, “Cancelada”, “Pendente” ou conclusão a partir de ausência de informação como se fossem valores originais da planilha. Para cadastros novos, a proposta de estado inicial é Pendente; atraso será calculado pelo prazo e conclusão, sem sobrescrever o status manual. Valores adicionais de eficácia e transições serão explicitados na implementação, não extraídos por suposição.

O arquivo possui 113 linhas com ID, das quais 91 têm descrição de ação; 22 linhas com ID não têm número de NC. Há 21 números de NC distintos não vazios. Esses números descrevem preenchimento da planilha, não a quantidade de registros que serão criados. Linhas pré-numeradas/incompletas não deverão virar ações ou alertas automaticamente.

O campo Quem contém nomes, não identificadores de contas. Armazenar o nome operacional e permitir vínculo opcional validado com uma conta, sem conceder permissão e sem usar correspondência automática por nome. O prazo e a conclusão são datas Excel: uma eventual importação futura precisará converter os seriais respeitando o calendário do arquivo e validar inconsistências, sem correção silenciosa.

A planilha não define login, destinatário de alertas, unicidade do gestor, trilha de auditoria ou regras completas de transição. Essas regras vêm do pedido do usuário. Verificação de eficácia permanecerá na ação, conforme a coluna M, com autoria/data da verificação registradas pelo sistema. Conclusão e eficácia são informações distintas.

Indicadores propostos: NC cadastradas, ações pendentes/em andamento/concluídas, ações por faixa de prazo e verificações de eficácia registradas/pendentes. Os indicadores contarão apenas registros persistidos e válidos do módulo, sem incorporar as linhas incompletas do arquivo. Nenhuma integração com os indicadores TOTVS existentes.

Evidências de leitura locais: `tmp/nonconformity-workbook-structure.json` e `tmp/nonconformity-workbook-summary.json`; não publicar esses arquivos nem a planilha como asset público.

## Matriz de acesso proposta

| Operação | USUARIO comum | Responsável explícito ativo | ADMIN |
| --- | --- | --- | --- |
| Consultar NC, ações, indicadores, filtros e histórico | Sim | Sim | Sim |
| Cadastrar/editar NC e ações, responsáveis, prazos, status, conclusões, observações e eficácia | Não | Sim | Sim |
| Conceder/revogar/substituir a permissão de gestão | Não | Não | Sim |
| Receber alertas de prazo | Não | Sim | Somente se explicitamente designado |
| Excluir definitivamente registros ou histórico | Não | Não | Não previsto |

Excluir definitivamente não foi autorizado como operação do módulo. Não criar endpoint de exclusão física. Caso seja necessário cancelamento/arquivamento, definir o fluxo e manter a trilha de auditoria.

O responsável de uma ação é um dado operacional e NÃO concede acesso de edição nem determina o destinatário dos alertas. O destinatário será exclusivamente o titular da permissão de gestão.

## Permissão única e substituição segura

Evitar um booleano isolado em cada usuário: ele não garante sozinho um único titular diante de requisições concorrentes.

Criar `nonconformity_manager_assignments` com UMA linha de chave primária fixa, criada pela migration, contendo `user_id` opcional, número de revisão e data da alteração. Essa linha é a fonte única da permissão “Gerenciar Não Conformidades”. A UI do cadastro de usuário mostra a permissão derivada dessa designação, independente de ADMIN.

Concessão/substituição será executada por um serviço transacional, exclusivo de ADMIN:

1. Validar novamente o ator, o usuário de destino ativo e seu perfil elegível (ADMIN/USUARIO; TV depende da decisão anterior).
2. Bloquear a linha única de designação e conferir a revisão esperada. O mecanismo de bloqueio será compatível com cada driver; testes concorrentes reais serão feitos no banco suportado, não inferidos apenas de SQLite.
3. Se outro titular existir, apresentar seu nome e a substituição proposta. O primeiro envio NÃO muda a designação.
4. Exigir uma segunda requisição POST com confirmação explícita, CSRF, titular anterior e revisão esperada. Se alguém alterou a designação nesse intervalo, recusar e pedir nova confirmação, sem substituir silenciosamente.
5. Atualizar somente a linha única e inserir a auditoria na mesma transação. Se a auditoria falhar, reverter a alteração.

Alterações de perfil/inativação do titular também passarão pelo serviço e pela mesma transação para revogar a designação e impedir alertas a inativos. Não transferir automaticamente para outro administrador. Mostrar ausência de responsável no cadastro administrativo. Não impedir usuários comuns de consultar o módulo por falta de titular.

Na próxima requisição, o antigo titular perde a capacidade de escrita; se ele também for ADMIN, mantém a escrita pelo perfil administrativo, mas perde o recebimento de alertas. A substituição não muda a autoria de registros antigos.

## Modelo de dados previsto

Novas tabelas no banco PCM, com relações e índices:

- `nonconformity_manager_assignments`: designação única e revisão.
- `nonconformities`: identificação e campos da NC a confirmar na planilha, status, origem, datas, autoria e revisão para controle de edições concorrentes.
- `nonconformity_actions`: vínculo com a NC, descrição, responsável, prazo, status, conclusão, observações e revisão do ciclo de prazo. Campos de eficácia serão vinculados à NC/ação conforme a planilha.
- `nonconformity_audit_events`: histórico somente de inclusão, com ator, identidade do objeto, operação, data/hora, valores anteriores/novos relevantes e contexto da substituição. Não registrar senhas, tokens ou dados de sessão. Preservar autoria mesmo se a conta for inativada.
- `nonconformity_notifications`: destinatário, ação, evento, prazo de referência, revisão do ciclo, revisão da designação, criação, leitura e eventual perda de validade. Sem exclusão em cascata da trilha histórica.

Concessões, revogações, substituições e alterações de NC/ações serão auditadas. A marcação de leitura ficará registrada na própria notificação. Nenhuma migration ou importação será executada nesta etapa de plano.

## Notificações e prazos

- Usar datas de calendário em America/Sao_Paulo: faltam 3 dias, falta 1 dia, vence hoje e prazo vencido.
- Criar comando idempotente para execução agendada diária e após mudanças relevantes (prazo, conclusão, reabertura ou titular). Consultar somente as ações do novo módulo.
- Não depender de o usuário abrir a página para produzir alertas. A frequência/configuração do agendamento será documentada para a hospedagem real.
- Evitar duplicação com índice único por destinatário + ação + evento + prazo/ciclo + revisão de designação. Inserção segura diante de concorrência: verificação em PHP sozinha não basta.
- Para uma ação já atrasada, gerar um evento de vencimento por ciclo, sem repetir diariamente. O painel continuará destacando o atraso enquanto a ação estiver pendente, mesmo depois da leitura do alerta.
- Em execução após indisponibilidade, gerar o alerta correspondente à situação atual (inclusive vencido), sem disparar em lote lembretes anteriores que perderam sentido.
- Ao concluir/cancelar uma ação, parar alertas e retirar eventos antigos sem validade do contador ativo, preservando seus registros. Não gerar alertas de atraso para ação sem prazo; tratá-la como pendência de cadastro.
- Mudança de prazo ou reabertura cria novo ciclo auditado e invalida os alertas do ciclo anterior. Não editar retrospectivamente os eventos antigos.
- Ao substituir o gestor, os alertas atuais passam ao novo titular com deduplicação; notificações antigas permanecem no histórico sem continuar aparecendo no sino do antigo titular. Revalidar o titular antes de inserir e antes de listar/abrir um alerta para evitar corrida com substituição ou inativação.
- ADMIN recebe notificações somente quando é o titular explícito. Nenhum e-mail, WhatsApp ou integração externa está incluído.
- Sino no menu superior: contador de notificações válidas não lidas, lista paginada e link para a ação/NC correta. Alterar leitura por POST com CSRF; leitura de outros usuários é proibida. Abrir a ação não concede permissão de edição.

## Backend e interface

- Serviço de autorização restrito ao módulo: leitura para perfis autorizados; escrita somente ADMIN ou titular ativo. Aplicar em TODAS as rotas de mutação, inclusive requests JSON/diretas, e novamente nos serviços que fazem a transação.
- Usar o usuário revalidado pelo fluxo existente; proteger contra IDOR, campos extras, ações vinculadas a outra NC e alteração de campos de auditoria pelo cliente.
- GET não grava dados de negócio. Escrita por POST/PATCH com CSRF, validação de campos, revisão concorrente e respostas 403/405/409 quando aplicável.
- Novos controllers próprios, sem afrouxar a whitelist de ações de PcmController nem as permissões dos demais módulos.
- Menu “Não Conformidades” para os perfis definidos. Manter o bloqueio existente de troca obrigatória de senha.
- Consulta com filtros GET por status, responsável, origem e prazo; indicadores e histórico disponíveis aos leitores. Controles de edição renderizados só para quem pode escrever, além da validação obrigatória no servidor.
- Tema escuro/claro e responsividade do PCM, com CSS/JS específicos do módulo para evitar regressões nas telas de setores.

## Arquivos previstos (nomes sujeitos ao mapeamento da planilha)

### Modificar

- `config/routes.php`: rotas explícitas de NC, ações, histórico e notificações.
- `src/Controller/UsersController.php`: concessão/revogação e confirmação de substituição por serviço transacional, sem ampliar os campos livres do cadastro.
- `src/Model/Table/UsersTable.php`: integração controlada da inativação/mudança de perfil do titular.
- `templates/Users/form.php`, `templates/Users/index.php`: permissão, titular atual e confirmação de substituição.
- `templates/layout/default.php`: aba e sino conforme perfil.
- `tests/TestCase/Migrations/PostgresPortabilityTest.php`: atualizar o inventário fixo de migrations ao acrescentar as novas, mantendo a verificação de portabilidade.
- Testes de usuários/autorização/TV existentes: ampliar apenas para verificar preservação das regras e a nova permissão.

Não está prevista alteração estrutural em `users` nem em `User::_accessible`; a fonte da permissão será a designação única. Não há necessidade de alterar a autenticação em `src/Application.php`. `AppController.php` só precisará de ajuste se for confirmada uma exceção para TV; não ampliar as permissões antigas.

### Criar

- `config/Migrations/<timestamp>_CreateNonconformityManagement.php`.
- `src/Model/Table/NonconformitiesTable.php`, `NonconformityActionsTable.php`, `NonconformityManagerAssignmentsTable.php`, `NonconformityAuditEventsTable.php`, `NonconformityNotificationsTable.php` e entidades correspondentes.
- `src/Service/NonconformityAccess.php`, `NonconformityManagerService.php`, `NonconformityWorkflowService.php`, `NonconformityNotificationService.php`.
- `src/Controller/NonconformitiesController.php`, `NonconformityActionsController.php`, `NonconformityNotificationsController.php`.
- `src/Command/NonconformityAlertsCommand.php`.
- `templates/Nonconformities/index.php`, `view.php`, `form.php`; templates de formulário de ações e confirmação de substituição; elementos de histórico e sino.
- `webroot/css/pcm-nonconformities.css`, `webroot/js/pcm-nonconformity-notifications.js`, carregados com versão.
- Testes de migrations, autorização HTTP, concessão concorrente, auditoria, fluxo e notificações; testes JS do sino.
- `docs/nao-conformidades.md`: modelo final, permissões, instalação, agendamento, recuperação de falhas e validação.

## Ordem de implementação após apresentar este plano

1. Usar o mapeamento da planilha acima e a decisão TV restrito; detalhar validações e transições de cadastro antes de codificá-las.
2. Criar migrations e modelos em ambiente de teste; validar unicidade, relações e portabilidade.
3. Implementar autorização, designação única, confirmação e auditoria transacional.
4. Implementar leitura, filtros, indicadores e histórico; depois os fluxos de escrita.
5. Implementar alertas persistentes, comando agendado e sino.
6. Validar segurança e regressões; preparar documentação e revisão local antes de qualquer publicação/migração em produção.

## Validação prevista

- Anônimo bloqueado; USUARIO lê mas recebe 403 em toda tentativa de escrita direta; ADMIN escreve sem ser destinatário automático; titular escreve e recebe alertas.
- Somente ADMIN concede permissão; payload extra não eleva privilégios; inativação/revogação tem efeito na próxima requisição.
- Duas concessões concorrentes resultam em apenas um titular; confirmação antiga não substitui um titular recém-alterado.
- Auditoria e alteração confirmam/revertem juntas; substituir titular não apaga NC, ações ou autoria.
- Alertas em D-3, D-1, D0 e atraso com relógio controlado, fuso definido, reexecução, concorrência, troca de prazo, conclusão/reabertura e troca de titular.
- Atraso permanece no painel após leitura do sino; contador não inclui concluídos/inválidos; link abre a ação correta e não permite ler notificação alheia.
- Datas/responsáveis/status inválidos rejeitados; edição concorrente não sobrescreve silenciosamente; filtros GET e responsividade testados.
- Regressões de usuários, ADMIN/USUARIO/TV, senha obrigatória, setores e permissões antigas; nenhum SQL de escrita no TOTVS.

## Alterações feitas nesta etapa

Somente este plano foi criado. Nenhuma migration, tabela, endpoint, permissão ou formulário foi implementado. A alteração preexistente em `docs/layout-local-producao.md` foi preservada. Não foram executados testes funcionais novos, pois não há código novo de aplicação nesta etapa; a inspeção do banco foi somente de leitura.
