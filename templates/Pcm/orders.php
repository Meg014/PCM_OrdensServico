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
$areaName = static fn ($code) => \App\Model\Table\MaintenanceAreasTable::FRIENDLY_NAMES[$code ?? ''] ?? $display($code);
$statusName = static function (array $row): string {
    $situation = rtrim((string)($row['TJ_SITUACA'] ?? ''));
    $ending = rtrim((string)($row['TJ_TERMINO'] ?? ''));
    if ($situation === 'L' && $ending === 'N') return 'Aberta';
    if ($situation === 'L' && $ending === 'S') return 'Fechada';
    return ['C' => 'Cancelada', 'P' => 'Pendente'][$situation] ?? '—';
};
$pageUrl = static fn (int $page) => ['_name' => 'pcm-orders', '?' => $filters + ['page' => $page, 'limite' => $listing['limit']]];
?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | ORDENS DE SERVIÇO</p>
<h1>Ordens de Serviço</h1><span class="badge text-bg-secondary">Fonte: Protheus</span>
<p class="text-body-secondary mt-2">Consulta direta ao TOTVS.</p></div>
</header>
<?= $this->Html->link('Exportar Excel', ['_name' => 'pcm-orders-excel', '?' => $filters], ['class' => 'btn btn-outline-success mb-3']) ?>
<?= $this->Html->link('Exportar apontamentos', ['_name' => 'pcm-order-entries-excel', '?' => $filters], ['class' => 'btn btn-outline-primary mb-3 ms-2']) ?>
<p class="text-body-secondary small">As exportações processam todos os resultados em lotes, sem limite total arbitrário.</p>
<section class="pcm-panel p-3 mb-4">
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-3']) ?>
<?php if (!empty($filters['filial'])): ?><?= $this->Form->hidden('filial', ['value' => $filters['filial']]) ?><?php endif; ?>
<?php if (($filters['centro_modo'] ?? '') === 'blank' || ($filters['centro_modo'] ?? '') === 'null'): ?>
<?= $this->Form->hidden('centro_modo', ['value' => $filters['centro_modo']]) ?>
<div class="col-md-4"><label class="form-label">Centro de custo</label><input class="form-control" value="Sem centro de custo" disabled></div>
<?php endif; ?>
<?php foreach (['os' => 'Número da OS (com zeros à esquerda)', 'centro' => 'Centro de custo', 'bem' => 'Código do equipamento'] as $key => $label): ?>
<?php if ($key === 'centro' && in_array($filters['centro_modo'] ?? '', ['blank', 'null'], true)) continue; ?>
<div class="col-md-4"><label class="form-label" for="filter-<?= h($key) ?>"><?= h($label) ?></label>
<input class="form-control" id="filter-<?= h($key) ?>" name="<?= h($key) ?>" maxlength="100" value="<?= h($filters[$key] ?? '') ?>"></div>
<?php endforeach; ?>
<div class="w-100 d-none d-md-block"></div>
<div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-nome-bem">Nome do equipamento</label>
<input class="form-control" id="filter-nome-bem" name="nome_bem" maxlength="100" value="<?= h($filters['nome_bem'] ?? '') ?>" placeholder="Ex.: BOMBA"></div>
<?php foreach (['servico', 'tipo', 'situacao', 'termino', 'historico', 'unidade'] as $key): ?><?php if (($filters[$key] ?? '') !== ''): ?><?= $this->Form->hidden($key, ['value' => $filters[$key]]) ?><?php endif; ?><?php endforeach; ?>
<div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-area">Área/Setor</label>
<select class="form-select" id="filter-area" name="area"><option value="">Todos</option>
<?php foreach (($areas ?? []) as $area): ?><option value="<?= h($area) ?>"<?= ($filters['area'] ?? '') === $area ? ' selected' : '' ?>><?= h($area) ?></option><?php endforeach; ?>
</select></div>
<?php foreach (['date_start' => 'Data inicial', 'date_end' => 'Data final'] as $key => $label): ?>
<div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-<?= h($key) ?>"><?= h($label) ?></label>
<input type="date" class="form-control" id="filter-<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($filters[$key] ?? '') ?>" aria-describedby="origin-date-help"></div>
<?php endforeach; ?>
<div class="col-12 text-body-secondary" id="origin-date-help">Período pela Data de origem da OS.</div>
<div class="col-12"><button class="btn btn-primary" type="submit">Pesquisar</button>
<?= $this->Html->link('Limpar', ['_name' => 'pcm-orders'], ['class' => 'btn btn-outline-secondary']) ?>
<span class="text-body-secondary ms-2">Códigos exatos; nome do equipamento por trecho.</span></div>
<?= $this->Form->end() ?></section>
<?php if (!$listing['available']): ?>
<div class="alert alert-secondary" role="status">Ordens do Protheus temporariamente indisponíveis. Tente novamente em instantes.</div>
<?php else: ?>
<section class="pcm-dashboard-section pcm-equipment-history"><div class="pcm-panel"><div class="pcm-sector-table-scroll" role="region" aria-label="Ordens de Serviço — rolagem horizontal" tabindex="0">
<table class="table pcm-orders-table pcm-order-listing align-middle"><thead><tr><th class="pcm-order-number">O.S.</th><th class="pcm-order-description">Descrição</th><?php foreach (['Data de origem', 'Início real', 'Fim real', 'Equipamento', 'Serviço', 'Situação', 'Centro de custo', 'Área/Setor'] as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach ($listing['orders'] as $row): ?>
<tr><td class="pcm-order-number"><?= $this->Html->link($row['TJ_ORDEM'], ['_name' => 'pcm-protheus-order', 'number' => $row['TJ_ORDEM'], '?' => ['filial' => $row['TJ_FILIAL']]]) ?></td>
<td class="pcm-order-description"><?= h($display($row['descricao'] ?? null)) ?></td>
<td><?= h($originDate($row['origin_date'] ?? null)) ?></td>
<td><?= h($protheusDate($row['TJ_DTMRINI'] ?? null)) ?></td>
<td><?= h($protheusDate($row['TJ_DTMRFIM'] ?? null)) ?></td>
<td class="pcm-order-text"><?= h($display($row['TJ_CODBEM'])) ?><br><?= h($display($row['equipment_name'])) ?></td>
<td class="pcm-order-text"><?= h($display($row['TJ_SERVICO'])) ?><br><?= h($display($row['service_name'])) ?></td>
<td><?= h($statusName($row)) ?></td>
<td><?= h($display($row['TJ_CCUSTO'])) ?></td>
<td><?= h($areaName($row['TJ_CODAREA'] ?? null)) ?></td></tr>
<?php endforeach; ?>
<?php if ($listing['orders'] === []): ?><tr><td colspan="10" class="pcm-empty-table">Nenhuma OS encontrada nesta página para os filtros informados.</td></tr><?php endif; ?>
</tbody></table></div>
<footer class="pcm-pagination"><span>Página <?= h($listing['page']) ?></span><nav class="d-flex gap-3 align-items-center" aria-label="Paginação das ordens">
<?php if ($listing['page'] > 1): ?><?= $this->Html->link('Anterior', $pageUrl($listing['page'] - 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
<?php if ($listing['has_more']): ?><?= $this->Html->link('Ver mais', $pageUrl($listing['page'] + 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
</nav></footer></div></section>
<?php endif; ?>
