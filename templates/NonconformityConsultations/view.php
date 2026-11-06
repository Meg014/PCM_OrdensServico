<?php use App\Service\NonconformityService as NC; ?>
<form method="get" class="pcm-panel p-3 p-md-4 mb-4 pcm-nc-public-filters"><div class="row g-3 align-items-end">
<div class="col-md-6"><label for="q" class="form-label">Pesquisar nesta ação</label><input id="q" name="q" class="form-control" maxlength="150" value="<?= h($filters['q']) ?>"></div>
<div class="col-md-2"><label for="status" class="form-label">Realizado</label><select id="status" name="status" class="form-select"><option value="">Todos</option><?php foreach (NC::STATUSES as $key => $label): ?><option value="<?= h($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label for="deadline" class="form-label">Prazo</label><select id="deadline" name="deadline" class="form-select"><?php foreach (['' => 'Todos', 'overdue' => 'Atrasada', 'today' => 'Vence hoje', 'soon' => 'Próximos 5 dias', 'none' => 'Sem prazo'] as $key => $label): ?><option value="<?= h($key) ?>" <?= $filters['deadline'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2 d-grid"><button class="btn btn-primary pcm-primary-action">Filtrar</button></div>
</div></form>
<section class="pcm-panel pcm-nc-public-results" aria-labelledby="nc-results-heading">
<div class="pcm-nc-public-table-heading"><h2 id="nc-results-heading">Acompanhamento da ação</h2><p>Deslize a tabela para visualizar todas as colunas.</p></div>
<?= $this->element('nonconformity_table', compact('rows', 'canManage', 'publicConsult')) ?>
</section>
