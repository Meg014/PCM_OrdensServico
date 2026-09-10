<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, int|float> $indicators
 * @var bool|null $showCancelled
 * @var bool|null $largeCards
 */
$showCancelled ??= false;
$largeCards ??= false;
$cards = [
    ['key' => 'open', 'label' => 'OS Em Aberto', 'value' => number_format((int)$indicators['open'], 0, ',', '.'), 'tone' => 'total', 'icon' => 'A'],
    ['key' => 'completed', 'label' => 'OS Fechadas', 'value' => number_format((int)$indicators['completed'], 0, ',', '.'), 'tone' => 'completed', 'icon' => 'F'],
];
// Audit counts are opt-in; operational dashboards do not display this card.
if ($showCancelled) {
    $cards[] = ['key' => 'cancelled', 'label' => 'OS Canceladas', 'value' => number_format((int)$indicators['cancelled'], 0, ',', '.'), 'tone' => 'cancelled', 'icon' => 'C'];
}
?>
<div class="row g-4<?= $largeCards ? ' pcm-kpi-grid-large' : '' ?>" aria-label="Indicadores do snapshot atual">
    <?php foreach ($cards as $card) : ?>
        <div class="col-12 col-sm-6 <?= count($cards) === 2 ? 'col-xl-6' : 'col-xl-4' ?>">
            <article class="pcm-kpi-card pcm-kpi-<?= h($card['tone']) ?>">
                <div>
                    <p class="pcm-kpi-label"><?= h($card['label']) ?></p>
                    <p class="pcm-kpi-value"><?= h($card['value']) ?></p>
                    <?php if (isset($comparison['delta'][$card['key']])) : ?>
                        <?php $delta = (float)$comparison['delta'][$card['key']]; ?>
                        <p class="pcm-kpi-delta"><?= $delta > 0 ? '+' : '' ?><?= h(number_format($delta, 0, ',', '.')) ?> vs relatório anterior</p>
                    <?php endif; ?>
                </div>
                <span class="pcm-kpi-icon" aria-hidden="true"><?= h($card['icon']) ?></span>
            </article>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 pcm-maintenance-type-cards" aria-label="Tipos de manutenção das OS em aberto">
    <?php foreach (
        [
            ['preventive', 'Preventivas', 'PRE'],
            ['corrective', 'Corretivas', 'COR'],
            ['improvement', 'Melhorias', 'MEL'],
        ] as [$key, $label, $code]
) : ?>
        <div class="col-12 col-sm-4">
            <article class="pcm-maintenance-type-card pcm-maintenance-type-<?= h(strtolower($code)) ?>">
                <div>
                    <p><?= h($label) ?></p>
                    <small>OS em aberto · Tipo Manut. <?= h($code) ?></small>
                </div>
                <strong><?= number_format((int)($indicators[$key] ?? 0), 0, ',', '.') ?></strong>
            </article>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->element('pcm_service_cards', ['indicators' => $indicators,
    'cardRoute' => $cardRoute ?? ['_name' => 'pcm-orders'], 'filters' => $filters ?? []]) ?>
