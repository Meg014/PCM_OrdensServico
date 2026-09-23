<?php
/**
 * @var \App\View\AppView $this
 * @var array $payload
 * @var \DateTimeInterface|null $lastUpdatedAt
 */
$this->assign('title', 'Modo Apresentação');
$this->Html->script('pcm-presentation', ['block' => true]);
$isTv = isset($currentUser) && $currentUser->role === 'TV';
$firstScreen = $payload['screens'][0];
?>
<section
    class="pcm-presentation"
    data-pcm-presentation
    data-presentation-url="<?= h($this->Url->build(['_name' => 'pcm-presentation-legacy-data'])) ?>"
    <?php if (!$isTv): ?>data-exit-url="<?= h($this->Url->build(['_name' => 'pcm'])) ?>"<?php endif; ?>
>
    <header class="pcm-presentation-header">
        <span class="badge text-bg-secondary">Legado Excel — última importação</span>
        <div>
            <h1 data-presentation-title><?= h($firstScreen['title']) ?></h1>
            <p class="pcm-updated" data-presentation-updated>
                <?= $this->element('pcm_updated_at', compact('lastUpdatedAt')) ?>
            </p>
        </div>
        <?php if (!$isTv): ?><button class="btn btn-outline-secondary" type="button" data-presentation-exit>Sair da apresentação</button>
        <?php else: ?>
        <?= $this->Form->postLink('Logout', '/logout', ['class' => 'btn btn-sm btn-outline-secondary']) ?>
        <?php endif; ?>
    </header>

    <div class="pcm-presentation-cards" aria-live="polite">
        <?php foreach (['safra' => 'SAFRA', 'offseason' => 'ENTRESSAFRA'] as $season => $label): ?>
        <?php foreach (['open' => 'O.S. EM ABERTO', 'completed' => 'O.S. FECHADAS'] as $status => $statusLabel): $key = $season . '_' . $status; ?>
        <article class="pcm-presentation-card pcm-presentation-<?= h($status) ?>">
            <p><?= h($label . ' — ' . $statusLabel) ?></p>
            <strong data-presentation-<?= h($key) ?>><?= number_format((int)$firstScreen[$key], 0, ',', '.') ?></strong>
        </article>
        <?php endforeach; endforeach; ?>
    </div>

    <div class="pcm-presentation-type-cards" aria-label="Tipos de manutenção das O.S. em aberto">
        <article><p>PREVENTIVAS</p><small>O.S. em aberto · PRE</small><strong data-presentation-preventive><?= number_format((int)$firstScreen['preventive'], 0, ',', '.') ?></strong></article>
        <article><p>CORRETIVAS</p><small>O.S. em aberto · COR</small><strong data-presentation-corrective><?= number_format((int)$firstScreen['corrective'], 0, ',', '.') ?></strong></article>
        <article><p>MELHORIAS</p><small>O.S. em aberto · MEL</small><strong data-presentation-improvement><?= number_format((int)$firstScreen['improvement'], 0, ',', '.') ?></strong></article>
    </div>

    <div class="pcm-presentation-type-cards pcm-presentation-service-cards" aria-label="Classificações de serviço das O.S. em aberto">
        <?php foreach (array_diff_key(\App\Service\PcmServiceClassifier::CARD_CLASSES, ['offseason' => true]) as $key => $classification): ?>
        <article><p><?= h(mb_strtoupper(\App\Service\PcmServiceClassifier::LABELS[$classification])) ?></p><small>O.S. em aberto</small><strong data-presentation-<?= h($key) ?>><?= number_format((int)$firstScreen[$key], 0, ',', '.') ?></strong></article>
        <?php endforeach; ?>
    </div>

    <footer class="pcm-presentation-footer">
        <span data-presentation-position>Tela 1 de <?= count($payload['screens']) ?></span>
        <span>Próxima tela em <strong data-presentation-countdown>15</strong>s</span>
    </footer>
</section>
<script type="application/json" data-presentation-payload><?= json_encode(
    $payload,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
) ?></script>
