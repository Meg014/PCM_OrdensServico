<!doctype html>
<html lang="pt-BR" data-bs-theme="light"><head>
<?= $this->Html->charset() ?><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow,noarchive">
<title>Consulta de Não Conformidades | Biolasa PCM</title>
<?= $this->Html->css(['bootstrap.min', 'pcm', 'pcm-nonconformities']) ?>
</head><body><main class="pcm-nc-container py-4">
<header class="mb-4"><strong>BIOLASA | PCM</strong><h1 class="h3 mt-2">Consulta de Não Conformidades</h1>
<p>Consulta somente para leitura da ação indicada no seu e-mail. Não é necessário login.</p></header>
<?= $this->fetch('content') ?>
</main></body></html>
