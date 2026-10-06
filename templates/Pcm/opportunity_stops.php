<?php
$this->assign('title', 'Paradas por Oportunidade');
$filters = $stops['filters'];
$query = ['area' => $stops['area'], 'unit' => $stops['unit'], 'cost_center' => $filters['cost_center']];
$workshopNames = \App\Service\Protheus\OpportunityStopService::WORKSHOPS;
$typeNames = ['COR' => 'Corretiva', 'PRE' => 'Preventiva', 'MEL' => 'Melhoria'];
?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | PROTHEUS</p><h1>Paradas por Oportunidade</h1><p class="pcm-updated">O.S. abertas classificadas pelos serviços MECOPO e ELECOP.</p></div></header>
<section class="pcm-panel pcm-filter-panel mb-4"><div class="pcm-panel-heading"><h2>Filtros</h2></div>
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'pcm-filter-form']) ?><div class="row g-3">
<div class="col-md-4"><?= $this->Form->control('area', ['label' => 'Oficina', 'empty' => 'Todas', 'options' => $workshops, 'value' => $stops['area']]) ?></div>
<div class="col-md-4"><?= $this->Form->control('unit', ['label' => 'Unidade', 'empty' => 'Todas', 'options' => $units, 'value' => $stops['unit']]) ?></div>
<div class="col-md-4"><?= $this->Form->control('cost_center', ['label' => 'Setor / Centro de Custo', 'empty' => 'Todos', 'options' => $costCenters, 'value' => $filters['cost_center']]) ?></div>
<div class="col-12"><button class="btn btn-primary" type="submit">Aplicar filtro</button> <?= $this->Html->link('Exportar para Excel', ['_name' => 'pcm-opportunity-stops-excel', '?' => $query], ['class' => 'btn btn-outline-success']) ?></div>
</div><?= $this->Form->end() ?></section>
<?php if (!$stops['available']): ?><p class="alert alert-secondary">Dados do Protheus temporariamente indisponíveis.</p><?php else: ?>
<p class="text-body-secondary">Total: <?= number_format($stops['total'], 0, ',', '.') ?> O.S.</p><div class="pcm-panel"><div class="pcm-sector-table-scroll" role="region" aria-label="Paradas por Oportunidade — rolagem horizontal" tabindex="0"><table class="table align-middle mb-0"><thead><tr><th>O.S.</th><th>Descrição do serviço</th><th>Equipamento</th><th>Setor</th><th>Oficina</th><th>Tipo de manutenção</th></tr></thead><tbody>
<?php foreach ($stops['orders'] as $row): $area = trim((string)$row['TJ_CODAREA']); $type = trim((string)$row['TJ_TIPO']); ?><tr><td><?= $this->Html->link(rtrim($row['TJ_ORDEM']), ['_name' => 'pcm-protheus-order', 'number' => rtrim($row['TJ_ORDEM']), '?' => ['filial' => rtrim($row['TJ_FILIAL']), 'unit' => $stops['unit']]]) ?></td><td><?= h(trim((string)($row['descricao'] ?? ''))) ?></td><td><?= h(trim($row['TJ_CODBEM'] . ' — ' . ($row['equipment_name'] ?? ''))) ?></td><td><?= h(\App\Service\Protheus\OpportunityStopService::costCenterLabel($row)) ?></td><td><?= h($workshopNames[$area] ?? $area) ?></td><td><?= h($typeNames[$type] ?? $type) ?></td></tr><?php endforeach; ?></tbody></table></div>
<nav class="pcm-pagination"><?php if ($stops['page'] > 1): ?><?= $this->Html->link('← Anterior', ['_name' => 'pcm-opportunity-stops', '?' => $query + ['page' => $stops['page'] - 1, 'limit' => $stops['limit']]], ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?><span>Página <?= $stops['page'] ?></span><?php if ($stops['has_more']): ?><?= $this->Html->link('Próxima →', ['_name' => 'pcm-opportunity-stops', '?' => $query + ['page' => $stops['page'] + 1, 'limit' => $stops['limit']]], ['class' => 'btn btn-outline-primary']) ?><?php endif; ?></nav></div><?php endif; ?>
