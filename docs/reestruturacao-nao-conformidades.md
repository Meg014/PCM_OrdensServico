# Não Conformidades — acompanhamento no formato da planilha

Reestruturação de 30/10/2026 sobre o módulo existente. Não houve recriação do módulo nem importação da planilha.

## Interface entregue

`/pcm/nao-conformidades` contém cabeçalho, botão de cadastro para quem pode gerenciar, filtros compactos e tabela. Os nove cards e as consultas de seus indicadores foram removidos. A tabela usa uma linha por ação, repetindo os dados da NC. Uma NC sem itens permanece consultável, com indicação Sem itens, sem gerar item fictício.

Colunas na ordem solicitada: NC nº; Item NC; Origem; Data NC; Não conformidade; Medida; O Que?; Quem?; Quando?; Data conclusão; Realizado; Verificação da Eficácia; Observação; Unidade / Setor; Ações.

Rolagem horizontal e vertical interna, cabeçalho fixo na área de rolagem, colunas de texto com largura própria e expansão nativa dos textos longos. Quebras de linha são mantidas. A tabela é paginada por linhas de ação (25 por página por padrão), sem omitir outros itens da mesma NC. Em celulares, a página cabe na tela e a tabela rola horizontalmente dentro do contêiner. Os detalhes também usam tabela de ações em vez de grandes cards.

## Campos e armazenamento

Todos os campos já existiam. Foi encontrada uma limitação em Medida: string de 40 caracteres com duas opções fixas. A migration `20261030010000_ExpandNonconformityMeasure` apenas amplia essa coluna para TEXT. A validação e os formulários agora aceitam medida livre, inclusive texto longo e quebras de linha, sem restringir ao catálogo anterior. Nenhuma coluna ou tabela foi apagada.

| Campo da referência | Armazenamento existente |
| --- | --- |
| NC nº | nonconformities.number (opcional, sem numeração automática) |
| Origem | nonconformities.origin (dez origens existentes) |
| Data NC | nonconformities.occurred_on |
| Unidade / Setor | nonconformities.unit |
| Não conformidade (Se aplicável) | nonconformities.description (texto opcional) |
| Item NC | nonconformity_actions.item (texto opcional, preserva zeros) |
| Medida | nonconformity_actions.measure (TEXT ampliado) |
| O Que? (What) | nonconformity_actions.description |
| Quem? (Who) | nonconformity_actions.responsible_name (sem vínculo obrigatório com login) |
| Quando? (When) | nonconformity_actions.due_on |
| Data conclusão | nonconformity_actions.completed_on |
| Realizado | nonconformity_actions.status |
| Verificação da Eficácia | nonconformity_actions.effectiveness |
| Observação | nonconformity_actions.notes |

Textos longos preservam inclusive espaços de borda e quebras de linha. Limites são validados com erro, sem truncamento silencioso: Medida/descrições/Observação até 20.000 caracteres; eficácia até 10.000; capacidade em bytes do TEXT também é respeitada. Números de NC e itens permanecem manuais; não são gerados para campos vazios.

## Cadastro e edição

O formulário de NC apresenta Dados da NC e Itens / Ações, com todos os campos originais. Nova NC inicia com um item; `+ Adicionar item` inclui mais itens sem enviar o formulário quando JavaScript está disponível. Há alternativa POST sem JavaScript para adicionar um item mantendo os dados, sem gravá-los. Somente itens ainda não salvos podem ser retirados do formulário; não foi criado endpoint para excluir ações existentes.

A NC e os itens enviados são gravados na mesma transação. Um erro em qualquer item ou na auditoria reverte todas as alterações dessa operação. Erros de validação/revisão devolvem o formulário com os dados preenchidos. Uma edição parcial não exclui itens omitidos. As revisões de NC e ações continuam impedindo sobrescrita concorrente silenciosa.

Há limite de 100 itens por operação e proteção adicional contra `max_input_vars` do PHP: o navegador impede envio que excederia a configuração, mantendo os campos na tela, e o backend exige um marcador de completude no final de formulários com itens. Um envio truncado não pode gravar apenas a parte recebida. Para conjuntos acima do limite, usar operações menores ou edição individual; não há limite de 100 ações armazenadas por NC.

Datas de cadastro/edição são mostradas em dd/mm/aaaa; o backend aceita esse formato e ISO e persiste datas reais. Data conclusão exige preenchimento para Concluída e não é substituída por “hoje”. Datas históricas já informadas são mantidas. Conclusão e eficácia permanecem independentes.

Na tabela, gestores e ADMIN editam diretamente Realizado, Quem?, Quando?, Data conclusão e Observação, com Salvar linha. O salvamento usa POST/CSRF, confirmação visual, revisão atualizada e badge de prazo recalculado. Erros mantêm os valores editados para correção. Campos longos/NC completa podem ser editados nos formulários ligados à linha. Sem JavaScript, o formulário de linha continua utilizável por POST.

