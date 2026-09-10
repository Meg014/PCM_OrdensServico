<?php
/**
 * @var \App\View\AppView $this
 * @var array $payload
 * @var \DateTimeInterface|null $lastUpdatedAt
 */
$this->assign('title', 'Modo Apresentação');
$this->Html->script('pcm-presentation', ['block' => true]);
$firstScreen = $payload['screens'][0];
?>
<section
    class="pcm-presentation"
    data-pcm-presentation
    data-presentation-url="<?= h($this->Url->build(['_name' => 'pcm-presentation-data'])) ?>"
    data-exit-url="<?= h($this->Url->build(['_name' => 'pcm'])) ?>"
>
    <header class="pcm-presentation-header">
        <div>
            <p class="pcm-eyebrow">MODO APRESENTAÇÃO</p>
            <h1 data-presentation-title><?= h($firstScreen['title']) ?></h1>
            <p class="pcm-updated" data-presentation-updated>
                <?= $this->element('pcm_updated_at', compact('lastUpdatedAt')) ?>
            </p>
        </div>
        <button class="btn btn-outline-secondary" type="button" data-presentation-exit>Sair da apresentação</button>
    </header>

    <div class="pcm-presentation-cards" aria-live="polite">
        <article class="pcm-presentation-card pcm-presentation-open">
            <p>OS EM ABERTO</p>
            <strong data-presentation-open><?= number_format((int)$firstScreen['open'], 0, ',', '.') ?></strong>
        </article>
        <article class="pcm-presentation-card pcm-presentation-completed">
            <p>OS FECHADAS</p>
            <strong data-presentation-completed><?= number_format((int)$firstScreen['completed'], 0, ',', '.') ?></strong>
        </article>
    </div>

    <div class="pcm-presentation-type-cards" aria-label="Tipos de manutenção das OS em aberto">
        <article><p>PREVENTIVAS</p><small>OS em aberto · PRE</small><strong data-presentation-preventive><?= number_format((int)$firstScreen['preventive'], 0, ',', '.') ?></strong></article>
        <article><p>CORRETIVAS</p><small>OS em aberto · COR</small><strong data-presentation-corrective><?= number_format((int)$firstScreen['corrective'], 0, ',', '.') ?></strong></article>
        <article><p>MELHORIAS</p><small>OS em aberto · MEL</small><strong data-presentation-improvement><?= number_format((int)$firstScreen['improvement'], 0, ',', '.') ?></strong></article>
    </div>

    <div class="pcm-presentation-type-cards" aria-label="Classificações de serviço das OS em aberto">
        <?php foreach (\App\Service\PcmServiceClassifier::CARD_CLASSES as $key => $classification): ?>
        <article><p><?= h(mb_strtoupper(\App\Service\PcmServiceClassifier::LABELS[$classification])) ?></p><small>OS em aberto</small><strong data-presentation-<?= h($key) ?>><?= number_format((int)$firstScreen[$key], 0, ',', '.') ?></strong></article>
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
