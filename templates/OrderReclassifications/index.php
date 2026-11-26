<?php
$this->assign('title', 'Reclassificação de O.S.');
$numberLink = fn(array $r, ?string $status) => $this->Html->link($r['number'], [
    '_name' => 'pcm-protheus-order', 'number' => $r['number'], '?' => ['filial' => $r['branch']],
], ['class' => $this->OrderNumber->colorClass($status)]);
$name = static fn($code) => \App\Model\Table\MaintenanceAreasTable::FRIENDLY_NAMES[$code] ?? ($code ?: 'Sem área');
$pages = function (string $key, int $page, bool $next) {
    foreach (['Anterior' => $page - 1, 'Próxima' => $page + 1] as $label => $target) {
        if ($target < 1 || ($target > $page && !$next)) continue;
        echo $this->Html->link($label, ['?' => array_replace($this->request->getQueryParams(), [$key => $target])], ['class' => 'btn btn-outline-secondary btn-sm me-2']);
    }
};
?>
<h1>Reclassificação de O.S.</h1>
<?php if (!$enabled): ?>
<p class="alert alert-info">A reclassificação requer o banco local do PCM em PostgreSQL.</p>
<?php return; endif; ?>
<section class="card card-body mb-3">
<h2 class="h5">Validar lote</h2>
<?= $this->Form->create(null) ?>
<?= $this->Form->hidden('operation', ['value' => 'preview']) ?>
<div class="row g-3">
<div class="col-sm-3"><?= $this->Form->control('branch', ['label' => 'Filial TOTVS', 'default' => '01', 'required' => true, 'maxlength' => 20, 'class' => 'form-control']) ?></div>
<div class="col-sm-6"><?= $this->Form->control('destination', ['label' => 'Área de destino', 'options' => $areas, 'empty' => 'Selecione', 'required' => true, 'class' => 'form-select']) ?></div>
<div class="col-md-6"><?= $this->Form->control('numbers', ['type' => 'textarea', 'label' => 'Números das O.S.', 'rows' => 4, 'required' => true, 'class' => 'form-control']) ?><small>Cole uma coluna do Excel ou separe os números por espaço, vírgula ou ponto e vírgula.</small></div>
<div class="col-md-6"><?= $this->Form->control('reason', ['type' => 'textarea', 'label' => 'Justificativa', 'rows' => 4, 'required' => true, 'maxlength' => 2000, 'class' => 'form-control']) ?></div>
</div>
<?= $this->Form->button('Validar no TOTVS', ['class' => 'btn btn-primary mt-3']) ?>
<?= $this->Form->end() ?></section>
<?php if ($preview): ?>
<section class="card card-body mb-3"><h2 class="h5">Prévia da filial <?= h($preview['branch']) ?></h2>
<p><?= h($preview['reason']) ?></p>
<?= $this->Form->create(null) ?>
<?= $this->Form->hidden('operation', ['value' => 'confirm']) ?>
<?= $this->Form->hidden('token', ['value' => $preview['token']]) ?>
<div class="table-responsive"><table class="table align-middle"><thead><tr>
<?php foreach (['Incluir', 'O.S.', 'Descrição', 'Equipamento', 'Serviço', 'Situação', 'Área original', 'Destino', 'Validação'] as $label): ?><th><?= h($label) ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($preview['rows'] as $r): ?><tr><td>
<?php if ($r['issue'] === ''): ?><input class="form-check-input" type="checkbox" name="selected[]" checked value="<?= h($r['number']) ?>" aria-label="Incluir O.S. <?= h($r['number']) ?>"><?php endif; ?>
</td><td><?= $r['issue'] === 'Inexistente' ? h($r['number']) : $numberLink($r, $r['status']) ?></td><?php foreach ([$r['description'], $r['equipment'], $r['service'], $r['status'], $name($r['original']), $name($r['destination']), $r['issue'] ?: 'Disponível'] as $value): ?><td><?= h($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<?= $this->Form->button('Confirmar selecionadas', ['class' => 'btn btn-primary', 'disabled' => !in_array('', array_column($preview['rows'], 'issue'), true)]) ?>
<?= $this->Form->end() ?></section><?php endif; ?>
<section class="card card-body mb-3"><h2 class="h5">Reclassificações ativas</h2>
<?php if (!$active): ?><p>Nenhuma reclassificação nesta página.</p><?php else: ?>
<?= $this->Form->create(null) ?>
<?= $this->Form->hidden('operation', ['value' => 'reverse']) ?>
<div class="table-responsive"><table class="table"><thead><tr><th>Reverter</th><th>Filial</th><th>O.S.</th><th>Área original na transferência</th><th>Destino</th></tr></thead><tbody>
<?php foreach (array_slice($active, 0, 50) as $r): ?><tr><td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Reverter O.S. <?= h($r['number']) ?>"></td>
<td><?= h($r['branch']) ?></td><td><?= $numberLink($r, $situations[$r['branch']][$r['number']] ?? null) ?></td><td><?= h($name($r['original_area'])) ?></td><td><?= h($name($r['destination'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?= $this->Form->control('reason', ['id' => 'reversal-reason', 'label' => 'Justificativa da reversão', 'required' => true, 'maxlength' => 2000, 'class' => 'form-control']) ?>
<?= $this->Form->button('Reverter selecionadas', ['class' => 'btn btn-outline-danger my-2']) ?>
<?= $this->Form->end() ?>
<?php endif; ?><nav><?php $pages('active_page', $activePage, count($active) > 50) ?></nav></section>
<section class="card card-body"><h2 class="h5">Histórico</h2>
<?php if (!$history): ?><p>Nenhum evento nesta página.</p><?php else: ?>
<div class="table-responsive"><table class="table"><thead><tr>
<?php foreach (['Data/hora (Brasília)', 'Usuário', 'Operação', 'Filial', 'O.S.', 'Área anterior', 'Destino', 'Justificativa'] as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach (array_slice($history, 0, 50) as $r): ?><tr><?php foreach ([$r['created'], $r['actor'], $r['operation'], $r['branch']] as $value): ?><td><?= h($value) ?></td><?php endforeach; ?><td><?= $numberLink($r, $situations[$r['branch']][$r['number']] ?? null) ?></td><?php foreach ([$name($r['previous_area']), $name($r['destination']), $r['reason']] as $value): ?><td><?= h($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?><nav><?php $pages('history_page', $historyPage, count($history) > 50) ?></nav></section>
