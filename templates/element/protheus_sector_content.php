<?php
$labels = ['safra_open' => 'Safra — O.S. em aberto', 'safra_completed' => 'Safra — O.S. fechadas',
    'offseason_open' => 'Entressafra — O.S. em aberto', 'offseason_completed' => 'Entressafra — O.S. fechadas'];
$value = static fn ($value) => $value === null || $value === '' ? '—' : (string)$value;
$url = fn (int $page) => ['_name' => 'pcm-sector', 'code' => $sector['code'], '?' => $sector['filters'] + ['page' => $page, 'limit' => $sector['limit']]];
$cardUrl = fn (string $category, string $status) => ['_name' => 'pcm-sector', 'code' => $sector['code'],
    '?' => array_replace($sector['filters'], ['card' => $category, 'card_status' => $status, 'backlog_age' => '',
        'date_start' => '', 'date_end' => '', 'page' => 1, 'limit' => $sector['limit']]), '#' => 'orders'];
$backlogUrl = fn (string $age) => ['_name' => 'pcm-sector', 'code' => $sector['code'],
    '?' => array_replace($sector['filters'], ['card' => '', 'card_status' => '', 'backlog_age' => $age,
        'date_start' => '', 'date_end' => '',
        'page' => 1, 'limit' => $sector['limit']]), '#' => 'orders'];
