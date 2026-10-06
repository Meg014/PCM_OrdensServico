<?php
$detail = $payload['detail'];
$part = $part ?? 'before';
$value = static fn ($value) => $value === null || $value === '' ? '—' : (string)$value;
if ($part === 'before'):
?>
<section class="pcm-dashboard-section"><h2>Resumo operacional geral</h2><div class="row g-3">
<?php foreach (['total' => 'Total operacional', 'open' => 'O.S. abertas', 'closed' => 'O.S. fechadas'] as $key => $label): ?>
<div class="col-md-4"><article class="pcm-kpi-card flex-column gap-3 p-4"><p class="pcm-kpi-label mb-0"><?= h($label) ?></p><strong class="pcm-kpi-value"><?= number_format($detail['operational'][$key], 0, ',', '.') ?></strong></article></div>
<?php endforeach; ?></div></section>
<section class="pcm-dashboard-section"><h2>Detalhamento por classificação</h2>
<p class="text-body-secondary">Detalhamentos por serviço podem se sobrepor aos tipos. Não são somados ao Total operacional.</p><div class="row g-3">
<?php foreach (\App\Service\Protheus\ProtheusSectorService::CATEGORIES as $category => $label): ?>
<div class="col-md-6 col-xl-4"><article class="pcm-kpi-card flex-column gap-3 p-4"><h3 class="h5 mb-0"><?= h($label) ?></h3><div class="d-flex flex-wrap justify-content-center gap-3"><?php if ($category === 'opportunity'): ?><?= $this->Html->link('Abertas: ' . number_format($detail['breakdown'][$category]['open'], 0, ',', '.'), ['_name' => 'pcm-opportunity-stops'], ['class' => 'pcm-kpi-inline-link']) ?><?php else: ?><span>Abertas: <strong><?= number_format($detail['breakdown'][$category]['open'], 0, ',', '.') ?></strong></span><?php endif; ?><span>Fechadas: <strong><?= number_format($detail['breakdown'][$category]['closed'], 0, ',', '.') ?></strong></span></div></article></div>
<?php endforeach; ?></div></section>
<?php else: ?>
<section class="pcm-dashboard-section"><h2>Backlog / O.S. em aberto</h2><p class="text-body-secondary">Abertas liberadas em todas as áreas. Idade desde a origem da O.S. até <?= h($detail['backlog']['as_of']) ?>.</p><div class="row g-3">
<?php foreach (['all' => 'Total de O.S. em aberto'] + \App\Service\Protheus\ProtheusSectorService::BACKLOG_AGES as $key => $label): $quantity = $key === 'all' ? $detail['backlog']['total'] : $detail['backlog']['ages'][$key]; if (in_array($key, ['unknown', 'future'], true) && $quantity === 0) continue; ?>
<div class="col-sm-6 col-xl-4"><article class="pcm-kpi-card flex-column gap-3 p-4"><p class="pcm-kpi-label mb-0"><?= h($label) ?></p><strong class="pcm-kpi-value"><?= number_format($quantity, 0, ',', '.') ?></strong></article></div><?php endforeach; ?>
</div></section>
<section class="pcm-dashboard-section" id="orders"><h2>Ordens de Serviço</h2><div class="pcm-panel"><div class="pcm-sector-table-scroll" role="region" aria-label="Ordens de Serviço — rolagem horizontal" tabindex="0"><table class="table pcm-orders-table align-middle"><thead><tr>
<?php foreach (['OS','Equipamento','Serviço','Área/Setor','Centro de custo','Tipo','Início planejado','Início real','Status'] as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($detail['orders'] as $row): ?><tr><td><?= $this->Html->link($row['TJ_ORDEM'], ['_name' => 'pcm-protheus-order', 'number' => $row['TJ_ORDEM'], '?' => ['filial' => $row['TJ_FILIAL'], 'unit' => $payload['filters']['unidade'] ?? '']]) ?></td>
<td><?= trim((string)$row['TJ_CODBEM']) === '' ? '—' : $this->Html->link($value($row['TJ_CODBEM']) . ' — ' . $value($row['equipment_name']), ['_name' => 'pcm-equipment', '?' => ['bem' => $row['TJ_CODBEM'], 'filial' => $row['TJ_FILIAL']]]) ?></td>
<td><?= h($value($row['TJ_SERVICO'])) ?><br><?= h($value($row['service_name'])) ?></td><td><?= h($value($row['TJ_CODAREA'])) ?></td><td><?= h($value($row['TJ_CCUSTO'])) ?></td><td><?= h($value($row['TJ_TIPO'])) ?></td><td><?= h($value($row['planned_date'])) ?> <?= h($value($row['TJ_HOMPINI'])) ?></td><td><?= h($value((new \App\Service\Protheus\Presentation\OrderSupplementMapper())->date($row['TJ_DTPRINI']))) ?> <?= h($value($row['TJ_HOPRINI'])) ?></td><td><?= h($value($row['status'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><nav class="pcm-pagination"><?php if ($detail['page'] > 1): ?><?= $this->Html->link('← Anterior', ['_name' => 'pcm', '?' => $payload['filters'] + ['page' => $detail['page'] - 1, 'limit' => $detail['limit']], '#' => 'orders'], ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?><span>Página <?= h($detail['page']) ?></span><?php if ($detail['has_more']): ?><?= $this->Html->link('Próxima →', ['_name' => 'pcm', '?' => $payload['filters'] + ['page' => $detail['page'] + 1, 'limit' => $detail['limit']], '#' => 'orders'], ['class' => 'btn btn-outline-primary']) ?><?php endif; ?></nav></div></section>
<?php endif; ?>
