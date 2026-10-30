# Layout dos filtros: diagnóstico e publicação

## Retomada em 30/10/2026 — situação atual

As instruções e observações de 29/10 abaixo são o histórico do diagnóstico. Na nova consulta pública de 30/10, o HTML de login do servidor JÁ contém versionamento nos dois CSS e nos três scripts comuns. Por exemplo: `/css/pcm.css?1793296026`. Não há necessidade de repetir a publicação apenas por falta de versão nesses arquivos. Esta sessão não realizou publicação.

Os oito assets públicos foram baixados novamente, com HTTP 200 e hashes idênticos aos locais. A comparação controlada com o mesmo HTML voltou a apresentar as mesmas coordenadas dos controles em 1440, 768 e 390 pixels. O teste de integração dos filtros com `debug=false` passou novamente: 1 teste, 64 asserções.

A aplicação local está respondendo em `http://127.0.0.1:8765`. O acesso público ao setor de produção continua redirecionando para `/login`, portanto a validação visual da página autenticada real permanece pendente. Não se deve declarar essa comparação concluída com base apenas no teste controlado. A próxima verificação é abrir o setor autenticado, recarregar com Ctrl+F5 e comparar com a mesma largura de viewport (1440 pixels CSS, zoom 100%). Se o problema persistir, comparar as classes e o estilo calculado do formulário conforme o procedimento ao final deste documento.

Nenhuma nova alteração de funcionalidade foi necessária nesta retomada. Evidências atualizadas: `tmp/sector-production-comparison.json` e `tmp/asset-version-recheck-2026-10-30.log`.

**Encerramento:** em 30/10/2026, após a orientação de recarregar com Ctrl+F5, o usuário confirmou que os filtros no servidor estão alinhados. A pendência visual descrita acima foi resolvida por essa confirmação do usuário, sem acesso desta sessão à página autenticada. O resultado é compatível com atualização de cache após a publicação; não houve inspeção do cache antigo para comprovar sua origem.

Verificação em 29/10/2026. Local: `http://127.0.0.1:8765`. Produção: `https://pcm.biolasa.com.br`.

## Conclusão e limites

Não foi confirmada uma diferença no CSS atualmente publicado: os oito assets listados abaixo responderam HTTP 200, com tipo de conteúdo adequado, e são idênticos byte a byte aos locais (SHA-256). O CSS público já contém `.pcm-sector-filter-fields { display: grid; ... }`, os estilos do autocomplete e as regras responsivas. Não há 404 nos assets verificados.

Foi confirmada uma lacuna de versionamento: o HTML público de login em produção referencia `/css/pcm.css` e os demais assets sem query de versão. No projeto, `Asset.timestamp` está comentado; apenas ativar `true` não resolveria produção com debug desativado. Isso permite que uma versão antiga já armazenada no navegador seja reutilizada. É uma hipótese plausível para o sintoma, não uma comprovação do conteúdo do cache do navegador do usuário.

A URL de setor em produção redireciona para `/login` sem autenticação. Não houve acesso a sessão, código PHP, filesystem, configuração, OPcache ou logs do servidor. Não foi possível confirmar se o HTML autenticado publicado possui as mesmas classes nem comparar a tela autenticada real dos dois ambientes. Outras hipóteses ainda abertas são template/PHP antigo em produção, HTML retido por proxy e viewport efetivo menor (zoom/DevTools abertos).

Em teste controlado no Edge, o MESMO HTML do template local, renderizado com dados reais, foi usado com assets locais e com cópias dos assets públicos de produção. As coordenadas e dimensões dos cinco controles foram exatamente iguais nos dois casos em 1440, 768 e 390 pixels. Isso isola os assets; não equivale a uma comparação da página autenticada real do servidor.

## Tecnologia e arquivos carregados

Não existe etapa de build do CSS dessa tela. Não há Tailwind ou configuração de bundler no projeto. O layout usa Bootstrap 5.3.0 distribuído no `webroot` e CSS personalizado servido diretamente.

Ordem dos estilos: `webroot/css/bootstrap.min.css`, depois `webroot/css/pcm.css`.

JavaScript do layout: `webroot/js/bootstrap.bundle.min.js`, `pcm-theme.js`, `pcm-sectors-menu.js`.