$equipmentUrl = static fn (array $row): array => ['_name' => 'pcm-equipment', '?' => [
    'bem' => $row['key'], 'filial' => $row['branch'], 'setor' => $sector['code'], 'unit' => $sector['filters']['unit'],
]];
$orderFilters = static function (array $extra = []) use ($sector): array {
    $query = array_replace(array_filter([
        'filial' => $sector['filters']['filial'], 'area' => $sector['code'],
        'bem' => $sector['filters']['equipment'], 'servico' => $sector['filters']['service'],
        'service_name' => $sector['filters']['service_name'], 'centro' => $sector['filters']['cost_center'],
        'tipo' => $sector['filters']['maintenance_type'], 'q' => $sector['filters']['q'],
        'status' => $sector['filters']['status'],
        'unidade' => $sector['filters']['unit'], 'analitico' => '1', 'safra' => '1',
    ], static fn ($value) => $value !== ''), $extra);

    // Empty values from the sector form must never overwrite an active drill-down context.
    return array_filter($query, static fn ($value) => $value !== '');
};
$orderUrl = static fn (array $extra = []): array => ['_name' => 'pcm-orders', '?' => $orderFilters($extra)];
$cardOrderUrl = static function (string $card, string $status) use ($orderFilters): array {
    $query = $orderFilters(['card' => $card, 'card_status' => $status]);
    unset($query['safra']);
    return ['_name' => 'pcm-orders', '?' => $query];
};
$backlogOrderUrl = static fn (string $age): array => ['_name' => 'pcm-orders', '?' => $orderFilters(['backlog_age' => $age])];
$activeDrilldown = null;
if ($sector['filters']['backlog_age'] !== '') {
    $age = $sector['filters']['backlog_age'];
    $activeDrilldown = ['label' => 'Backlog · ' . ($age === 'all' ? 'Total de O.S. em aberto' : (
        \App\Service\Protheus\ProtheusSectorService::BACKLOG_AGES[$age] ?? $age)),
        'total' => $age === 'all' ? $sector['backlog']['total'] : $sector['backlog']['ages'][$age],
        'clear' => $backlogUrl('')];
} elseif (in_array($sector['filters']['card'], ['safra', 'offseason'], true) && $sector['filters']['card_status'] !== '') {
    $season = $sector['filters']['card'];
    $state = $sector['filters']['card_status'] === 'EM ABERTO' ? 'open' : 'completed';
    $activeDrilldown = ['label' => ($season === 'safra' ? 'Safra' : 'Entressafra') . ' · '
        . ($state === 'open' ? 'Em aberto' : 'Fechadas'), 'total' => $sector['cards'][$season . '_' . $state],
        'clear' => $cardUrl('', '')];
}
?>
<section class="pcm-dashboard-section"><h2>Safra / Entressafra</h2>
<p class="text-body-secondary">Comparativo informativo. As demais análises desta página consideram somente Safra.</p><div class="row g-3">
<?php foreach ($labels as $key => $label): ?><?php [$season, $state] = explode('_', $key, 2); ?><div class="col-sm-6"><a class="pcm-kpi-card pcm-season-card text-decoration-none" href="<?= $this->Url->build($cardOrderUrl($season, $state === 'open' ? 'EM ABERTO' : 'FECHADA')) ?>"><p class="pcm-kpi-label"><?= h($label) ?></p><strong class="pcm-kpi-value"><?= h(number_format($sector['cards'][$key], 0, ',', '.')) ?></strong></a></div><?php endforeach; ?>
</div></section>
<section class="pcm-dashboard-section"><h2>Backlog / O.S. em aberto</h2>
<p class="text-body-secondary">Abertas liberadas do setor, conforme os filtros manuais. Idade desde a origem da O.S. até <?= h($sector['backlog']['as_of']) ?>, sem corte de início planejado. O período planejado filtra somente a tabela.</p>
<div class="row g-3">
<?php foreach (['all' => 'Total de O.S. em aberto'] + \App\Service\Protheus\ProtheusSectorService::BACKLOG_AGES as $key => $label): ?>
<?php $quantity = $key === 'all' ? $sector['backlog']['total'] : $sector['backlog']['ages'][$key];
if (in_array($key, ['unknown', 'future'], true) && $quantity === 0) continue; ?>
<div class="col-sm-6 col-xl-4"><a class="pcm-kpi-card text-decoration-none" href="<?= $this->Url->build($backlogOrderUrl($key)) ?>">
<p class="pcm-kpi-label"><?= h($label) ?></p><strong class="pcm-kpi-value"><?= h(number_format($quantity, 0, ',', '.')) ?></strong></a></div>
<?php endforeach; ?></div>
<div class="row g-3 mt-2">
<?php foreach (['equipment' => 'Top 10 equipamentos por O.S.', 'costCenters' => 'Top 10 centros de custo por O.S.', 'maintenance' => 'O.S. por Tipo de Manutenção'] as $key => $label): ?>
<?php $rankingRows = $key === 'equipment' ? $sector['top_equipment'] : $sector['historical_rankings'][$key]; ?>
<div class="col-lg-4"><article class="pcm-panel p-3"><h3 class="h5"><?= h($label) ?></h3>
<ul class="list-group list-group-flush">
<?php foreach ($rankingRows as $row): ?>
<?php $rankingFilter = match ($key) { 'equipment' => ['filial' => $row['branch'], 'bem' => $row['key']], 'costCenters' => ['centro' => $row['key']], default => ['tipo' => $row['key']] }; ?>
<li class="list-group-item d-flex justify-content-between gap-2"><?= $this->Html->link($row['label'], $orderUrl($rankingFilter), ['class' => 'pcm-ranking-link']) ?><strong><?= h($row['quantity']) ?></strong></li>
<?php endforeach; ?>
<?php if (!$rankingRows): ?><li class="list-group-item text-body-secondary">Nenhuma O.S. encontrada.</li><?php endif; ?>
</ul></article></div>
<?php endforeach; ?></div></section>
<?php if ($sector['generic_equipment']): ?><section class="pcm-dashboard-section"><h2>Equipamentos genéricos</h2>
<p class="text-body-secondary">Agrupadores explícitos, mantidos fora dos rankings de equipamentos físicos.</p><div class="pcm-panel p-3"><ul class="list-group list-group-flush">
<?php foreach ($sector['generic_equipment'] as $row): ?><li class="list-group-item d-flex justify-content-between gap-3"><?= $this->Html->link($row['label'], $orderUrl(['filial' => $row['branch'], 'bem' => $row['key']]), ['class' => 'pcm-ranking-link']) ?><strong><?= h($row['quantity']) ?></strong></li><?php endforeach; ?>
</ul></div></section><?php endif; ?>
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
<section class="pcm-dashboard-section"><div class="pcm-section-title"><h2>Rankings da carteira</h2></div><div class="row g-4">
<?php foreach (['equipment' => ['Top 10 equipamentos', $sector['top_equipment']], 'services' => ['Top 10 serviços', $sector['rankings']['services']],
    'costCenters' => ['Ranking de centros de custo', $sector['historical_rankings']['costCenters']]] as $key => [$label, $rows]): ?>
