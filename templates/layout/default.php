<?php
/**
 * @var \App\View\AppView $this
 * @var list<\App\Model\Entity\MaintenanceArea>|null $navigationAreas
 */
$navigationAreas ??= [];
$isTv = isset($currentUser) && $currentUser->role === 'TV';
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
    <?php if (empty($presentation)): ?>
    <nav class="navbar navbar-expand-lg pcm-navbar">
        <div class="container-xl">
            <?= $this->Html->link('PCM', ['_name' => $isTv ? 'pcm-presentation' : 'pcm'], ['class' => 'navbar-brand fw-bold']) ?>
            <div class="d-flex align-items-center gap-2 order-lg-3">
                <?php if (isset($currentUser)): ?>
                    <span class="small text-body-secondary"><?= h($currentUser->nome) ?></span>
                    <?= $this->Form->postLink('Sair', '/logout', ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                <?php endif; ?>
                <button class="pcm-theme-toggle" type="button" data-pcm-theme-toggle
                        title="Ativar tema claro" aria-label="Ativar tema claro">
                    <span data-pcm-theme-icon aria-hidden="true">☀</span>
                </button>
                <?php if (!$isTv): ?><button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pcmNavigation"
                        aria-controls="pcmNavigation" aria-expanded="false" aria-label="Abrir navegação">
                    <span class="navbar-toggler-icon"></span>
                </button><?php endif; ?>
            </div>
            <?php if (isset($currentUser) && !$isTv): ?>
            <div class="collapse navbar-collapse" id="pcmNavigation">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item"><?= $this->Html->link('Ordens de Serviço', ['_name' => 'pcm-orders'], ['class' => 'nav-link']) ?></li>
                    <?php if ($currentUser->role === 'ADMIN'): ?>
                        <li class="nav-item"><?= $this->Html->link('Usuários', '/usuarios', ['class' => 'nav-link']) ?></li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <?= $this->Html->link('PCM Geral', ['_name' => 'pcm'], ['class' => 'nav-link']) ?>
                    </li>
                    <li class="nav-item dropdown" data-sectors-menu data-url="<?= h($this->Url->build(['_name' => 'pcm-sector-options'])) ?>">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                           aria-expanded="false">Setores</a>
                        <ul class="dropdown-menu dropdown-menu-end" data-sectors-items aria-live="polite">
                            <li><span class="dropdown-item-text text-body-secondary">Abra para consultar os setores.</span></li>
                        </ul>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
    <main class="pcm-main">
        <div class="container-xl">
            <?= $this->Flash->render() ?>
            <?= $this->fetch('content') ?>
        </div>
    </main>
    <?= $this->Html->script('bootstrap.bundle.min') ?>
    <?= $this->Html->script('pcm-theme') ?>
    <?= $this->Html->script('pcm-sectors-menu', ['defer' => true]) ?>
    <?= $this->fetch('script') ?>
</body>
</html>
