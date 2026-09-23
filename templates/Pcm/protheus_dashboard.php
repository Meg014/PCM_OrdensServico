<?php
$this->assign('title', $presentation ? 'Visão Gerencial — Protheus' : 'PCM Geral — Protheus');
$this->Html->script('pcm-protheus-dashboard', ['block' => true, 'defer' => true]);
$isTv = isset($currentUser) && $currentUser->role === 'TV';
?>
<section class="<?= $presentation ? 'p-4' : '' ?>" data-protheus-dashboard data-presentation="<?= $presentation ? 'true' : 'false' ?>"
 data-url="<?= h($this->Url->build(['_name' => $presentation ? 'pcm-presentation-data' : 'pcm-data', '?' => $payload['filters']])) ?>">
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | PROTHEUS</p>
<h1 data-dashboard-title><?= $presentation ? 'PCM - VISÃO GERAL' : 'Visão Geral' ?></h1>
<span class="badge text-bg-secondary">Fonte: Protheus</span>
<p class="pcm-updated" data-dashboard-updated>Dados atualizados em: consulta ainda indisponível</p>
<small>Atualização automática a cada 5 minutos</small></div>
<?php if (!$isTv): ?><div class="d-flex gap-2">
<?= $this->Html->link($presentation ? 'Sair da apresentação' : 'Modo Apresentação', ['_name' => $presentation ? 'pcm' : 'pcm-presentation', '?' => $payload['filters']], ['class' => 'btn btn-outline-primary']) ?>
<?= $this->Html->link('Comparar legado Excel', ['_name' => $presentation ? 'pcm-presentation-legacy' : 'pcm-legacy'], ['class' => 'btn btn-outline-secondary']) ?>
</div><?php else: ?><?= $this->Form->postLink('Logout', '/logout', ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?></header>
<p data-dashboard-notice role="status" aria-live="polite" class="text-body-secondary"></p>
<p class="text-body-secondary">Safra/Entressafra seguem a classificação de serviços do PCM.
Abertas: término N, situação diferente de C e início planejado desde 01/01/2026.
Fechadas: término S, sem corte de data. Combinações conflitantes aguardam validação.</p>
<details class="pcm-panel p-3 mb-3"><summary>Filtros da consulta Protheus</summary>
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 mt-2']) ?>
<?php foreach (['filial' => 'Filial', 'area' => 'Área', 'bem' => 'Equipamento', 'servico' => 'Serviço',
    'centro' => 'Centro de custo', 'tipo' => 'TJ_TIPO (bruto)', 'situacao' => 'Situação (bruta)', 'termino' => 'Término (bruto)'] as $key => $label): ?>
<div class="col-sm-6 col-lg-3"><label class="form-label" for="dashboard-<?= h($key) ?>"><?= h($label) ?></label>
<input class="form-control" id="dashboard-<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($payload['filters'][$key]) ?>" maxlength="100"></div>
<?php endforeach; ?>
<div class="col-12"><button type="submit" class="btn btn-primary">Aplicar códigos exatos</button>
<small>Vazio: sem filtro. A atualização automática preserva estes filtros.</small></div>
<?= $this->Form->end() ?></details>
<div class="<?= $presentation ? 'pcm-presentation-cards' : 'row g-3 pcm-kpi-grid-large' ?>" aria-live="polite">
<?php foreach (['safra' => 'Safra', 'offseason' => 'Entressafra'] as $season => $label): ?>
<?php foreach (['open' => 'em aberto', 'completed' => 'fechadas'] as $status => $statusLabel): $key = $season . '_' . $status; ?>
<div class="<?= $presentation ? '' : 'col-12 col-sm-6' ?>"><article class="<?= $presentation ? 'pcm-presentation-card pcm-presentation-' . $status : 'pcm-kpi-card pcm-kpi-' . ($status === 'open' ? 'total' : 'completed') ?>">
<p class="pcm-kpi-label"><?= h($label . ' — O.S. ' . $statusLabel) ?></p>
<strong class="pcm-kpi-value" data-dashboard-card="<?= h($key) ?>"><?= $payload['indicators'][$key] === null ? '—' : h(number_format($payload['indicators'][$key], 0, ',', '.')) ?></strong>
</article></div><?php endforeach; endforeach; ?></div>
<?php if ($presentation): ?>
<?php foreach ([['preventive' => 'Preventivas', 'corrective' => 'Corretivas', 'improvement' => 'Melhorias'],
    ['emergency' => 'Corretivas Emergenciais', 'scheduled' => 'Corretivas Programadas']] as $labels): ?>
<div class="pcm-presentation-type-cards mt-3">
<?php foreach ($labels as $key => $label): ?><article><p><?= h($label) ?></p>
<strong data-dashboard-card="<?= h($key) ?>"><?= $payload['indicators'][$key] === null ? '—' : h(number_format($payload['indicators'][$key], 0, ',', '.')) ?></strong>
<small>O.S. em aberto elegíveis</small>
</article><?php endforeach; ?></div>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($presentation): ?><footer class="pcm-presentation-footer"><span data-dashboard-position>Tela 1</span>
<span>Alternância de áreas a cada 15 segundos</span></footer><?php endif; ?>
<details class="mt-3 text-body-secondary"><summary>Informação técnica</summary>
O.S. não excluídas nos filtros selecionados: <span data-dashboard-count>—</span> (não é o total operacional).
</details>
<noscript>Ative JavaScript para a atualização automática.</noscript>
</section>
<script type="application/json" data-dashboard-payload><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