<div class="col-lg-4"><article class="pcm-panel p-3 h-100"><h3 class="h5"><?= h($label) ?></h3><ul class="list-group list-group-flush mt-3">
<?php foreach ($rows as $row): ?>
<?php $rankingFilter = match ($key) { 'equipment' => ['filial' => $row['branch'], 'bem' => $row['key']], 'services' => ['filial' => $row['branch'], 'servico' => $row['key']], default => ['centro' => $row['key']] }; ?>
<li class="list-group-item d-flex justify-content-between gap-3 px-0"><?= $this->Html->link($row['label'], $orderUrl($rankingFilter), ['class' => 'pcm-ranking-link']) ?><strong><?= h($row['quantity']) ?></strong></li>
<?php endforeach; ?><?php if (!$rows): ?><li class="list-group-item text-body-secondary px-0">Nenhuma O.S. encontrada.</li><?php endif; ?>
</ul></article></div><?php endforeach; ?></div></section>
<section class="pcm-dashboard-section" id="orders"><h2>Ordens de Serviço</h2>
<?php if ($activeDrilldown !== null): ?>
<p class="alert alert-secondary pcm-active-drilldown"><strong>Filtro: <?= h($activeDrilldown['label']) ?> — <?= h(number_format($activeDrilldown['total'], 0, ',', '.')) ?> O.S.</strong>
<?= $this->Html->link('Limpar filtro do card', $activeDrilldown['clear'], ['class' => 'alert-link ms-2']) ?>
<small class="d-block">A tabela abaixo contém exclusivamente a população deste card; paginação e atualização automática preservam o filtro.</small></p>
<?php elseif ($sector['filters']['card'] !== '' || $sector['filters']['card_status'] !== ''): ?>
<p class="alert alert-secondary">Atalho aplicado:
<?= h((\App\Service\Protheus\ProtheusSectorService::CATEGORIES + ['all' => 'Total operacional', 'safra' => 'Safra', 'offseason' => 'Entressafra'])[$sector['filters']['card']] ?? 'Status') ?>
<?= h($sector['filters']['card_status']) ?>.
<?= $this->Html->link('Limpar filtro do card', $cardUrl('', ''), ['class' => 'alert-link']) ?>
<small class="d-block">Os filtros manuais e o período da tabela também são respeitados. Cards e gráficos mantêm o conjunto de referência.</small></p>
<?php endif; ?>
<div class="pcm-panel"><div class="pcm-sector-table-scroll" role="region" aria-label="Ordens de Serviço — rolagem horizontal" tabindex="0"><table class="table pcm-orders-table align-middle">
<thead><tr><?php foreach (array_merge(['OS', 'Equipamento', 'Serviço', 'Centro de custo', 'Tipo', 'Início planejado', 'Início real', 'Status'], $sector['filters']['backlog_age'] !== '' ? ['Origem / idade'] : []) as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($sector['orders'] as $row): ?><tr>
<td><?= $this->Html->link($row['TJ_ORDEM'], ['_name' => 'pcm-protheus-order', 'number' => $row['TJ_ORDEM'], '?' => ['filial' => $row['TJ_FILIAL'], 'unit' => $sector['filters']['unit']]]) ?></td>
<td><?php if (trim((string)$row['TJ_CODBEM']) !== ''): ?><?= $this->Html->link($value($row['TJ_CODBEM']) . ' — ' . $value($row['equipment_name']), ['_name' => 'pcm-equipment', '?' => ['bem' => $row['TJ_CODBEM'], 'filial' => $row['TJ_FILIAL'], 'unit' => $sector['filters']['unit']]]) ?><?php else: ?>—<?php endif; ?></td>
<td><?= h($value($row['TJ_SERVICO'])) ?><br><?= h($value($row['service_name'])) ?></td>
<td><?= h($value($row['TJ_CCUSTO'])) ?></td><td><?= h($value($row['TJ_TIPO'])) ?></td>
<td><?= h($value($row['planned_date'])) ?> <?= h($value($row['TJ_HOMPINI'])) ?></td>
<td><?= h($value((new \App\Service\Protheus\Presentation\OrderSupplementMapper())->date($row['TJ_DTPRINI']))) ?> <?= h($value($row['TJ_HOPRINI'])) ?></td>
<td><span class="badge pcm-status-badge"><?= h($row['status']) ?></span></td>
<?php if ($sector['filters']['backlog_age'] !== ''): ?><td><?= h($value($row['origin_date'])) ?><br><?= h($row['age_days'] === null ? 'Sem data válida' : ($row['age_days'] < 0 ? 'Data futura' : $row['age_days'] . ' dias')) ?></td><?php endif; ?>
</tr><?php endforeach; ?>
<?php if (!$sector['orders']): ?><tr><td colspan="<?= $sector['filters']['backlog_age'] !== '' ? 9 : 8 ?>">Nenhuma O.S. encontrada para os filtros aplicados.</td></tr><?php endif; ?>
</tbody></table></div><footer class="pcm-pagination"><span>Página <?= h($sector['page']) ?></span>
<?php if ($sector['page'] > 1): ?><?= $this->Html->link('Anterior', $url($sector['page'] - 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
<?php if ($sector['has_more']): ?><?= $this->Html->link('Ver mais', $url($sector['page'] + 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
</footer></div></section>
