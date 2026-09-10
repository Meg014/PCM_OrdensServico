<?php
/**
 * @var \App\View\AppView $this
 * @var list<\App\Model\Entity\MaintenanceArea>|null $navigationAreas
 */
$navigationAreas ??= [];
$title = trim($this->fetch('title')) ?: 'PCM';
?>
<!doctype html>
<html lang="pt-BR" data-theme="dark" data-bs-theme="dark">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title><?= h($title) ?> | PCM</title>
    <?= $this->Html->meta('icon') ?>
    <script>
        (() => {
            try {
                const savedTheme = localStorage.getItem('pcm-theme');
                const theme = savedTheme === 'light' ? 'light' : 'dark';
                document.documentElement.dataset.theme = theme;
                document.documentElement.dataset.bsTheme = theme;
            } catch (_error) {
                document.documentElement.dataset.theme = 'dark';
                document.documentElement.dataset.bsTheme = 'dark';
            }
        })();
    </script>
    <?= $this->Html->css(['bootstrap.min', 'pcm']) ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
</head>
<body>
    <nav class="navbar navbar-expand-lg pcm-navbar">
        <div class="container-xl">
            <?= $this->Html->link('PCM', ['_name' => 'pcm'], ['class' => 'navbar-brand fw-bold']) ?>
            <div class="d-flex align-items-center gap-2 order-lg-3">
                <button class="pcm-theme-toggle" type="button" data-pcm-theme-toggle
                        title="Ativar tema claro" aria-label="Ativar tema claro">
                    <span data-pcm-theme-icon aria-hidden="true">☀</span>
                </button>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pcmNavigation"
                        aria-controls="pcmNavigation" aria-expanded="false" aria-label="Abrir navegação">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>
            <div class="collapse navbar-collapse" id="pcmNavigation">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item">
                        <?= $this->Html->link('PCM Geral', ['_name' => 'pcm'], ['class' => 'nav-link']) ?>
                    </li>
                    <li class="nav-item">
                        <?= $this->Html->link('Análises', ['_name' => 'pcm-analyses'], ['class' => 'nav-link']) ?>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                           aria-expanded="false">Setores</a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($navigationAreas === []) : ?>
                                <li><span class="dropdown-item-text text-body-secondary">Nenhum setor disponível</span></li>
                            <?php else : ?>
                                <?php foreach ($navigationAreas as $navigationArea) : ?>
                                    <li>
                                        <?= $this->Html->link(
                                            $navigationArea->display_name,
                                            ['_name' => 'pcm-sector', 'code' => $navigationArea->source_code],
                                            ['class' => 'dropdown-item'],
                                        ) ?>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <?= $this->Html->link(
                            'Importações',
                            ['controller' => 'ReportImports', 'action' => 'index'],
                            ['class' => 'nav-link'],
                        ) ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <main class="pcm-main">
        <div class="container-xl">
            <?= $this->Flash->render() ?>
            <?= $this->fetch('content') ?>
        </div>
    </main>
    <?= $this->Html->script('bootstrap.bundle.min') ?>
    <?= $this->Html->script('pcm-theme') ?>
    <?= $this->fetch('script') ?>
</body>
</html>
