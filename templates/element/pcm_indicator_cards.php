<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, int|float> $indicators
 * @var bool|null $showCancelled
 * @var bool|null $largeCards
 */
$showCancelled ??= false;
$largeCards ??= false;
$cardRoute ??= ['_name' => 'pcm-orders-legacy'];
$filters ??= [];
$cards = [];
foreach (['safra' => 'Safra', 'offseason' => 'Entressafra'] as $season => $label) {
    foreach (['open' => 'O.S. Em Aberto', 'completed' => 'O.S. Fechadas'] as $status => $statusLabel) {
        $key = $season . '_' . $status;
        $cards[] = ['key' => $key, 'label' => $label . ' — ' . $statusLabel,
            'value' => number_format((int)$indicators[$key], 0, ',', '.'),
            'tone' => $status === 'open' ? 'total' : 'completed',
            'filters' => \App\Service\PcmIndicatorService::drilldownFilters($filters, $key)];
    }
}
?>
<div class="row g-3<?= $largeCards ? ' pcm-kpi-grid-large' : '' ?>" aria-label="Indicadores do snapshot atual">
    <?php foreach ($cards as $card) : ?>
        <div class="col-12 col-sm-6 col-xl-6">
            <a href="<?= h($this->Url->build($cardRoute + ['?' => $card['filters'], '#' => 'orders'])) ?>" class="text-decoration-none pcm-kpi-card pcm-kpi-<?= h($card['tone']) ?>">
                <div>
                    <p class="pcm-kpi-label"><?= h($card['label']) ?></p>
                    <p class="pcm-kpi-value"><?= h($card['value']) ?></p>
                    <?php if (isset($comparison['delta'][$card['key']])) : ?>
                        <?php $delta = (float)$comparison['delta'][$card['key']]; ?>
                        <p class="pcm-kpi-delta"><?= $delta > 0 ? '+' : '' ?><?= h(number_format($delta, 0, ',', '.')) ?> vs relatório anterior</p>
                    <?php endif; ?>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 pcm-maintenance-type-cards" aria-label="Tipos de manutenção das O.S. em aberto">
    <?php foreach (
        [
            ['preventive', 'Preventivas', 'PRE'],
            ['corrective', 'Corretivas', 'COR'],
            ['improvement', 'Melhorias', 'MEL'],
        ] as [$key, $label, $code]
) : ?>
        <div class="col-12 col-sm-4">
            <a href="<?= h($this->Url->build($cardRoute + ['?' => \App\Service\PcmIndicatorService::drilldownFilters($filters, $key), '#' => 'orders'])) ?>" class="text-decoration-none pcm-maintenance-type-card pcm-maintenance-type-<?= h(strtolower($code)) ?>">
                <div>
                    <p><?= h($label) ?></p>
                    <small>O.S. em aberto · Tipo Manut. <?= h($code) ?></small>
                </div>
                <strong><?= number_format((int)($indicators[$key] ?? 0), 0, ',', '.') ?></strong>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->element('pcm_service_cards', ['indicators' => $indicators,
    'cardRoute' => $cardRoute ?? ['_name' => 'pcm-orders-legacy'], 'filters' => $filters ?? []]) ?>
