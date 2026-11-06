# Não Conformidades — consulta por link e cadastro no formulário

Implementação local, sem deploy, ativação do cron ou envio real de e-mail.

## Consulta sem login

Os cinco tipos de mensagem utilizam o mesmo gerador de links:

`https://pcm.biolasa.com.br/consulta/nao-conformidades/{token}`

O token contém uma assinatura HMAC-SHA256 com separação de contexto, um valor aleatório de 128 bits e validade de **90 dias a partir da geração do e-mail**. Usa o `SECURITY_SALT` existente; não é uma senha de usuário nem cria uma sessão PCM. Não é necessário adicionar tabela ou credencial para esses links.

Cada link autoriza somente a combinação NC/ação/responsável/ciclo de atribuição. O servidor verifica o vínculo atual, a situação ativa do responsável, sua revisão cadastral e o hash do e-mail em toda consulta. Trocar o responsável, alterar seu cadastro ou remover o registro invalida os links anteriores. Alterações de prazo e conclusão aparecem na consulta atualizada, sem conceder escrita. Mensagens novas recebem links novos; e-mails já recebidos não podem ser alterados.

A consulta possui pesquisa textual e filtros de Realizado e prazo, sempre aplicados à única ação autorizada. Não há visão geral compartilhada, navegação administrativa ou histórico de outras ações. IDs em parâmetros de pesquisa não ampliam o acesso. A rota aceita somente GET; não existe operação de escrita pública. As rotas administrativas continuam exigindo a autenticação e autorização anteriores.

