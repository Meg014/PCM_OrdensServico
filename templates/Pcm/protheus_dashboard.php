<?php
$this->assign('title', $presentation ? 'Visão Gerencial — Protheus' : 'PCM Geral — Protheus');
$this->Html->script($presentation ? ['pcm-protheus-dashboard'] : ['chart.umd.min', 'pcm-protheus-dashboard'],
    ['block' => true, 'defer' => true, 'timestamp' => 'force']);
$isTv = isset($currentUser) && $currentUser->role === 'TV';
?>
<section class="<?= $presentation ? 'p-4' : '' ?>" data-protheus-dashboard data-presentation="<?= $presentation ? 'true' : 'false' ?>"
 data-url="<?= h($this->Url->build(['_name' => $presentation ? 'pcm-presentation-data' : 'pcm-data', '?' => $payload['filters']])) ?>">
<header class="pcm-page-header"><div><?php if (!$presentation): ?><p class="pcm-eyebrow">PCM | PROTHEUS</p><?php endif; ?>
<h1 data-dashboard-title><?= $presentation ? 'PCM - VISÃO GERAL' : 'Visão Geral' ?></h1>
<?php if (!$presentation): ?><span class="badge text-bg-secondary">Fonte: Protheus</span><?php endif; ?>
<p class="pcm-updated" data-dashboard-updated>Dados atualizados em: consulta ainda indisponível</p>
<?php if (!$presentation): ?><small>Atualização automática a cada 5 minutos</small><?php endif; ?></div>
<?php if ($presentation && !$isTv): ?>
<?= $this->Html->link('Sair da apresentação', ['_name' => 'pcm'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
<?php endif; ?>
<?php if ($presentation && $isTv): ?>
<?= $this->Form->postLink(
    'Sair',
    '/logout',
    [
        'class' => 'btn btn-sm btn-outline-secondary',
        'confirm' => 'Deseja sair da conta da TV?'
    ]
) ?>
<?php endif; ?>
<?php if (!$presentation && !$isTv): ?><div class="d-flex gap-2">
<?= $this->Html->link($presentation ? 'Sair da apresentação' : 'Modo Apresentação', ['_name' => $presentation ? 'pcm' : 'pcm-presentation', '?' => $payload['filters']], ['class' => 'btn btn-outline-primary']) ?>

</div><?php endif; ?></header>
<p data-dashboard-notice role="status" aria-live="polite" class="text-body-secondary"></p>
<?php if (!$presentation): ?>
<p class="text-body-secondary">Safra/Entressafra seguem a classificação de serviços do PCM.
Abertas: término N, situação diferente de C e início planejado desde 01/01/2026.
Fechadas: término S, sem corte de data. Combinações conflitantes aguardam validação.</p>
<details class="pcm-panel p-3 mb-3"><summary>Filtros da consulta Protheus</summary>
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 mt-2']) ?>
<?php if ($payload['filters']['filial'] !== ''): ?><?= $this->Form->hidden('filial', ['value' => $payload['filters']['filial']]) ?><?php endif; ?>
<?php foreach (['area' => 'Área', 'bem' => 'Equipamento', 'servico' => 'Serviço',
    'centro' => 'Centro de custo', 'tipo' => 'TJ_TIPO (bruto)', 'situacao' => 'Situação (bruta)', 'termino' => 'Término (bruto)'] as $key => $label): ?>
<div class="col-sm-6 col-lg-3"><label class="form-label" for="dashboard-<?= h($key) ?>"><?= h($label) ?></label>
<input class="form-control" id="dashboard-<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($payload['filters'][$key]) ?>" maxlength="100"></div>
<?php endforeach; ?>
<div class="col-12"><button type="submit" class="btn btn-primary">Aplicar códigos exatos</button>
<small>Vazio: sem filtro. A atualização automática preserva estes filtros.</small></div>
<?= $this->Form->end() ?></details>
<?php endif; ?>
<div class="<?= $presentation ? 'pcm-presentation-cards' : 'row g-3 pcm-kpi-grid-large' ?>" aria-live="polite">
<?php foreach (['safra' => 'Safra', 'offseason' => 'Entressafra'] as $season => $label): ?>
<?php foreach (['open' => 'em aberto', 'completed' => 'fechadas'] as $status => $statusLabel): $key = $season . '_' . $status; ?>
<div class="<?= $presentation ? '' : 'col-12 col-sm-6' ?>"><article class="<?= $presentation ? 'pcm-presentation-card pcm-presentation-' . $status : 'pcm-kpi-card pcm-kpi-' . ($status === 'open' ? 'total' : 'completed') ?>">
<p class="pcm-kpi-label"><?= h($label . ' — O.S. ' . $statusLabel) ?></p>
<strong class="pcm-kpi-value" data-dashboard-card="<?= h($key) ?>"><?= $payload['indicators'][$key] === null ? '—' : h(number_format($payload['indicators'][$key], 0, ',', '.')) ?></strong>
</article></div><?php endforeach; endforeach; ?></div>
<?php if ($presentation): ?>
<?php foreach ([['preventive' => 'Preventivas', 'corrective' => 'Corretivas', 'improvement' => 'Melhorias'],
    ['emergency' => 'Corretivas Emergenciais', 'scheduled' => 'Corretivas Programadas', 'opportunity' => 'PARADAS POR OPORTUNIDADE']] as $labels): ?>
<div class="pcm-presentation-type-cards mt-3">
<?php foreach ($labels as $key => $label): ?><article><p><?= h($label) ?></p>
<strong data-dashboard-card="<?= h($key) ?>"><?= $payload['indicators'][$key] === null ? '—' : h(number_format($payload['indicators'][$key], 0, ',', '.')) ?></strong>
</article><?php endforeach; ?></div>
<?php endforeach; ?>
<?php endif; ?>
<?php if (!$presentation): ?>
<?php if (($payload['detail']['available'] ?? false) === true): ?><?= $this->element('protheus_general_detail', ['payload' => $payload, 'part' => 'before']) ?><?php endif; ?>
<?php $analysis = $payload['analysis'] ?? ['total' => 0, 'equipment' => [], 'services' => [], 'costCenters' => [], 'maintenance' => [], 'sectors' => [],
    'status' => ['completed' => 0, 'open' => 0, 'canceled' => 0]]; ?>
<section class="pcm-dashboard-section mt-4" data-dashboard-analysis>
<div class="pcm-section-title"><p>ANÁLISE GERAL</p><h2>Visão histórica das O.S.</h2></div>
<p class="text-body-secondary">Todas as O.S. válidas e não excluídas da STJ010, considerando os filtros exatos aplicados acima.</p>
<div class="row g-3 mb-4"><div class="col-sm-6 col-lg-4"><article class="pcm-kpi-card pcm-kpi-total flex-column align-items-start text-start gap-3 p-4">
<p class="pcm-kpi-label mb-0">Total de O.S.</p><strong class="pcm-kpi-value" data-analysis-total><?= number_format($analysis['total'], 0, ',', '.') ?></strong>
</article></div></div>
<div class="row g-4">
<div class="col-xl-4"><article class="pcm-chart-card"><h3>Top 10 equipamentos por O.S.</h3><p>Equipamentos com maior volume no histórico selecionado.</p><ul class="list-group list-group-flush mt-3" data-analysis-list="equipment">
<?php foreach ($analysis['equipment'] as $row): ?><li class="list-group-item d-flex justify-content-between align-items-start gap-3 px-0"><?= $this->Html->link($row['code'] . ' — ' . $row['name'], ['_name' => 'pcm-equipment', '?' => ['bem' => $row['code'], 'filial' => $row['branch']]], ['class' => 'pcm-ranking-link']) ?><strong><?= number_format($row['quantity'], 0, ',', '.') ?></strong></li><?php endforeach; ?>
</ul></article></div>
<div class="col-xl-4"><article class="pcm-chart-card"><h3>Top 10 serviços por O.S.</h3><p>Serviços mais recorrentes no histórico selecionado.</p><ul class="list-group list-group-flush mt-3" data-analysis-list="services">
<?php foreach ($analysis['services'] as $row): ?><li class="list-group-item d-flex justify-content-between align-items-start gap-3 px-0"><span><?= h($row['code'] . ' — ' . $row['name']) ?></span><strong><?= number_format($row['quantity'], 0, ',', '.') ?></strong></li><?php endforeach; ?>
</ul></article></div>
<div class="col-xl-4"><article class="pcm-chart-card"><h3>Top 10 centros de custo por O.S.</h3><p>Quantidade histórica; não representa custo financeiro.</p><ul class="list-group list-group-flush mt-3" data-analysis-list="costCenters">
<?php foreach ($analysis['costCenters'] as $row): ?><li class="list-group-item d-flex justify-content-between align-items-start gap-3 px-0"><span><?= h($row['code'] ?: 'Sem centro de custo') ?></span><strong><?= number_format($row['quantity'], 0, ',', '.') ?></strong></li><?php endforeach; ?>
</ul></article></div>
<?php foreach ([['maintenance', 'O.S. por Tipo de Manutenção'], ['sectors', 'O.S. por Setor']] as [$key, $heading]): ?>
<div class="col-xl-6"><article class="pcm-chart-card"><h3><?= h($heading) ?></h3><p>Distribuição no mesmo universo histórico.</p><ul class="list-group list-group-flush mt-3" data-analysis-list="<?= h($key) ?>">
<?php foreach ($analysis[$key] as $row): ?><li class="list-group-item d-flex justify-content-between align-items-start gap-3 px-0"><span><?= h($row['label']) ?></span><strong><?= number_format($row['quantity'], 0, ',', '.') ?></strong></li><?php endforeach; ?>
</ul></article></div><?php endforeach; ?>
<div class="col-12"><article class="pcm-chart-card"><h3>Situação das O.S.</h3><p>Classificação exclusiva: cada O.S. pertence a somente uma situação.</p><div class="row g-3 mt-1">
<?php foreach (['completed' => ['Finalizadas', 'pcm-kpi-completed'], 'open' => ['Não finalizadas', 'pcm-kpi-progress'], 'canceled' => ['Canceladas', 'pcm-kpi-cancelled']] as $key => [$label, $class]): ?><div class="col-12 col-md-4 d-flex"><div class="pcm-kpi-card <?= h($class) ?> flex-column align-items-start text-start gap-3 p-4 w-100"><p class="pcm-kpi-label mb-0"><?= h($label) ?></p><strong class="pcm-kpi-value" data-analysis-status="<?= h($key) ?>"><?= number_format($analysis['status'][$key], 0, ',', '.') ?></strong></div></div><?php endforeach; ?>
</div></article></div>
</div></section>
<section class="pcm-dashboard-section" data-analysis-charts><div class="pcm-section-title"><p>VISÃO CONSOLIDADA</p><h2>Situação e concentração das O.S.</h2></div><div class="row g-4">
<?php foreach (['status' => 'Distribuição por situação', 'maintenance' => 'Perfil de manutenção',
    'equipment' => 'Top 10 equipamentos', 'services' => 'Top 10 serviços',
    'costCenters' => 'Top 10 centros de custo', 'sectors' => 'Distribuição por setor'] as $key => $heading): ?>
<div class="col-lg-6"><article class="pcm-chart-card pcm-chart-card-tall"><h3><?= h($heading) ?></h3><div class="pcm-chart-wrap"><canvas data-analysis-chart="<?= h($key) ?>"></canvas></div></article></div>
<?php endforeach; ?></div></section>
<?php if (($payload['detail']['available'] ?? false) === true): ?><?= $this->element('protheus_general_detail', ['payload' => $payload, 'part' => 'after']) ?><?php endif; ?>
<details class="mt-3 text-body-secondary"><summary>Informação técnica</summary>
O.S. não excluídas nos filtros selecionados: <span data-dashboard-count>—</span> (não é o total operacional).
</details>
<?php endif; ?>
<noscript>Ative JavaScript para a atualização automática.</noscript>
</section>
<script type="application/json" data-dashboard-payload><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
