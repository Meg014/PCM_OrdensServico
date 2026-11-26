<?php
$this->assign('title', 'Ordens de Serviço — Protheus');
$filters = $listing['filters'];
$display = static fn ($value) => $value === null || trim((string)$value) === '' ? '—' : rtrim((string)$value);
$originDate = static function ($value): string {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        return '—';
    }

    return $parts[3] . '/' . $parts[2] . '/' . $parts[1];
};
$protheusDate = static function ($value): string {
    $iso = (new \App\Service\Protheus\Presentation\OrderSupplementMapper())->date($value);
    return $iso ? implode('/', array_reverse(explode('-', $iso))) : '—';
};
$originDateHasIssue = static function (array $row): bool {
    $flags = \App\Service\OrderAuditFlags::forOrder(['origin_date' => $row['origin_date'] ?? null]);
    return isset($flags['invalid_date']) || isset($flags['date_out_of_range']);
};
$areaName = static fn ($code) => \App\Model\Table\MaintenanceAreasTable::FRIENDLY_NAMES[$code ?? ''] ?? $display($code);
$statusName = static function (array $row): string {
    $situation = rtrim((string)($row['TJ_SITUACA'] ?? ''));
    $ending = rtrim((string)($row['TJ_TERMINO'] ?? ''));
    if ($situation !== 'C' && $ending !== 'S') return 'Aberta';
    if (\App\Service\Protheus\ProtheusOperationalEligibility::isClosed($situation, $ending)) return 'Fechada';
    return ['C' => 'Cancelada', 'P' => 'Pendente'][$situation] ?? '—';
};
$navigationFilters = array_filter($filters, static fn ($value): bool => $value !== '');
$pageUrl = static fn (int $page) => ['_name' => 'pcm-orders',
    '?' => array_replace($navigationFilters, ['page' => $page, 'limite' => $listing['limit']])];
?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | ORDENS DE SERVIÇO</p>
<h1>Ordens de Serviço</h1><span class="badge text-bg-secondary">Fonte: Protheus</span>
<p class="text-body-secondary mt-2">Consulta direta ao TOTVS.</p></div>
</header>
<?= $this->Html->link('Exportar Excel', ['_name' => 'pcm-orders-excel', '?' => $navigationFilters], ['class' => 'btn btn-outline-success mb-3']) ?>
<?= $this->Html->link('Exportar apontamentos', ['_name' => 'pcm-order-entries-excel', '?' => $navigationFilters], ['class' => 'btn btn-outline-primary mb-3 ms-2']) ?>
<p class="text-body-secondary small">As exportações processam todos os resultados em lotes, sem limite total arbitrário.</p>
<section class="pcm-panel p-3 mb-4">
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-3']) ?>
<?php if (!empty($filters['filial'])): ?><?= $this->Form->hidden('filial', ['value' => $filters['filial']]) ?><?php endif; ?>
<?php foreach (['centro', 'centro_modo'] as $key): ?><?php if (($filters[$key] ?? '') !== ''): ?><?= $this->Form->hidden($key, ['value' => $filters[$key]]) ?><?php endif; ?><?php endforeach; ?>
<div class="col-12 col-md-4"><label class="form-label" for="filter-os">Número da OS (com zeros à esquerda)</label>
<input class="form-control" id="filter-os" name="os" maxlength="100" value="<?= h($filters['os'] ?? '') ?>"></div>
<div class="col-12 col-md-4"><?= $this->Form->control('initial_user', ['label' => 'Usuário de início',
    'empty' => 'Todos', 'options' => $initialUsers ?? [], 'value' => $filters['initial_user'] ?? '', 'class' => 'form-select']) ?></div>
<div class="w-100 d-none d-md-block"></div>
<div class="col-12 col-md-6 position-relative" data-equipment-autocomplete data-lookup="<?= h($this->Url->build(['_name' => 'pcm-equipment-suggestions'])) ?>">
<label class="form-label" for="filter-equipment">Bem / Equipamento</label>
<input class="form-control" id="filter-equipment" name="equipment_q" maxlength="100" value="<?= h(($filters['equipment_q'] ?? '') ?: (($filters['bem'] ?? '') ?: ($filters['nome_bem'] ?? ''))) ?>"
    placeholder="Digite o código ou nome do equipamento" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="equipment-options" aria-expanded="false" aria-describedby="equipment-message" data-search>