Como é um link de acesso, qualquer pessoa que receba uma cópia poderá consultar a ação durante sua validade. Não o publique ou compartilhe. As respostas usam `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, proibição de indexação e CSP que restringe os recursos à própria origem e impede incorporação em frames. Não há recursos externos nessa página.

O comando de teste SMTP continua usando dados fictícios, sem cadastrar ações. Portanto, o botão de uma mensagem de **teste controlado** não autoriza a consulta a dados reais; os links funcionais são gerados para as notificações de ações existentes. O comando de teste não mudou sua forma de envio.

## Responsáveis

No formulário da ação, Quem? e E-mail obrigatório ficam lado a lado. O nome sugere os cadastros ativos e preenche o endereço ao selecionar um deles. Um nome novo e e-mail válido criam um responsável ativo junto com a ação, dentro da mesma transação e bloqueio já utilizados no módulo. Qualquer erro posterior desfaz contato, NC, ações, auditoria e fila da operação.

A correspondência usa a normalização existente de nome (acentuação, caixa e espaços); um cadastro existente é reutilizado. Cadastros inativos exigem revisão administrativa. O backend rejeita nomes/IDs inconsistentes e endereços inválidos, inclusive requisições forjadas.

Para corrigir o endereço de um cadastro existente, use o link **Responsáveis** no próprio formulário. Essa tela preserva autorização, controle de revisão e auditoria. O formulário de ação não troca silenciosamente o endereço de uma pessoa já cadastrada; após corrigir, recarregue o formulário da ação. Os nomes históricos das ações vinculadas continuam preservados. A correção cadastral mantém o cancelamento de mensagens pendentes para o endereço antigo e a verificação final da fila já existentes.

## Unidade e Medida

Novas Unidades: `Servlasa/ Lasa` e `Usina/ Fábrica`.

Novas Medidas: `Correção` e `Ação Corretiva`.

O formulário usa selects e o backend rejeita valores novos fora dessas listas. Um registro antigo com texto diferente recebe uma opção adicional **(histórico — manter)**, limitada ao valor já salvo naquele registro. É possível mantê-lo integralmente ou substituir conscientemente por uma das opções fixas. Não há normalização nem atualização em massa de registros históricos. A edição rápida continua preservando esses campos.

## Arquivos desta melhoria

- `src/Service/NonconformityConsultationService.php`: emissão e validação dos links.
- `src/Controller/NonconformityConsultationsController.php`: consulta isolada, filtros e cabeçalhos.
- `templates/layout/nonconformity_consultation.php` e `templates/NonconformityConsultations/view.php`: página sem navegação administrativa.
- `config/routes.php`: rota GET exclusiva.
- `src/Service/NonconformityEmailService.php`: destino compartilhado dos cinco modelos, preservando HTML e texto existentes.
- `src/Service/NonconformityService.php`: listas fixas e resolução/cadastro transacional de responsáveis.
- `src/Controller/NonconformitiesController.php`: preservação dos valores do formulário em caso de erro.
- `templates/Nonconformities/form.php`, `templates/Nonconformities/action.php`, `templates/element/nonconformity_action_fields.php`, `templates/element/nonconformity_responsible_inline.php`: selects e campos de responsável.
- `templates/element/nonconformity_table.php`: tabela compartilhada com modo público somente leitura.
- `webroot/js/pcm-nonconformities.js`: sugestões e preenchimento de e-mail.
- Testes: `tests/TestCase/Controller/NonconformityConsultationsControllerTest.php`, `tests/TestCase/Controller/NonconformitiesControllerTest.php`, `tests/TestCase/Service/NonconformityServiceTest.php`, `tests/TestCase/Service/NonconformityEmailServiceTest.php`, `tests/TestCase/Command/NonconformityAlertsCommandTest.php`, `tests/js/pcm-nonconformities.test.cjs`.

## Ubuntu após sua publicação

**Nenhuma migration nova** foi criada por esta melhoria. A migration anterior `20261106000000_AddNonconformityResponsibleEmail` continua necessária caso ainda não esteja aplicada. Consulte antes o estado no servidor com `php bin/cake.php migrations status` e aplique as pendentes conforme o procedimento de implantação existente.

Não há dependências nem variáveis SMTP novas. Preserve `config/.env` do servidor, mantenha `DEBUG=false` e o `SECURITY_SALT` forte, secreto e estável. PHP web e CLI precisam carregar o mesmo salt. Rotacioná-lo invalida todos os links emitidos anteriormente. Não copie o `.env` local para produção.

O domínio oficial deve servir também `/consulta/nao-conformidades/...` pelo mesmo front controller CakePHP; se o proxy atualmente encaminhar somente `/pcm/...`, inclua essa nova rota. O acesso requer HTTPS. Não registre a URL completa com tokens em ferramentas de analytics, captura de erros ou logs de acesso. Se houver logs Apache/Nginx por caminho, mascare ou exclua o segmento do token nessa rota; mantenha os logs operacionais da fila, que registram IDs e não esses links.

O cron, `flock`, SMTP, regras de vencimento, retries e deduplicação continuam os mesmos. Siga [o guia de produção](nao-conformidades-producao.md) para os comandos já preparados. Esta melhoria não os instala nem habilita.

Para conferir configuração e fila sem gerar nem enviar notificações:

```sh
php bin/cake.php nonconformity_alerts --dry-run
```

Para testar a consulta em homologação, use uma ação de teste vinculada a um destinatário controlado; a assinatura valida dados da instalação que emitiu o link, e a URL dos e-mails continua sendo exclusivamente o domínio oficial. Não dispare a fila de responsáveis reais para validar a página.

## Validação local

Os testes automatizados usam banco SQLite separado e transportes de e-mail simulados. Cobrem tokens válidos, adulterados e expirados; isolamento entre ações e NCs; bloqueio de escrita e ausência de autenticação por token nos demais módulos; revogação por alteração cadastral/atribuição; links HTML e texto dos cinco eventos; cadastro/reutilização de responsáveis; validação e correção controlada de e-mail; rollback; listas fixas e preservação histórica. A suíte existente verifica prazos, conclusão com fila pendente, alteração de responsável/prazo, reabertura, cron repetido e concorrência.

Resultado final: **357 testes PHP, 6.336 asserções e 31 testes JavaScript aprovados**. Relatórios locais: `tmp/nc-improvements-all.log`, `tmp/nc-improvements-all.xml` e `tmp/nc-improvements-js.log`. O PHP local emite o aviso preexistente de carregamento duplicado de OpenSSL, sem falhas na suíte.
