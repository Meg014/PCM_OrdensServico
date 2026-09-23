<?php
$this->assign('title', $presentation ? 'Apresentação — Protheus' : 'PCM Geral — Protheus');
$this->Html->script('pcm-protheus-dashboard', ['block' => true, 'defer' => true]);
$isTv = isset($currentUser) && $currentUser->role === 'TV';
$endpoint = $presentation ? 'pcm-presentation-data' : 'pcm-data';
?>
<section class="<?= $presentation ? 'p-4' : '' ?>" data-protheus-dashboard data-presentation="<?= $presentation ? 'true' : 'false' ?>"
    data-url="<?= h($this->Url->build(['_name' => $endpoint, '?' => $payload['filters']])) ?>">
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | PROTHEUS</p>
<h1><?= $presentation ? 'Visão Gerencial' : 'Visão Geral' ?></h1>
<span class="badge text-bg-secondary">Fonte: Protheus</span>
<p class="pcm-updated" data-dashboard-updated>Dados atualizados em: consulta ainda indisponível</p>
<small>Atualização automática a cada 5 minutos</small></div>
<?php if (!$isTv): ?><div class="d-flex gap-2">
<?= $this->Html->link($presentation ? 'Sair da apresentação' : 'Modo Apresentação', ['_name' => $presentation ? 'pcm' : 'pcm-presentation', '?' => $payload['filters']], ['class' => 'btn btn-outline-primary']) ?>
<?= $this->Html->link('Comparar legado Excel', ['_name' => $presentation ? 'pcm-presentation-legacy' : 'pcm-legacy'], ['class' => 'btn btn-outline-secondary']) ?>
</div><?php else: ?><?= $this->Form->postLink('Logout', '/logout', ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?></header>
<p class="text-body-secondary" data-dashboard-notice role="status" aria-live="polite"></p>
<div class="alert alert-secondary">Status e total operacional aguardam validação dos códigos do Protheus.
Os números abaixo representam O.S. não excluídas, sem aplicar a regra operacional do legado.
Tipos, situação e término são códigos brutos; não representam classificações confirmadas.</div>
<details class="pcm-panel p-3 mb-3"><summary>Filtros da consulta Protheus</summary>
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 mt-2']) ?>
<?php foreach (['filial' => 'Filial', 'area' => 'Área', 'bem' => 'Equipamento', 'servico' => 'Serviço',
    'centro' => 'Centro de custo', 'tipo' => 'Tipo (bruto)', 'situacao' => 'Situação (bruta)', 'termino' => 'Término (bruto)'] as $key => $label): ?>
<div class="col-sm-6 col-lg-3"><label class="form-label" for="dashboard-<?= h($key) ?>"><?= h($label) ?></label>
<input class="form-control" id="dashboard-<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($payload['filters'][$key]) ?>" maxlength="100"></div>
<?php endforeach; ?>
<div class="col-12"><button type="submit" class="btn btn-primary">Aplicar códigos exatos</button>
<small>Vazio: sem filtro. A atualização automática preserva estes filtros.</small></div>
<?= $this->Form->end() ?></details>
<div class="row g-3 mb-3">
<div class="col-12"><article class="pcm-kpi-card pcm-kpi-total"><p class="pcm-kpi-label">O.S. não excluídas no Protheus</p>
<p class="pcm-kpi-value" data-dashboard-count>—</p></article></div>
<?php foreach (['Total operacional', 'Concluídas', 'Em andamento', 'Canceladas', 'Não iniciadas', 'Eficiência'] as $label): ?>
<div class="col-sm-6 col-lg-4"><article class="pcm-kpi-card"><p class="pcm-kpi-label"><?= h($label) ?></p>
<p class="pcm-kpi-value">—</p><small>Aguardando validação da regra</small></article></div>
<?php endforeach; ?></div>
<div class="row g-3" data-dashboard-rankings>
<?php foreach (['area' => 'Áreas', 'equipment' => 'Equipamentos', 'service' => 'Serviços',
    'cost_center' => 'Centros de custo', 'type' => 'Tipos (TJ_TIPO)', 'status_raw' => 'Situação / término (brutos)'] as $dimension => $title): ?>
<section class="col-lg-6" data-dashboard-panel><div class="pcm-panel p-3"><h2><?= h($title) ?></h2>
<p class="text-body-secondary">Até 10 maiores grupos, separados por filial. Percentual sobre O.S. não excluídas.</p>
<div data-dashboard-group="<?= h($dimension) ?>"></div></div></section>
<?php endforeach; ?></div>
<?php if ($presentation): ?><p class="text-body-secondary mt-3">Exibição alternada dos rankings a cada 15 segundos.</p><?php endif; ?>
<noscript>Ative JavaScript para exibir os resultados e a atualização automática.</noscript>
</section>
<script type="application/json" data-dashboard-payload><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