<input type="hidden" name="bem" value="<?= h($filters['bem'] ?? '') ?>" data-selected>
<input type="hidden" name="bem_filial" value="<?= h($filters['bem_filial'] ?? '') ?>" data-branch>
<div id="equipment-options" role="listbox" aria-label="Equipamentos encontrados" class="list-group position-absolute bg-white shadow w-100" style="z-index:10;max-height:20rem;overflow:auto" hidden></div>
<small id="equipment-message" class="text-body-secondary" role="status" aria-live="polite">Selecione uma sugestão para filtrar o equipamento exato; sem seleção, a busca usa trechos do código ou nome.</small>
</div>
<div class="col-12 col-md-6 col-xl-3"><?= $this->Form->control('service_scope', [
    'label' => 'Tipo de serviço', 'id' => 'filter-service-scope', 'class' => 'form-select',
    'options' => ['' => 'Todos os serviços', 'checklist' => 'Rotas de Checklist'],
    'value' => $filters['service_scope'] ?? '',
]) ?></div>
<div class="col-12 col-md-6 col-xl-3"><?= $this->Form->control('checklist_service', [
    'label' => 'Serviço de Checklist', 'id' => 'filter-checklist-service', 'class' => 'form-select',
    'empty' => 'Todas as rotas', 'options' => $checklistServices ?? [],
    'value' => $filters['checklist_service'] ?? '', 'disabled' => ($filters['service_scope'] ?? '') !== 'checklist',
]) ?></div>
<?php foreach (['servico', 'tipo', 'termino', 'historico', 'safra', 'analitico', 'card', 'card_status',
    'backlog_age', 'q', 'status', 'service_name', 'period_start', 'period_end'] as $key): ?><?php if (($filters[$key] ?? '') !== ''): ?><?= $this->Form->hidden($key, ['value' => $filters[$key]]) ?><?php endif; ?><?php endforeach; ?>
<div class="col-12 col-md-6 col-xl-3"><?= $this->Form->control('unidade', ['label' => 'Unidade', 'empty' => 'Todas',
    'options' => \App\Service\Protheus\ProtheusUnit::LABELS, 'value' => $filters['unidade'] ?? '', 'class' => 'form-select']) ?></div>
<div class="col-12 col-md-6 col-xl-3"><?= $this->Form->control('order_status', ['label' => 'Situação',
    'options' => ['all' => 'Padrão', 'open' => 'Abertas', 'closed' => 'Fechadas', 'cancelled' => 'Canceladas'],
    'value' => ($filters['order_status'] ?? '') ?: (($filters['situacao'] ?? '') === 'C' ? 'cancelled' : 'all'), 'class' => 'form-select']) ?></div>
