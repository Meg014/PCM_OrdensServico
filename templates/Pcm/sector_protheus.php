<?php
$this->assign('title', $sector['name']);
$this->Html->script(['chart.umd.min', 'pcm-protheus-sector'], ['block' => true, 'defer' => true]);
?>
<section data-protheus-sector data-url="<?= h($this->Url->build(['_name' => 'pcm-sector-data', 'code' => $sector['code'], '?' => $sector['filters'] + ['page' => $sector['page'], 'limit' => $sector['limit']]])) ?>">
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | SETOR</p><h1><?= h($sector['name']) ?></h1>
<span class="badge text-bg-secondary">Fonte: Protheus</span>
<p class="pcm-updated" data-sector-updated><?= h($sector['queried_at'] ? 'Dados atualizados em: ' . $sector['queried_at'] : 'Consulta indisponível') ?></p></div>
</header>
<p data-sector-notice role="status" class="text-body-secondary"><?= $sector['available'] ? '' : 'Dados do Protheus temporariamente indisponíveis.' ?></p>
<section class="pcm-panel pcm-filter-panel"><h2>Filtros do setor</h2>
<p>Carteira operacional: abertas elegíveis desde 01/01/2026 e fechadas, sem canceladas.</p>
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'pcm-filter-form']) ?>
<?= $this->Form->hidden('card', ['value' => $sector['filters']['card']]) ?>
<?= $this->Form->hidden('card_status', ['value' => $sector['filters']['card_status']]) ?>
<?= $this->Form->hidden('backlog_age', ['value' => $sector['filters']['backlog_age']]) ?>
<div class="row g-3">
<?php foreach (['filial' => 'Filial', 'status' => 'Status', 'equipment' => 'Equipamento/Bem (código)', 'service' => 'Serviço (código)',
    'service_name' => 'Nome do serviço (exato)', 'cost_center' => 'Centro de custo', 'maintenance_type' => 'Tipo Manut.',
    'q' => 'Pesquisar OS, bem ou serviço', 'date_start' => 'Início planejado: de', 'date_end' => 'Início planejado: até'] as $key => $label): ?>
<div class="col-md-4"><?= $this->Form->control($key, ['label' => $label, 'value' => $sector['filters'][$key],
    'type' => in_array($key, ['status', 'maintenance_type'], true) ? 'select' : (str_starts_with($key, 'date_') ? 'date' : 'text')]
    + ($key === 'status' ? ['empty' => 'Todos', 'options' => ['EM ABERTO' => 'Em aberto', 'FECHADA' => 'Fechada']] : [])
    + ($key === 'maintenance_type' ? ['empty' => 'Todos', 'options' => ['PRE' => 'Preventiva', 'COR' => 'Corretiva', 'MEL' => 'Melhoria']] : [])) ?></div>
<?php endforeach; ?></div>
<p class="text-body-secondary mt-2">Códigos e nome do serviço: correspondência exata. Pesquisa: parte do número, código ou nome.
O período planejado filtra somente a tabela.</p>
<button class="btn pcm-primary-action">Aplicar</button>
<?= $this->Html->link('Limpar', ['_name' => 'pcm-sector', 'code' => $sector['code']], ['class' => 'btn pcm-secondary-action']) ?>
<?= $this->Form->end() ?></section>
<div data-sector-content><?= $sector['available'] ? $this->element('protheus_sector_content', compact('sector')) : '' ?></div>
<small>Atualização automática a cada 5 minutos.</small>
</section>
<script type="application/json" data-sector-charts><?= json_encode($sector['charts'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
