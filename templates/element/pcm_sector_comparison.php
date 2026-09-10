<?php
/** @var list<array<string, mixed>> $sectorComparison */
$currentSort = (string)$this->getRequest()->getQuery('sector_sort', 'area');
$currentDirection = (string)$this->getRequest()->getQuery('sector_direction', 'asc');
$sortLink = function (string $field, string $label) use ($currentSort, $currentDirection): string {
    $direction = $currentSort === $field && $currentDirection === 'asc' ? 'desc' : 'asc';
    return $this->Html->link($label, ['?' => ['sector_sort' => $field, 'sector_direction' => $direction]]);
};
?>
<section class="pcm-dashboard-section" aria-labelledby="sector-comparison-title"><div class="pcm-section-title"><p>COMPARATIVO ENTRE SETORES</p><h2 id="sector-comparison-title">Indicadores por área no snapshot atual</h2></div>
<?php if ($sectorComparison === []): ?><div class="pcm-panel pcm-history-empty">Nenhum setor disponível para comparação.</div><?php else: ?><div class="row g-4"><div class="col-xl-7"><div class="pcm-panel"><div class="table-responsive"><table class="table pcm-sector-table mb-0"><thead><tr><th><?= $sortLink('name', 'Área') ?></th><th><?= $sortLink('total', 'Total') ?></th><th><?= $sortLink('open', 'Em aberto') ?></th><th><?= $sortLink('completed', 'Fechadas') ?></th><th><?= $sortLink('cancelled', 'Canceladas') ?></th><th><?= $sortLink('efficiency', 'Eficiência') ?></th></tr></thead><tbody><?php foreach ($sectorComparison as $sector): ?><tr><td><?= $this->Html->link($sector['name'], ['_name' => 'pcm-sector', 'code' => $sector['area']]) ?></td><td><?= number_format((int)$sector['total'], 0, ',', '.') ?></td><td><?= number_format((int)$sector['open'], 0, ',', '.') ?></td><td><?= number_format((int)$sector['completed'], 0, ',', '.') ?></td><td><?= number_format((int)$sector['cancelled'], 0, ',', '.') ?></td><td><?= number_format((float)$sector['efficiency'], 2, ',', '.') ?>%</td></tr><?php endforeach; ?></tbody></table></div></div></div><div class="col-xl-5"><div class="pcm-chart-card"><h3>Eficiência por setor</h3><p>Comparação simples no snapshot atual.</p><div class="pcm-chart-wrap"><canvas data-pcm-history="sectors"></canvas></div></div></div></div><script type="application/json" data-pcm-sector-payload><?= json_encode($sectorComparison, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script><?php endif; ?>
</section>
