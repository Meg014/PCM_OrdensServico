<?php
$this->assign('title', $sector['name']);
$this->Html->script('pcm-equipment-autocomplete', ['block' => true, 'defer' => true, 'timestamp' => 'force']);
$this->Html->script('pcm-sector-unit-filter', ['block' => true, 'defer' => true, 'timestamp' => 'force']);
$this->Html->script('pcm-protheus-sector', ['block' => true, 'defer' => true, 'timestamp' => 'force']);
$sectorNavigationFilters = array_filter($sector['filters'], static fn ($value): bool => $value !== '');
$equipmentScopeEmpty = $sector['available'] && !$sector['orders']
    && array_sum(array_intersect_key($sector['cards'], array_flip(['safra_open', 'safra_completed', 'offseason_open', 'offseason_completed']))) === 0
    && ($sector['backlog']['total'] ?? 0) === 0;
?>
<section data-protheus-sector data-url="<?= $this->Url->build(['_name' => 'pcm-sector-data', 'code' => $sector['code'], '?' => $sectorNavigationFilters + ['page' => $sector['page'], 'limit' => $sector['limit']]]) ?>">
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | SETOR</p><h1><?= h($sector['name']) ?></h1>
<span class="badge text-bg-secondary">Fonte: Protheus</span>
<p class="pcm-updated" data-sector-updated><?= h($sector['queried_at'] ? 'Dados atualizados em: ' . (new \App\Service\PcmTimeFormatter())->format(new \DateTimeImmutable($sector['queried_at']), 'd/m/Y, H:i:s') : 'Consulta indisponível') ?></p></div>
</header>
<?= $this->Html->link('Exportar Excel', ['_name' => 'pcm-sector-excel', 'code' => $sector['code'], '?' => $sectorNavigationFilters], ['class' => 'btn btn-outline-success mb-3']) ?>
<?= $this->Html->link('Exportar apontamentos', ['_name' => 'pcm-sector-entries-excel', 'code' => $sector['code'], '?' => $sectorNavigationFilters], ['class' => 'btn btn-outline-primary mb-3 ms-2']) ?>
<p class="text-body-secondary small">As exportações processam todos os resultados dos filtros aplicados em lotes, sem limite total arbitrário.</p>
<p data-sector-notice role="status" class="text-body-secondary"><?= $sector['available'] ? '' : 'Dados do Protheus temporariamente indisponíveis.' ?></p>
<section class="pcm-panel pcm-sector-filter-panel p-3 mb-3"><h2 class="h5">Filtros do setor</h2>
<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Unidade" data-sector-unit-filter>
<?php foreach (\App\Service\Protheus\ProtheusUnit::BUTTONS as $key => $label): ?>
<?= $this->Html->link($label, ['_name' => 'pcm-sector', 'code' => $sector['code'], '?' => array_replace($sectorNavigationFilters, ['unit' => $key, 'page' => 1])], ['class' => 'btn ' . ($sector['filters']['unit'] === $key ? 'btn-primary' : 'btn-outline-primary'), 'aria-current' => $sector['filters']['unit'] === $key ? 'true' : null, 'data-unit-choice' => $key]) ?>
<?php endforeach; ?></nav>
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'pcm-sector-filter-fields', 'data-sector-filter-form' => true, 'data-unit' => $sector['filters']['unit']]) ?>
<?= $this->Form->hidden('unit', ['value' => $sector['filters']['unit']]) ?>
<div class="pcm-sector-equipment" data-equipment-autocomplete data-lookup="<?= h($this->Url->build(['_name' => 'pcm-equipment-suggestions'])) ?>">
<label class="form-label" for="filter-equipment">Bem / Equipamento</label>
<div class="pcm-sector-equipment-anchor">
<input class="form-control" id="filter-equipment" name="equipment_q" maxlength="100" value="<?= h($sector['filters']['equipment'] ?: $sector['filters']['equipment_q']) ?>" placeholder="Código, TAG ou nome" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="equipment-options" aria-expanded="false" data-search>
<input type="hidden" name="bem" value="<?= h($sector['filters']['equipment']) ?>" data-selected>
<input type="hidden" name="bem_filial" value="<?= h($sector['filters']['filial']) ?>" data-branch>
<div id="equipment-options" role="listbox" aria-label="Equipamentos encontrados" class="list-group pcm-sector-equipment-options shadow" hidden></div>
</div>
<small role="status" aria-live="polite" class="text-body-secondary">Selecione uma sugestão para filtrar o Bem e a filial.</small>
</div>
<?php foreach (['date_start' => 'Data inicial', 'date_end' => 'Data final'] as $key => $label): ?>
<div><?= $this->Form->control($key, ['type' => 'text', 'label' => $label, 'class' => 'form-control', 'placeholder' => 'dd/mm/aaaa', 'maxlength' => 10, 'pattern' => '\d{2}/\d{2}/\d{4}', 'value' => $sector['filters'][$key] === '' ? '' : implode('/', array_reverse(explode('-', $sector['filters'][$key])))]) ?></div>
<?php endforeach; ?>
<div class="pcm-sector-filter-actions"><button class="btn pcm-primary-action">Aplicar</button>
<?= $this->Html->link('Limpar', ['_name' => 'pcm-sector', 'code' => $sector['code']], ['class' => 'btn pcm-secondary-action']) ?></div>
<?= $this->Form->end() ?><p data-sector-draft-notice class="text-body-secondary small mt-2" hidden></p><small class="text-body-secondary">Período pelo início planejado, aplicado a todos os componentes da carteira.</small></section>
<?php if ($equipmentScopeEmpty && $sector['filters']['equipment'] !== '' && $sector['filters']['unit'] !== ''): ?>
<p class="alert alert-info">Nenhuma O.S. deste equipamento atende à unidade e aos demais filtros.
<?= $this->Html->link('Remover filtro de equipamento', ['_name' => 'pcm-sector', 'code' => $sector['code'], '?' => array_replace($sectorNavigationFilters, ['equipment' => '', 'equipment_q' => '', 'filial' => '', 'page' => 1])], ['class' => 'alert-link']) ?></p>
<?php endif; ?>
<div data-sector-content><?= $sector['available'] ? $this->element('protheus_sector_content', compact('sector')) : '' ?></div>
<small>Atualização automática a cada 5 minutos.</small>
</section>
