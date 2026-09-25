<?php
$f = $equipment['filters'];
$this->assign('title', 'Histórico do equipamento — ' . $f['bem']);
$url = fn (int $page) => ['_name' => 'pcm-equipment', '?' => $f + ['page' => $page, 'limit' => $equipment['limit']]];
$date = static function ($raw): string {
    $iso = (new \App\Service\Protheus\Presentation\OrderSupplementMapper())->date($raw);
    return $iso ? implode('/', array_reverse(explode('-', $iso))) : '—';
};
$area = static fn ($code) => \App\Model\Table\MaintenanceAreasTable::FRIENDLY_NAMES[$code ?? ''] ?? ($code ?: '—');
$status = static function (array $row): string {
    if ($row['TJ_SITUACA'] === 'L' && $row['TJ_TERMINO'] === 'N') return 'Aberta';
    if ($row['TJ_SITUACA'] === 'L' && $row['TJ_TERMINO'] === 'S') return 'Fechada';
    return ['C' => 'Cancelada', 'P' => 'Pendente'][$row['TJ_SITUACA']] ?? '—';
};
?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">HISTÓRICO DO EQUIPAMENTO</p>
<h1><?= h($f['bem']) ?></h1><span class="badge text-bg-secondary">Fonte: Protheus</span>
<?php if ($equipment['available']): ?><p class="pcm-updated">Dados atualizados em: <?= h($equipment['queried_at']) ?></p><?php endif; ?>
</div><?= $this->Html->link('Ordens de Serviço', ['_name' => 'pcm-orders'], ['class' => 'btn btn-outline-secondary']) ?></header>
<?= $this->Html->link('Exportar histórico para Excel', ['_name' => 'pcm-equipment-excel', '?' => $f], ['class' => 'btn btn-outline-success mb-3']) ?>
<p class="text-body-secondary small">Exporta todos os resultados dos filtros aplicados, até 5.000 OS.</p>
<?php if (!$equipment['available']): ?>
<p class="alert alert-secondary" role="status">Histórico do Protheus temporariamente indisponível. Tente novamente em instantes.</p>
<?php return; endif; $s = $equipment['summary']; ?>
<section class="pcm-panel p-4 mb-4"><h2><?= h($equipment['header']['T9_NOME'] ?? 'Nome não disponível no cadastro') ?></h2>
<p>Filial: <?= h($f['filial'] ?: 'Não informada') ?></p>
<p>Centro de custo registrado nas O.S.: <?= h((int)$s['cost_center_count'] > 1 ? 'Múltiplos — consulte a tabela' : ($s['cost_center'] ?: 'Não informado')) ?><br>
Área/setor registrado nas O.S.: <?= h((int)$s['area_count'] > 1 ? 'Múltiplos — consulte a tabela' : $area($s['area'])) ?></p>
<small class="text-body-secondary">Área e centro de custo são os registros históricos das O.S., não uma atribuição cadastral atual do bem.</small></section>
<section class="pcm-panel p-4"><h2>Filtros do histórico</h2>
<?= $this->Form->create(null, ['type' => 'get']) ?>
<?= $this->Form->hidden('bem', ['value' => $f['bem']]) ?><?= $this->Form->hidden('filial', ['value' => $f['filial']]) ?>
<div class="row g-3">
<?php foreach (['date_start' => 'Data inicial', 'date_end' => 'Data final'] as $key => $label): ?>
<div class="col-md-3"><?= $this->Form->control($key, ['type' => 'date', 'label' => $label, 'value' => $f[$key], 'class' => 'form-control']) ?></div>
<?php endforeach; ?>
<div class="col-md-3"><?= $this->Form->control('type', ['label' => 'Tipo de manutenção', 'empty' => 'Todos', 'options' => ['COR' => 'Corretiva', 'PRE' => 'Preventiva', 'MEL' => 'Melhoria'], 'value' => $f['type'], 'class' => 'form-select']) ?></div>
<div class="col-md-3"><?= $this->Form->control('status', ['label' => 'Situação operacional', 'empty' => 'Todas', 'options' => ['open' => 'Aberta', 'closed' => 'Fechada'], 'value' => $f['status'], 'class' => 'form-select']) ?></div>
</div><p class="text-body-secondary mt-3">Período pela data de referência: término real, início real ou origem, nessa ordem. Os filtros afetam tabela, resumo e reincidência.</p>
<button class="btn pcm-primary-action">Aplicar</button> <?= $this->Html->link('Limpar', ['_name' => 'pcm-equipment', '?' => array_intersect_key($f, array_flip(['bem', 'filial']))], ['class' => 'btn pcm-secondary-action']) ?>
<?= $this->Form->end() ?></section>
<section class="pcm-dashboard-section"><h2 class="mb-4">Resumo do histórico</h2><div class="row g-3">
<?php foreach (['total' => 'Total de O.S.', 'open_count' => 'Abertas', 'closed_count' => 'Fechadas', 'corrective' => 'Corretivas', 'preventive' => 'Preventivas', 'improvement' => 'Melhorias'] as $key => $label): ?>
<div class="col-md-4"><article class="pcm-kpi-card flex-column gap-3 p-4"><p class="pcm-kpi-label"><?= h($label) ?></p><strong class="pcm-kpi-value"><?= h(number_format((int)$s[$key], 0, ',', '.')) ?></strong></article></div>
<?php endforeach; ?></div><p class="text-body-secondary mt-3">Total inclui todos os registros não excluídos. Tipos e reincidência consideram somente liberadas L/N e L/S.
Canceladas: <?= h($s['canceled_count']) ?> · Pendentes: <?= h($s['pending_count']) ?>.</p></section>
<section class="pcm-dashboard-section"><h2 class="mb-4">Reincidência de corretivas</h2><div class="row g-3">
<?php foreach ([30, 90, 365] as $days): ?><div class="col-md-4"><article class="pcm-kpi-card flex-column gap-3 p-4"><p class="pcm-kpi-label">Últimos <?= h($days) ?> dias</p><strong class="pcm-kpi-value"><?= h($s['recurrence' . $days]) ?></strong></article></div><?php endforeach; ?>
</div><p class="text-body-secondary mt-3">Tipo COR pela data de origem (TJ_DTORIGI), incluindo hoje e os dias anteriores da janela, no fuso do PCM. Datas inválidas, ausentes e futuras não entram nas janelas.</p></section>
<section class="pcm-dashboard-section pcm-equipment-history"><h2 class="mb-4">Histórico de O.S.</h2><div class="pcm-panel"><div class="pcm-sector-table-scroll" role="region" aria-label="Histórico do equipamento — rolagem horizontal" tabindex="0">
<table class="table pcm-orders-table align-middle"><thead><tr><?php foreach (['O.S.', 'Data de referência', 'Tipo', 'Serviço', 'Descrição da O.S.', 'Situação', 'Centro de custo', 'Área/setor'] as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($equipment['orders'] as $row): ?><tr>
<td><?= $this->Html->link($row['TJ_ORDEM'], ['_name' => 'pcm-protheus-order', 'number' => $row['TJ_ORDEM'], '?' => ['filial' => $row['TJ_FILIAL']]]) ?></td>
<td><?= h($date($row['reference_date'])) ?></td><td><?= h(['COR' => 'Corretiva', 'PRE' => 'Preventiva', 'MEL' => 'Melhoria'][$row['TJ_TIPO']] ?? $row['TJ_TIPO']) ?></td>
<td><?= h($row['TJ_SERVICO']) ?><br><?= h($row['service_name'] ?? '—') ?></td>
<td class="text-wrap" style="min-width: 20rem; white-space: pre-wrap"><?= h($row['descricao'] ?? '—') ?></td>
<td><?= h($status($row)) ?></td>
<td><?= h($row['TJ_CCUSTO'] ?: '—') ?></td><td><?= h($area($row['TJ_CODAREA'])) ?></td></tr><?php endforeach; ?>
<?php if (!$equipment['orders']): ?><tr><td colspan="8">Nenhuma O.S. encontrada para os filtros aplicados.</td></tr><?php endif; ?>
</tbody></table></div><footer class="pcm-pagination"><span>Página <?= h($equipment['page']) ?></span>
<?php if ($equipment['page'] > 1): ?><?= $this->Html->link('Anterior', $url($equipment['page'] - 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
<?php if ($equipment['has_more']): ?><?= $this->Html->link('Ver mais', $url($equipment['page'] + 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
</footer></div></section>