<div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-area">Área/Setor</label>
<select class="form-select" id="filter-area" name="area"><option value="">Todos</option>
<?php foreach (($areas ?? []) as $area): ?><option value="<?= h($area) ?>"<?= ($filters['area'] ?? '') === $area ? ' selected' : '' ?>><?= h($areaName($area)) ?></option><?php endforeach; ?>
</select></div>
<?php foreach (['date_start' => 'Data inicial', 'date_end' => 'Data final'] as $key => $label): ?>
<div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-<?= h($key) ?>"><?= h($label) ?></label>
<input type="date" class="form-control" id="filter-<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($filters[$key] ?? '') ?>" aria-describedby="origin-date-help"></div>
<?php endforeach; ?>
<div class="col-12 col-xl-6">
<div class="pcm-order-total" role="status" aria-live="polite">
<svg class="pcm-order-total-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 9h6M9 13h6M9 17h4"/></svg>
<div><p class="pcm-order-total-label">TOTAL DE O.S. ENCONTRADAS</p>
<strong class="pcm-order-total-value"><?= $listing['available'] && isset($listing['total']) ? h(number_format($listing['total'], 0, ',', '.')) : '&mdash;' ?></strong>
<?php if (!$listing['available'] || !isset($listing['total'])): ?><small class="d-block text-body-secondary">Contagem indisponível</small><?php endif; ?>
</div></div></div>
<div class="col-12 text-body-secondary" id="origin-date-help">Período pela Data de origem da OS.</div>
<?php if (!empty($filters['period_start'])): ?><p class="text-body-secondary">Recorte do dashboard: <?= h($filters['period_start']) ?> a <?= h($filters['period_end']) ?>. Fechadas pela data geral TJ_DTPRFIM; abertas por origem. O filtro de emissão abaixo usa somente origem.</p><?php endif; ?>
<?php if (($filters['situacao'] ?? '') === 'C'): ?><p class="text-body-secondary">Consulta histórica de canceladas. Estas O.S. não entram nos indicadores de abertas ou fechadas.</p><?php endif; ?>
<div class="col-12"><button class="btn btn-primary" type="submit">Pesquisar</button>
<?= $this->Html->link('Limpar', ['_name' => 'pcm-orders'], ['class' => 'btn btn-outline-secondary']) ?>
<span class="text-body-secondary ms-2">Para consultar canceladas, selecione a situação ou informe o número exato da O.S.</span></div>
<?= $this->Form->end() ?></section>
<?php if (($filters['analitico'] ?? '') === '1'): ?><p class="alert alert-secondary">Escopo analítico: Data de origem a partir de 01/01/2025.</p><?php endif; ?>
<?php if (!empty($listing['drilldown'])): ?><p class="alert alert-secondary pcm-active-drilldown"><strong>Filtro ativo: <?= h($areaName($filters['area'] ?? '')) ?> · <?= h($listing['drilldown']['label']) ?></strong>
<span class="d-block"><?= h(number_format($listing['drilldown']['total'], 0, ',', '.')) ?> O.S. encontradas</span>
<?= $this->Html->link('Limpar drill-down', ['_name' => 'pcm-orders', '?' => array_filter(['area' => $filters['area'] ?? '', 'unidade' => $filters['unidade'] ?? ''])], ['class' => 'alert-link']) ?></p><?php endif; ?>
<?php if (!$listing['available']): ?>
<div class="alert alert-secondary" role="status">Ordens do Protheus temporariamente indisponíveis. Tente novamente em instantes.</div>
<?php else: ?>
<section class="pcm-dashboard-section pcm-equipment-history"><div class="pcm-panel"><div class="pcm-sector-table-scroll" role="region" aria-label="Ordens de Serviço — rolagem horizontal" tabindex="0">
<table class="table pcm-orders-table pcm-order-listing align-middle"><thead><tr><th class="pcm-order-number">O.S.</th><th class="pcm-order-description">Descrição</th><?php foreach (['Data de origem', 'Início real', 'Fim real', 'Equipamento', 'Serviço', 'Situação', 'Centro de custo', 'Área/Setor'] as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach ($listing['orders'] as $row): ?>
<tr><td class="pcm-order-number"><?= $this->Html->link($row['TJ_ORDEM'], ['_name' => 'pcm-protheus-order', 'number' => $row['TJ_ORDEM'], '?' => ['filial' => $row['TJ_FILIAL'], 'unit' => $filters['unidade'] ?? '']], ['class' => $this->OrderNumber->colorClass($row['TJ_SITUACA'] ?? null)]) ?><?= $this->element('order_audit_flags', ['order' => $row, 'excludeFlags' => ['invalid_date', 'date_out_of_range']]) ?></td>
<td class="pcm-order-description"><?= $this->element('order_description', ['description' => \App\Service\Protheus\OrderDescriptions::listing($row)]) ?></td>
<td<?= $originDateHasIssue($row) ? ' class="text-warning fw-semibold"' : '' ?>><?= h($originDate($row['origin_date'] ?? null)) ?></td>
<td><?= h($protheusDate(\App\Service\Protheus\OrderDates::pair($row, 'actual_start')[0])) ?></td>
<td><?= h($protheusDate(\App\Service\Protheus\OrderDates::pair($row, 'actual_end')[0])) ?></td>
<td class="pcm-order-text"><?= h($display($row['TJ_CODBEM'])) ?><br><?= h($display($row['equipment_name'])) ?></td>
<td class="pcm-order-text"><?= h($display($row['TJ_SERVICO'])) ?><br><?= h($display($row['service_name'])) ?></td>
<td><?= h($statusName($row)) ?></td>
<td><?= h($display($row['TJ_CCUSTO'])) ?><?= $this->element('protheus_unit_label', ['costCenter' => $row['TJ_CCUSTO'] ?? null]) ?></td>
<td><?= h($areaName($row['TJ_CODAREA'] ?? null)) ?></td></tr>
<?php endforeach; ?>
<?php if ($listing['orders'] === []): ?><tr><td colspan="10" class="pcm-empty-table">Nenhuma OS encontrada nesta página para os filtros informados.</td></tr><?php endif; ?>
</tbody></table></div>
<footer class="pcm-pagination"><span>Página <?= h($listing['page']) ?></span><nav class="d-flex gap-3 align-items-center" aria-label="Paginação das ordens">
<?php if ($listing['page'] > 1): ?><?= $this->Html->link('Anterior', $pageUrl($listing['page'] - 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
<?php if ($listing['has_more']): ?><?= $this->Html->link('Ver mais', $pageUrl($listing['page'] + 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
</nav></footer></div></section>
<?php endif; ?>
<?= $this->Html->script('pcm-equipment-autocomplete', ['block' => true]) ?>
<?= $this->Html->script('pcm-checklist-filter', ['block' => true]) ?>
