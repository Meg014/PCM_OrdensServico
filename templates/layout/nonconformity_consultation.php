<!doctype html>
<html lang="pt-BR" data-theme="light" data-bs-theme="light"><head>
<?= $this->Html->charset() ?><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow,noarchive">
<title>Consulta de Não Conformidades | Biolasa PCM</title>
<?= $this->Html->meta('icon', '/img/pcm-favicon.png', ['type' => 'image/png']) ?>
<?= $this->Html->script('pcm-nc-consultation-theme', ['timestamp' => 'force']) ?>
<?= $this->Html->css(['bootstrap.min', 'pcm', 'pcm-nonconformities'], ['timestamp' => 'force']) ?>
</head><body class="pcm-nc-public"><main class="pcm-nc-container pcm-main">
<header class="pcm-page-header pcm-nc-public-header"><div class="pcm-nc-public-identity">
<?= $this->Html->image('pcm-favicon.png', ['alt' => '', 'width' => 48, 'height' => 48, 'class' => 'pcm-nc-public-logo']) ?>
<div><p class="pcm-eyebrow">BIOLASA | PCM · QUALIDADE</p><h1>Consulta de Não Conformidades</h1>
<p class="pcm-nc-subtitle">Consulta somente para leitura da ação indicada no seu e-mail. Não é necessário login.</p></div></div>
<span class="pcm-nc-public-access">Somente leitura</span></header>
<?= $this->fetch('content') ?>
</main></body></html>
