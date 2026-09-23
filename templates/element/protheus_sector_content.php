<?php
$labels = ['safra_open' => 'Safra — O.S. em aberto', 'safra_completed' => 'Safra — O.S. fechadas',
    'offseason_open' => 'Entressafra — O.S. em aberto', 'offseason_completed' => 'Entressafra — O.S. fechadas'];
$value = static fn ($value) => $value === null || $value === '' ? '—' : (string)$value;
$url = fn (int $page) => ['_name' => 'pcm-sector', 'code' => $sector['code'], '?' => $sector['filters'] + ['page' => $page, 'limit' => $sector['limit']]];
$cardUrl = fn (string $category, string $status) => ['_name' => 'pcm-sector', 'code' => $sector['code'],
    '?' => array_replace($sector['filters'], ['card' => $category, 'card_status' => $status, 'page' => 1, 'limit' => $sector['limit']]), '#' => 'orders'];
?>
<section class="pcm-dashboard-section"><h2>Resumo operacional do setor</h2><div class="row g-3">
<?php foreach (['total' => 'Total operacional', 'open' => 'O.S. abertas', 'closed' => 'O.S. fechadas'] as $key => $label): ?>
<div class="col-md-4"><a class="pcm-kpi-card text-decoration-none" href="<?= h($this->Url->build($cardUrl('all', ['open' => 'EM ABERTO', 'closed' => 'FECHADA'][$key] ?? ''))) ?>">
<p class="pcm-kpi-label"><?= h($label) ?></p><strong class="pcm-kpi-value"><?= h(number_format($sector['operational'][$key], 0, ',', '.')) ?></strong></a></div>
<?php endforeach; ?></div></section>
<section class="pcm-dashboard-section"><h2>Detalhamento por classificação</h2>
<p class="text-body-secondary">Detalhamentos por serviço podem se sobrepor aos tipos. Não são somados ao Total operacional.</p>
<div class="row g-3">
<?php foreach (\App\Service\Protheus\ProtheusSectorService::CATEGORIES as $category => $label): ?>
<div class="col-md-6 col-xl-4"><article class="pcm-kpi-card"><h3 class="h5"><?= h($label) ?></h3><div class="d-flex gap-4">
<?php foreach (['open' => 'Abertas', 'closed' => 'Fechadas'] as $state => $text): ?>
<?= $this->Html->link($text . ': ' . number_format($sector['breakdown'][$category][$state], 0, ',', '.'),
    $cardUrl($category, $state === 'open' ? 'EM ABERTO' : 'FECHADA'), ['class' => 'btn btn-outline-primary']) ?>
<?php endforeach; ?></div></article></div>
<?php endforeach; ?></div></section>
<h2>Safra / Entressafra</h2>
<div class="row g-3 mt-3">
<?php foreach ($labels as $key => $label): ?><div class="<?= str_starts_with($key, 'safra_') || str_starts_with($key, 'offseason_') ? 'col-sm-6' : 'col-sm-4' ?>">
<?php [$season, $state] = explode('_', $key, 2); ?>
<a class="pcm-kpi-card text-decoration-none" href="<?= h($this->Url->build($cardUrl($season, $state === 'open' ? 'EM ABERTO' : 'FECHADA'))) ?>"><p class="pcm-kpi-label"><?= h($label) ?></p><strong class="pcm-kpi-value"><?= h(number_format($sector['cards'][$key], 0, ',', '.')) ?></strong></a>
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
<section class="pcm-dashboard-section" id="orders"><h2>Ordens de Serviço</h2>
<?php if ($sector['filters']['card'] !== '' || $sector['filters']['card_status'] !== ''): ?>
<p class="alert alert-secondary">Atalho aplicado:
<?= h((\App\Service\Protheus\ProtheusSectorService::CATEGORIES + ['all' => 'Total operacional', 'safra' => 'Safra', 'offseason' => 'Entressafra'])[$sector['filters']['card']] ?? 'Status') ?>
<?= h($sector['filters']['card_status']) ?>.
<?= $this->Html->link('Limpar filtro do card', $cardUrl('', ''), ['class' => 'alert-link']) ?>
<small class="d-block">Os filtros manuais e o período da tabela também são respeitados. Cards e gráficos mantêm o conjunto de referência.</small></p>
<?php endif; ?>
<div class="pcm-panel"><div class="pcm-sector-table-scroll" role="region" aria-label="Ordens de Serviço — rolagem horizontal" tabindex="0"><table class="table pcm-orders-table align-middle">
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
</tbody></table></div><footer class="pcm-pagination"><span>Página <?= h($sector['page']) ?></span>
<?php if ($sector['page'] > 1): ?><?= $this->Html->link('Anterior', $url($sector['page'] - 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
<?php if ($sector['has_more']): ?><?= $this->Html->link('Ver mais', $url($sector['page'] + 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
</footer></div></section>