JavaScript do setor: `webroot/js/pcm-equipment-autocomplete.js`, `pcm-sector-unit-filter.js`, `pcm-protheus-sector.js`.

HTML: `templates/layout/default.php` e `templates/Pcm/sector_protheus.php`. O formulário usa `.pcm-sector-filter-fields`; seus filhos usam `.pcm-sector-equipment` e `.pcm-sector-filter-actions`. O painel usa `.pcm-sector-filter-panel`.

Desktop: a partir de 992 pixels CSS de viewport, equipamento, duas datas e grupo de ações ficam na mesma linha. Abaixo disso, a grade se reorganiza; abaixo de 576 pixels, usa uma coluna. A largura física do monitor não é suficiente: zoom e ferramentas abertas alteram a largura em pixels CSS.

## Correção preparada localmente

Foram alterados APENAS:

1. `templates/layout/default.php`: opção `timestamp => force` nos dois CSS e três scripts comuns.
2. `templates/Pcm/sector_protheus.php`: mesma opção nos três scripts do setor.
3. `tests/TestCase/Controller/PcmControllerTest.php`: verifica as oito URLs com versão, inclusive com `debug=false`, mantendo as verificações dos filtros e navegação.
4. Este documento.

O CakePHP acrescenta `?TIMESTAMP_DO_ARQUIVO` aos assets locais existentes, preservando seus caminhos e a ordem de carregamento. Os valores são calculados no servidor; não precisam coincidir com os timestamps locais. O versionamento continua ativo com debug desligado e não depende de modificar `config/app_local.php` ou `.env`.

Nenhum CSS, JS de comportamento, SQL, classificação de centro de custo, indicador ou registro TOTVS foi alterado neste ajuste.

## Publicação

Não foi feita publicação nem limpeza no servidor. Não se conhece o caminho físico da instalação de produção.

Publicar pelo procedimento normal da aplicação os DOIS templates acima, da mesma revisão. Não publicar `.env`, `config/app_local.php`, banco, `tmp`, `logs`, configurações do webserver ou de outros sistemas. Fazer a substituição coordenada dos templates e manter a revisão anterior para reversão.

Os oito assets já conferem com os locais: não precisam ser recompilados ou substituídos neste diagnóstico. Se uma nova consulta mostrar diferença de hash, publicar também os respectivos arquivos de `webroot/css` e `webroot/js` da mesma revisão dos templates. Se o HTML autenticado ainda for antigo, a publicação de `templates/Pcm/sector_protheus.php` deve incluir a estrutura `.pcm-sector-filter-fields` e a âncora do autocomplete da revisão local.

Não há `npm install`, `npm run build`, migração, importação TOTVS ou atualização Composer necessária para este ajuste.

### Comandos após publicar

Executar na RAIZ REAL da instalação de produção do PCM_OrdensServico: o diretório que contém `bin/cake.php`, `templates` e `webroot`. Não executar a partir de `webroot`. Substituir o caminho abaixo pelo caminho real, que não está disponível nesta sessão.

Windows PowerShell:

```powershell
Set-Location -LiteralPath 'CAMINHO_REAL_DA_INSTALACAO_PCM_OrdensServico'
php -l templates/layout/default.php
php -l templates/Pcm/sector_protheus.php
php -r "echo hash_file('sha256', 'webroot/css/pcm.css'), PHP_EOL;"
php -r "echo filemtime('webroot/css/pcm.css'), PHP_EOL;"
```

Linux, se for o sistema utilizado na hospedagem:

```sh
cd '/CAMINHO_REAL_DA_INSTALACAO_PCM_OrdensServico'
php -l templates/layout/default.php
php -l templates/Pcm/sector_protheus.php
php -r "echo hash_file('sha256', 'webroot/css/pcm.css'), PHP_EOL;"
php -r "echo filemtime('webroot/css/pcm.css'), PHP_EOL;"
```

Usar o executável PHP correspondente a essa instalação se `php` não estiver no PATH. O hash esperado do CSS nesta revisão é:

```text
5eec05fc6fe9d2c30b71b556423adaf4937b682ae5426e5f00e3dee680b64730
```

No HTML, esperar `/css/pcm.css?NUMERO`, com NUMERO igual ao `filemtime` retornado NA INSTALAÇÃO DO SERVIDOR. Conferir também que os outros sete assets possuem versão.

