<?php
$reportDate = $dataQuality['reportDate'];
?>
<section class="pcm-dashboard-section pcm-quality-section" aria-labelledby="pcm-quality-title">
    <div class="pcm-section-title">
        <p>CONFERÊNCIA DA FONTE TOTVS</p>
        <h2 id="pcm-quality-title">QUALIDADE DOS DADOS</h2>
        <span>
            <?= $reportDate ? 'Qualidade dos dados referente a: ' . h($reportDate->format('d/m/Y')) : 'Nenhum snapshot disponível para análise.' ?>
        </span>
    </div>
    <div class="row g-3">
        <?php foreach ($dataQuality['indicators'] as $indicator): ?>
            <div class="col-12 col-sm-6 col-xl-4">
                <article class="pcm-quality-card">
                    <div class="pcm-quality-icon" aria-hidden="true">!</div>
                    <div>
                        <span><?= h($indicator['shortLabel']) ?></span>
                        <strong><?= number_format($indicator['count'], 0, ',', '.') ?> OS</strong>
                        <small><?= number_format($indicator['percentage'], 2, ',', '.') ?>% da base</small>
                    </div>
                    <?= $this->Html->link(
                        'Ver ocorrências',
                        ['_name' => 'pcm-data-quality', 'type' => $indicator['type']],
                        ['class' => 'stretched-link', 'aria-label' => 'Ver ' . $indicator['label']],
                    ) ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="pcm-quality-note">Os apontamentos servem para conferência da origem e não alteram as Ordens de Serviço.</p>
</section>
