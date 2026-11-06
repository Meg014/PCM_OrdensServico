# Não Conformidades — ajustes visuais finais

Alterações somente no projeto local. Nenhum acesso ao Ubuntu, envio real ou alteração de dados existentes.

## Resultado

- Consulta por token com os estilos, cores, preferência de tema claro/escuro e favicon do PCM, cabeçalho organizado, filtros alinhados e tabela ampla com rolagem horizontal. Os estilos novos têm classes próprias da consulta pública. Nenhuma alteração no layout global, no token ou nas permissões.
- Botão dos cinco e-mails com a cor de fundo no próprio link, borda clicável de 16 px, texto branco centralizado e cantos arredondados. O Outlook clássico recebe um botão VML com o mesmo endereço e `w:anchorlock`; os demais clientes recebem o link HTML. O conteúdo das mensagens, os destinatários e o endereço com token foram preservados.
- Data NC, Quando? e Data conclusão usam máscara `DD/MM/AAAA`, teclado numérico, edição com cursor e validação de calendário com anos bissextos. O evento `formdata` converte o valor enviado para `AAAA-MM-DD`, sem trocar o texto visível. Funciona no formulário completo, itens adicionados dinamicamente, edição individual e edição rápida com campos associados pelo atributo `form`. Sem JavaScript, o backend continua aceitando o formato brasileiro e validando datas reais.

SMTP, cron, fila, regras de prazo e backend não foram alterados.

## Arquivos alterados nesta etapa

- `templates/layout/nonconformity_consultation.php`
- `templates/NonconformityConsultations/view.php`
- `templates/email/html/nonconformity.php` — compartilhado pelos cinco eventos.
- `templates/Nonconformities/form.php`
- `templates/element/nonconformity_assets.php`
- `templates/element/nonconformity_action_fields.php`
- `templates/element/nonconformity_table.php`
- `webroot/css/pcm-nonconformities.css`
- `webroot/js/pcm-nc-consultation-theme.js` — novo.
- `webroot/js/pcm-nonconformity-dates.js` — novo.
- `tests/TestCase/Controller/NonconformitiesControllerTest.php`
- `tests/TestCase/Controller/NonconformityConsultationsControllerTest.php`
- `tests/js/pcm-nonconformity-dates.test.cjs` — novo.
- Este documento.

## Validação

Resultado: **358 testes PHP, 6.374 asserções e 36 testes JavaScript aprovados**. Sintaxe PHP e `git diff --check` também aprovados. O PHP local mantém o aviso preexistente de OpenSSL carregado duas vezes; a suíte não apresentou falhas.

Testes JavaScript: máscara progressiva, colagem ISO, edição, exclusão e limpeza, datas impossíveis, anos bissextos, bloqueio do formulário, conversão para ISO sem mudar a apresentação e preferência de tema.

Testes PHP: acesso sem login e somente leitura, isolamento por token, favicon, estrutura visual pública, links idênticos no HTML/VML/texto dos cinco eventos e salvamento de datas brasileiras/ISO sem mudar o vencimento. A suíte existente também verifica os avisos de 5 dias, 1 dia, vencimento, atraso, conclusão, destinatários e deduplicação.

Chrome headless local: consulta em 1440×1000 e 390×844, temas claro e escuro, ausência de transbordamento horizontal da página, rolagem interna da tabela e ausência de controles administrativos. Nos cinco e-mails, testes geométricos confirmaram que centro e bordas coloridas pertencem ao link. No navegador real, `FormData` de campos internos e externos ao formulário envia datas ISO e `31/02/2026` é bloqueada.

O Outlook desktop e o Outlook Web não foram executados neste ambiente. A estrutura VML foi verificada nos cinco modelos; a confirmação visual nesses clientes deve ser feita com um envio controlado para o próprio usuário após sua publicação, sem disparar a fila geral.

Relatórios: `tmp/nc-final-visual-php.log`, `tmp/nc-final-visual-php.xml`, `tmp/nc-final-visual-js.log` e `tmp/nc-visual/final-validation.json`. Capturas: `tmp/nc-visual/consulta-final-{desktop|celular}-{light|dark}.png`. O script local de inspeção está em `tmp/nc-tools/final-visual.cjs`, fora dos arquivos de implantação.

## Implantação

**Nenhuma migration ou variável de ambiente nova.** Publique os arquivos PHP, CSS e JavaScript juntos pelo seu procedimento habitual. Os assets usam versão por timestamp para atualizar o cache. Não há comando adicional de cron, configuração SMTP ou alteração de permissões nesta etapa.