### Cache

Não é necessário limpar o cache do CakePHP para acrescentar versão aos assets. O projeto não configura cache de HTML dessa página; os caches comuns de metadata/core não armazenam esse CSS. Não executar `cache clear_all`, apagar `tmp`, limpar sessões ou sobrescrever configuração. Caso seja necessário apenas listar configurações, o comando de leitura é `php bin/cake.php cache list`, na mesma raiz da aplicação.

Depois de publicar, abrir DevTools > Network > Disable cache e recarregar a página autenticada (Ctrl+F5). Desativar a opção depois de validar. A consulta numérica nova faz o navegador solicitar o arquivo versionado.

Se o HTML continuar sem versão mesmo com os templates corretos em disco, verificar se a requisição está chegando a outra instalação/nó e se o PHP web retém OPcache antigo. Nesse caso o administrador deve reciclar somente o worker/pool PHP desta aplicação pelo mecanismo da hospedagem. Não se conhece o serviço/pool; não é seguro inventar comando de reinício. `php -r 'opcache_reset();'` no CLI não limpa o OPcache do processo web. Nenhum reset/reinício foi executado. Se existir CDN/proxy que ignore query strings, invalidar somente os URLs afetados pelo procedimento do provedor, sem limpar outros sistemas.

## Validação na página autenticada real

1. Entrar manualmente em local e produção e abrir o mesmo setor, com os mesmos filtros e tema. Não compartilhar credenciais.
2. Em ambos, usar modo responsivo com viewport 1440 × 1000 e zoom 100%. Repetir com 768 × 1000 e 390 × 1000.
3. Network: confirmar CSS/JS HTTP 200, conteúdo correto e URLs com versão. Não basta uma resposta 200 que contenha HTML de login.
4. Elements: confirmar `form[data-sector-filter-form].pcm-sector-filter-fields`, `.pcm-sector-filter-panel`, `.pcm-sector-equipment-anchor` e `.pcm-sector-filter-actions`. Computed: em desktop, `display: grid`, quatro colunas (a última contém os dois botões) e controles com a mesma altura.
5. Confirmar ordem Todas | Fábrica | Usina | Adm | Externo | Setores, em linha superior, e filtros abaixo.
6. Verificar sugestões curtas/longas, rolagem, seleção por mouse/teclado, Escape, clique fora, recarga e navegação automática por unidade, preservando GET.
7. Comparar capturas dos dois ambientes na mesma resolução. Se houver diferença, registrar o CSS vencedor em Computed e as classes do formulário antes de qualquer nova alteração.

No Console, este diagnóstico é somente de leitura e ajuda a comparar as duas páginas:

```js
(() => {
  const form = document.querySelector('[data-sector-filter-form]');
  return {
    viewport: innerWidth,
    formClass: form?.className,
    display: form && getComputedStyle(form).display,
    columns: form && getComputedStyle(form).gridTemplateColumns,
    assets: [...document.querySelectorAll('link[rel="stylesheet"], script[src]')]
      .map(el => el.href || el.src),
    controls: form && [...form.querySelectorAll('.form-control, .pcm-sector-filter-actions .btn')]
      .map(el => { const r = el.getBoundingClientRect(); return {
        name: el.name || el.textContent.trim(), x: r.x, y: r.y,
        width: r.width, height: r.height
      }; })
  };
})()
```

## Evidências locais

Validação concluída: 273 testes PHP, 5.613 asserções; 21 testes JavaScript; cenários de navegador de autocomplete e troca automática de unidade aprovados. O teste de integração confirmou versionamento dos oito assets com `debug=false`. Verificações de sintaxe dos dois templates e `git diff --check` aprovadas. A captura desktop do teste com assets públicos de produção também foi inspecionada visualmente.

- `tmp/sector-production-comparison.json`: hashes dos oito assets, headers HTTP e medidas do teste controlado.
- `tmp/compare-layout-local-*.png` e `tmp/compare-layout-production-assets-*.png`: capturas com o mesmo HTML.
- `tmp/compare-sector-assets.cjs`: comparação controlada de assets/layout.
- `tmp/asset-version-tests.xml`: teste de versionamento com debug desativado.
- `tmp/layout-publication-tests.xml`: suíte PHP completa.

As evidências em `tmp` são locais e não devem ser publicadas.
