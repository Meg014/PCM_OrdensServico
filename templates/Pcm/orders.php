<?php
$this->assign('title', 'Ordens de Serviço — Protheus');
$filters = $listing['filters'];
$display = static fn ($value) => $value === null || $value === '' ? '—' : (string)$value;
$pageUrl = static fn (int $page) => ['_name' => 'pcm-orders', '?' => $filters + ['page' => $page, 'limite' => $listing['limit']]];
?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | ORDENS DE SERVIÇO</p>
<h1>Ordens de Serviço</h1><span class="badge text-bg-secondary">Fonte: Protheus</span>
<p class="text-body-secondary mt-2">Consulta direta ao TOTVS. Códigos de tipo, situação e término são exibidos sem interpretação.</p></div>
</header>
<?= $this->Html->link('Exportar Excel', ['_name' => 'pcm-orders-excel', '?' => $filters], ['class' => 'btn btn-outline-success mb-3']) ?>
<p class="text-body-secondary small">Exporta todos os resultados dos filtros aplicados, até 5.000 OS.</p>
<section class="pcm-panel p-3 mb-4">
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-3']) ?>
<?php if (!empty($filters['filial'])): ?><?= $this->Form->hidden('filial', ['value' => $filters['filial']]) ?><?php endif; ?>
<?php foreach (['os' => 'Número da OS (com zeros à esquerda)', 'centro' => 'Centro de custo', 'bem' => 'Código do equipamento'] as $key => $label): ?>
<div class="col-md-4"><label class="form-label" for="filter-<?= h($key) ?>"><?= h($label) ?></label>
<input class="form-control" id="filter-<?= h($key) ?>" name="<?= h($key) ?>" maxlength="100" value="<?= h($filters[$key] ?? '') ?>"></div>
<?php endforeach; ?>
<?php foreach (['date_start' => 'Data inicial', 'date_end' => 'Data final'] as $key => $label): ?>
<div class="col-md-4"><label class="form-label" for="filter-<?= h($key) ?>"><?= h($label) ?></label>
<input type="date" class="form-control" id="filter-<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($filters[$key] ?? '') ?>" aria-describedby="reference-date-help"></div>
<?php endforeach; ?>
<div class="col-12 text-body-secondary" id="reference-date-help">Período opcional da Data de referência, incluindo as datas inicial e final.</div>
<div class="col-12"><button class="btn btn-primary" type="submit">Pesquisar</button>
<?= $this->Html->link('Limpar', ['_name' => 'pcm-orders'], ['class' => 'btn btn-outline-secondary']) ?>
<span class="text-body-secondary ms-2">Pesquisa por códigos exatos.</span></div>
<?= $this->Form->end() ?></section>
<?php if (!$listing['available']): ?>
<div class="alert alert-secondary" role="status">Ordens do Protheus temporariamente indisponíveis. Tente novamente em instantes.</div>
<?php else: ?>
<section class="pcm-panel p-3"><div class="table-responsive"><table class="table table-hover align-middle">
<thead><tr><th>Filial / OS</th><th>Data de referência</th><th>Equipamento</th><th>Serviço</th><th>Área / centro de custo</th><th>Tipo / situação / término (brutos)</th></tr></thead>
<tbody>
<?php foreach ($listing['orders'] as $row): ?>
<tr><td><?= h($row['TJ_FILIAL'] === '' ? '(em branco)' : $row['TJ_FILIAL']) ?> /
<?= $this->Html->link($row['TJ_ORDEM'], ['_name' => 'pcm-protheus-order', 'number' => $row['TJ_ORDEM'], '?' => ['filial' => $row['TJ_FILIAL']]]) ?></td>
<td><?= h($display($row['reference_date'])) ?></td>
<td><?= h($display($row['TJ_CODBEM'])) ?><br><?= h($display($row['equipment_name'])) ?></td>
<td><?= h($display($row['TJ_SERVICO'])) ?><br><?= h($display($row['service_name'])) ?></td>
<td><?= h($display($row['TJ_CODAREA'])) ?> / <?= h($display($row['TJ_CCUSTO'])) ?></td>
<td><?= h($display($row['TJ_TIPO'])) ?> / <?= h($display($row['TJ_SITUACA'])) ?> / <?= h($display($row['TJ_TERMINO'])) ?></td></tr>
<?php endforeach; ?>
<?php if ($listing['orders'] === []): ?><tr><td colspan="6">Nenhuma OS encontrada nesta página para os filtros informados.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="d-flex gap-3 align-items-center" aria-label="Paginação das ordens">
<?php if ($listing['page'] > 1): ?><?= $this->Html->link('Anterior', $pageUrl($listing['page'] - 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
<span>Página <?= h($listing['page']) ?></span>
<?php if ($listing['has_more']): ?><?= $this->Html->link('Ver mais', $pageUrl($listing['page'] + 1), ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
</nav></section>
<?php endif; ?>
