<?php
/** Shared by sector and general dashboards; links reuse the scoped order table. */
if (empty($checklists['visible'])) return;
$notes = ['route_on_time' => 'Dentro do prazo de acompanhamento',
    'route_attention' => 'Exige atenção do encarregado', 'route_critical' => 'Atraso crítico para análise'];
$colors = ['route_on_time' => 'success', 'route_attention' => 'warning', 'route_critical' => 'danger'];
$ranges = \App\Service\Protheus\RouteChecklist::ranges($checklists['area'] ?? '');
?>
<section class="pcm-dashboard-section" aria-label="Checklists das rotas em aberto">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
<h2 class="mb-0">Checklists das rotas em aberto</h2>
<?= $this->Html->link('Total em aberto: ' . number_format($checklists['total'], 0, ',', '.'), $checklistUrl('route_all'), ['class' => 'btn btn-outline-info']) ?>
</div>
<p class="text-body-secondary">Rotas da Fábrica. Idade em dias desde a origem da O.S. até <?= h($checklists['as_of']) ?>. As cores indicam acompanhamento e não alteram a situação no TOTVS.</p>
<div class="row g-3">
<?php foreach (\App\Service\Protheus\RouteChecklist::AGES as $key => $label):
    if (!isset($notes[$key]) && $checklists['ages'][$key] === 0) continue;
    $color = $colors[$key] ?? 'secondary'; ?>
<div class="col-12 col-md-4"><a class="pcm-kpi-card flex-column gap-3 p-4 h-100 text-decoration-none border border-<?= h($color) ?>" href="<?= $this->Url->build($checklistUrl($key)) ?>">
<p class="pcm-kpi-label text-<?= h($color) ?>"><?= h($label) ?></p>
<?php if (isset($ranges[$key])): ?><p class="small mb-0"><?= h($ranges[$key]) ?></p><?php endif; ?>
<strong class="pcm-kpi-value"><?= h(number_format($checklists['ages'][$key], 0, ',', '.')) ?></strong>
<p class="small text-body-secondary mb-0"><?= h($notes[$key] ?? 'Confira a data de origem cadastrada') ?></p>
</a></div>
<?php endforeach; ?>
</div></section>
