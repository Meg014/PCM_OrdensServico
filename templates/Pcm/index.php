<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, int|float> $indicators
 * @var \App\Model\Entity\ReportImport|null $currentImport
 */
$this->assign('title', 'PCM Geral');
$this->Html->script('pcm-auto-refresh', ['block' => true]);
?>
<header class="pcm-page-header">
    <div>
        <p class="pcm-eyebrow">PCM | ORDENS DE SERVIÇO</p>
        <h1>Visão Geral</h1>
        <p class="pcm-updated">
            <?= $this->element('pcm_updated_at', compact('lastUpdatedAt')) ?>
            <span
                class="ms-2 text-muted"
                data-pcm-current-version
                data-current-import-id="<?= h($currentImport?->id ?? '') ?>"
                data-version-url="<?= h($this->Url->build(['_name' => 'pcm-current-version'])) ?>"
            > Atualização automática ativa</span>
        </p>
    </div>
    <div>
        <?= $this->Html->link(
            '▣ Modo Apresentação',
            ['_name' => 'pcm-presentation'],
            ['class' => 'btn btn-outline-primary', 'data-pcm-presentation-start' => true],
        ) ?>
    </div>
</header>

<?php if ($currentImport === null) : ?>
    <div class="alert alert-light border shadow-sm" role="status">
        Nenhum relatório foi importado com sucesso. Importe um XLSX para habilitar os indicadores.
    </div>
<?php endif; ?>

<?= $this->element('pcm_indicator_cards', [
    'indicators' => $indicators,
    'showCancelled' => false,
    'largeCards' => true,
]) ?>

<p class="mt-4"><?= $this->Html->link('Relatório completo — todas as áreas', ['_name' => 'pcm-orders'], ['class' => 'btn btn-outline-primary']) ?></p>