## Alertas e acesso preservados

Sino, exclusividade do titular, transferência confirmada, deduplicação, auditoria e comando de geração de notificações foram preservados. As regras continuam no backend. Usuários comuns leem todos os campos/histórico, mas não recebem inputs de edição e são bloqueados ao tentar endpoints de escrita. TV continua sem acesso.

Prazos das linhas usam Quando? e estado Realizado separadamente: mais de 3 dias = no prazo; 3 ou 2 dias = atenção; 1 dia = urgente; hoje = vencimento; passado = atrasada; Concluída = sem alerta de vencimento ativo. Usam badges/bordas discretos, sem piscar. Notificações continuam em D-3, D-1, D0 e atraso; não foi acrescentado evento duplicado em D-2.

Também foi corrigido o tipo de exceção de validação para o nome disponível no CakePHP instalado (`UnprocessableContentException`), garantindo retorno 422 e preservação do formulário em vez de erro 500.

## Arquivos desta reestruturação

- `config/Migrations/20261030010000_ExpandNonconformityMeasure.php` (novo).
- `config/Migrations/schema-dump-default.lock` (atualizado pelo comando de migration).
- `config/routes.php` (rota de edição rápida).
- `src/Service/NonconformityService.php`.
- `src/Controller/NonconformitiesController.php`.
- `templates/Nonconformities/index.php`, `form.php`, `action.php`, `view.php`.
- `templates/element/nonconformity_action_fields.php`, `nonconformity_table.php`, `nonconformity_text.php` (novos).
- `webroot/css/pcm-nonconformities.css`.
- `webroot/js/pcm-nonconformities.js` (novo).
- `tests/TestCase/Service/NonconformityServiceTest.php`.
- `tests/TestCase/Controller/NonconformitiesControllerTest.php`.
- `tests/TestCase/Migrations/PostgresPortabilityTest.php` (inventário atualizado).
- `tests/js/pcm-nonconformities.test.cjs` (novo).
- Este relatório e a referência de atualização em `docs/nao-conformidades.md`.

As mudanças anteriores de descrições de O.S., permissões de usuários e documentação de outros ajustes foram preservadas, sem novas alterações de lógica nesses módulos.

## Migration executada

Somente no MySQL local, na raiz `C:\PCM\Sistemas\PCM_OrdensServico`:

```powershell
& C:\xampp\php\php.exe bin/cake.php migrations migrate --connection default
```

A migration nova foi confirmada como `up`. Antes da alteração, o banco local não tinha NC/ações cadastradas. Os registros temporários usados para validação foram removidos ao fim do teste, com filtros pelos IDs/autores dos próprios testes. Nenhum dado operacional preexistente foi apagado. O rollback automático da ampliação é bloqueado para evitar reduzir a capacidade da coluna e perder textos.

Nenhuma publicação ou migration em produção foi executada. Para implantar, publicar a revisão coerente e executar a migration na raiz real da instalação de destino antes de aceitar medidas longas. Não sobrescrever `.env` ou configurações de produção. Sem importação automática, sem escrita no TOTVS, sem mudanças nas regras de O.S.

## Validação

Regressão final: **299 testes PHP passaram, com 5.919 asserções**; **29 testes JavaScript passaram**, sem falhas. git diff --check sem erros.

Testes PHP ampliados cobrem formulário de uma/várias ações, transação/rollback de todo o conjunto, edição de todos os campos, Medida livre longa, textos com quebras, datas brasileiras/históricas, eficácia, edição rápida, revisões concorrentes, envio incompleto, auditoria, notificações e permissões. Paginação testada com 31 ações em duas páginas, sem duplicações ou omissões.

Navegador Edge com servidor e MySQL locais reais: duas ações no mesmo cadastro, validação 422 sem perder campos, medida livre longa, salvamento de linha, data histórica mantida após recarga e edição completa. Depois, conjunto temporário de 40 ações para rolagem e paginação. Confirmados 15 cabeçalhos, ausência de cards, cabeçalho fixo, rolagem horizontal interna e ausência de overflow horizontal da página em 1440, 768 e 390 pixels. Capturas desktop e celular foram inspecionadas visualmente.

Apenas o redirecionamento do login existente é adaptado no teste para a porta 8765; a página inicial geral é substituída por uma página neutra para evitar consultas TOTVS. Cadastro, validação, tabela e edição de NC usam os endpoints e o banco reais. Contas/NC/ações temporárias são removidas ao final; não são dados importados da planilha.

Evidências locais (não publicar): `tmp/nc-sheet-full-tests.xml`, `tmp/nc-sheet-final-targeted.xml`, `tmp/nc-restructure-js.log`, `tmp/nc-sheet-browser.log`, `tmp/nc-sheet-1440.png`, `tmp/nc-sheet-768.png`, `tmp/nc-sheet-390.png`.
