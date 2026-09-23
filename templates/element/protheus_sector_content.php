<?php
$labels = ['safra_open' => 'Safra — O.S. em aberto', 'safra_completed' => 'Safra — O.S. fechadas',
    'offseason_open' => 'Entressafra — O.S. em aberto', 'offseason_completed' => 'Entressafra — O.S. fechadas',
    'preventive' => 'Preventivas', 'corrective' => 'Corretivas', 'improvement' => 'Melhorias',
    'emergency' => 'Corretivas Emergenciais', 'scheduled' => 'Corretivas Programadas'];
$value = static fn ($value) => $value === null || $value === '' ? '—' : (string)$value;
$url = fn (int $page) => ['_name' => 'pcm-sector', 'code' => $sector['code'], '?' => $sector['filters'] + ['page' => $page, 'limit' => $sector['limit']]];
?>
<div class="row g-3 mt-3">
<?php foreach ($labels as $key => $label): ?><div class="<?= str_starts_with($key, 'safra_') || str_starts_with($key, 'offseason_') ? 'col-sm-6' : 'col-sm-4' ?>">
<article class="pcm-kpi-card"><p class="pcm-kpi-label"><?= h($label) ?></p><strong class="pcm-kpi-value"><?= h(number_format($sector['cards'][$key], 0, ',', '.')) ?></strong></article>
</div><?php endforeach; ?></div>
<section class="pcm-dashboard-section"><div class="pcm-section-title"><h2>Situação da carteira</h2></div><div class="row g-4">
<?php foreach (['status' => 'Distribuição por status', 'maintenance' => 'Perfil de manutenção', 'equipment' => 'Top 10 equipamentos',
    'services' => 'Top 10 serviços', 'costCenters' => 'Ranking de centros de custo'] as $key => $label): ?>
<div class="col-lg-6"><div class="pcm-chart-card pcm-chart-card-tall"><h3><?= h($label) ?></h3><div class="pcm-chart-wrap"><canvas data-sector-chart="<?= h($key) ?>"></canvas></div></div></div>
<?php endforeach; ?></div></section>
<section class="pcm-dashboard-section"><h2>Pontos de atenção</h2><div class="row g-3">
<div class="col-md-4 pcm-attention-card"><span>Sem início real informado</span><strong><?= h($sector['missing_start']) ?></strong></div>
<?php foreach (['equipment' => 'Equipamento mais recorrente', 'services' => 'Serviço mais recorrente'] as $key => $label): ?>
<div class="col-md-4 pcm-attention-card"><span><?= h($label) ?></span><strong class="pcm-attention-name"><?= h($sector['charts'][$key][0]['label'] ?? '—') ?></strong></div>
<?php endforeach; ?></div></section>
<section class="pcm-dashboard-section" id="orders"><h2>Ordens de Serviço</h2><div class="pcm-panel table-responsive"><table class="table pcm-orders-table align-middle">
<thead><tr><?php foreach (['Filial / OS', 'Equipamento', 'Serviço', 'Centro de custo', 'Tipo', 'Situação / término', 'Início planejado', 'Início real', 'Status'] as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($sector['orders'] as $row): ?><tr>
<td><?= h($value($row['TJ_FILIAL'])) ?> /
<?= $this->Html->link($row['TJ_ORDEM'], ['_name' => 'pcm-protheus-order', 'number' => $row['TJ_ORDEM'], '?' => ['filial' => $row['TJ_FILIAL']]]) ?></td>
<td><?= h($value($row['TJ_CODBEM'])) ?><br><?= h($value($row['equipment_name'])) ?></td>
<td><?= h($value($row['TJ_SERVICO'])) ?><br><?= h($value($row['service_name'])) ?></td>
<td><?= h($value($row['TJ_CCUSTO'])) ?></td><td><?= h($value($row['TJ_TIPO'])) ?></td>
<td><?= h($value($row['TJ_SITUACA'])) ?> / <?= h($value($row['TJ_TERMINO'])) ?></td>
<td><?= h($value($row['planned_date'])) ?> <?= h($value($row['TJ_HOMPINI'])) ?></td>
<td><?= h($value((new \App\Service\Protheus\Presentation\OrderSupplementMapper())->date($row['TJ_DTPRINI']))) ?> <?= h($value($row['TJ_HOPRINI'])) ?></td>
<td><span class="badge pcm-status-badge"><?= h($row['status']) ?></span></td></tr><?php endforeach; ?>
<?php if (!$sector['orders']): ?><tr><td colspan="9">Nenhuma O.S. encontrada para os filtros aplicados.</td></tr><?php endif; ?>
</tbody></table><footer class="pcm-pagination"><span>Página <?= h($sector['page']) ?></span>
<?php if ($sector['page'] > 1): ?><?= $this->Html->link('Anterior', $url($sector['page'] - 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
<?php if ($sector['has_more']): ?><?= $this->Html->link('Ver mais', $url($sector['page'] + 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
</footer></div></section>
