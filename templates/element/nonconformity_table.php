<?php
use App\Service\NonconformityService as NC;
$dateBr = static function ($v): string { $s = NC::iso($v); return $s ? (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s) ? implode('/', array_reverse(explode('-', $s))) : $s) : ''; };
$badge = ['overdue' => 'text-bg-danger', 'today' => 'text-bg-danger', 'urgent' => 'text-bg-warning', 'attention' => 'text-bg-warning', 'completed' => 'text-bg-success', 'on_time' => 'text-bg-success', 'none' => 'text-bg-secondary'];
?>
<div class="pcm-nc-table-scroll" tabindex="0" role="region" aria-label="Tabela de acompanhamento de Não Conformidades">
<table class="table pcm-nc-sheet align-top mb-0"><thead><tr>
<?php foreach (['NC nº','Item NC','Origem','Data NC','Não conformidade','Medida','O Que?','Quem?','Quando?','Data conclusão','Realizado','Verificação da Eficácia','Observação','Unidade / Setor','Ações'] as $label): ?><th scope="col"><?= h($label) ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($rows as $row): $hasAction = $row->action_id !== null; $formId = 'quick-action-' . (int)$row->action_id; $tone = $hasAction ? NC::deadlineTone($row->due_on, $row->status) : 'none'; ?>
<tr id="<?= $hasAction ? 'acao-' . (int)$row->action_id : 'nc-' . (int)$row->id ?>" data-nc-row data-deadline-tone="<?= h($tone) ?>">
<td><?= $this->Html->link($row->number ?? 'Sem numeração', '/pcm/nao-conformidades/' . $row->id) ?>
<?php if ($canManage && $hasAction): ?>
<?= $this->Form->create(null, ['id' => $formId, 'url' => '/pcm/nao-conformidades/' . $row->id . '/acoes/' . $row->action_id . '/rapida', 'data-nc-quick-form' => true]) ?>
<?= $this->Form->hidden('revision', ['value' => $row->action_revision]) ?><?= $this->Form->end() ?>
<?php endif; ?></td>
<td><?= h($row->item ?? ($hasAction ? 'Sem numeração' : 'Sem itens')) ?></td>
<td><?= h($row->origin) ?></td><td class="text-nowrap"><?= h($dateBr($row->occurred_on)) ?></td>
<td><?= $this->element('nonconformity_text', ['text' => $row->description]) ?></td>
<td><?= $this->element('nonconformity_text', ['text' => $row->measure]) ?></td>
<td><?= $this->element('nonconformity_text', ['text' => $row->action_description]) ?></td>
<td><?php if ($canManage && $hasAction): ?><input class="form-control form-control-sm" form="<?= h($formId) ?>" name="responsible_name" aria-label="Quem?" required maxlength="150" value="<?= h($row->responsible_name) ?>"><?php else: ?><?= h($row->responsible_name ?? '—') ?><?php endif; ?></td>
<td><?php if ($canManage && $hasAction): ?><input class="form-control form-control-sm" form="<?= h($formId) ?>" name="due_on" aria-label="Quando?" maxlength="10" placeholder="dd/mm/aaaa" value="<?= h($dateBr($row->due_on)) ?>"><?php else: ?><?= h($dateBr($row->due_on) ?: '—') ?><?php endif; ?>
<?php if ($hasAction): ?><span data-nc-deadline class="badge mt-2 <?= h($badge[$tone]) ?>"><?= h(NC::deadline($row->due_on, $row->status)) ?></span><?php endif; ?></td>
<td><?php if ($canManage && $hasAction): ?><input class="form-control form-control-sm" form="<?= h($formId) ?>" name="completed_on" aria-label="Data conclusão" maxlength="10" placeholder="dd/mm/aaaa" value="<?= h($dateBr($row->completed_on)) ?>"><?php else: ?><?= h($dateBr($row->completed_on) ?: '—') ?><?php endif; ?></td>
<td><?php if ($canManage && $hasAction): ?><select class="form-select form-select-sm" form="<?= h($formId) ?>" name="status" aria-label="Realizado"><?php foreach (NC::STATUSES as $key => $label): ?><option value="<?= h($key) ?>"<?= $row->status === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select><?php else: ?><span class="badge <?= $row->status === 'completed' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h(NC::STATUSES[$row->status ?? ''] ?? '—') ?></span><?php endif; ?></td>
<td><?= $this->element('nonconformity_text', ['text' => $row->effectiveness]) ?></td>
<td><?php if ($canManage && $hasAction): ?><textarea class="form-control form-control-sm" form="<?= h($formId) ?>" name="notes" aria-label="Observação" rows="3" maxlength="20000"><?= h($row->notes) ?></textarea><?php else: ?><?= $this->element('nonconformity_text', ['text' => $row->notes]) ?><?php endif; ?></td>
<td><?= h($row->unit) ?></td>
<td><div class="pcm-nc-row-actions">
<?php if ($canManage && $hasAction): ?><button type="submit" form="<?= h($formId) ?>" class="btn btn-sm btn-primary">Salvar linha</button><?= $this->Html->link('Editar item', '/pcm/nao-conformidades/' . $row->id . '/acoes/' . $row->action_id . '/editar', ['class' => 'btn btn-sm btn-outline-primary']) ?><?php endif; ?>
<?= $this->Html->link('Detalhes e histórico', '/pcm/nao-conformidades/' . $row->id . ($hasAction ? '#acao-' . $row->action_id : ''), ['class' => 'btn btn-sm btn-outline-secondary']) ?>
<?php if ($canManage): ?><?= $this->Html->link('Editar NC e itens', '/pcm/nao-conformidades/' . $row->id . '/editar', ['class' => 'btn btn-sm btn-outline-secondary']) ?><?php endif; ?>
<span role="status" aria-live="polite" data-nc-save-status class="small"></span></div></td>
</tr><?php endforeach; ?>
<?php if (count($rows) === 0): ?><tr><td colspan="15" class="p-4">Nenhum registro encontrado.</td></tr><?php endif; ?>
</tbody></table></div>
