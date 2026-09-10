<?php
$this->assign('title', 'Análises');
$this->Html->script(['chart.umd.min', 'pcm-history'], ['block' => true]);
?>
<header class="pcm-page-header">
    <div>
        <p class="pcm-eyebrow">PCM | ANÁLISES</p>
        <h1>Visão Gerencial</h1>
        <p class="pcm-updated"><?= $this->element('pcm_updated_at', compact('lastUpdatedAt')) ?></p>
    </div>
</header>

<?= $this->element('pcm_data_quality', compact('dataQuality')) ?>
<?= $this->element('pcm_history', compact('history')) ?>
<?= $this->element('pcm_sector_comparison', compact('sectorComparison')) ?>
