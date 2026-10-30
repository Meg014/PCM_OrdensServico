<?php
use App\Service\NonconformityService as NC;
$this->assign('title', 'NC ' . ($record->number ?? 'Sem numeração')); echo $this->element('nonconformity_assets');
$this->Html->script('pcm-nonconformities', ['block' => true, 'defer' => true, 'timestamp' => 'force']);
?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | NÃO CONFORMIDADES</p><h1><?= h($this->fetch('title')) ?></h1><p><?= h($record->unit) ?> · <?= h($record->origin) ?> · <?= h($record->occurred_on->format('d/m/Y')) ?></p></div>
<div class="d-flex gap-2 flex-wrap"><?= $this->Html->link('Voltar', '/pcm/nao-conformidades', ['class' => 'btn btn-outline-secondary']) ?>
<?php if ($canManage): ?><?= $this->Html->link('Editar NC', '/pcm/nao-conformidades/' . $record->id . '/editar', ['class' => 'btn btn-outline-primary']) ?><?= $this->Html->link('Adicionar ação', '/pcm/nao-conformidades/' . $record->id . '/acoes/nova', ['class' => 'btn btn-primary']) ?><?php endif; ?></div></header>
<section class="pcm-panel p-4 mb-4"><h2 class="h5">Não conformidade</h2><p class="pcm-nc-text mb-0"><?= h($record->description) ?></p></section>
<section class="mb-4"><h2 class="h4">Itens / Ações</h2><div class="pcm-panel">
<?php $rows = $actions->map(static fn ($a) => (object)[
    'id' => $record->id, 'number' => $record->number, 'unit' => $record->unit, 'origin' => $record->origin, 'occurred_on' => $record->occurred_on, 'description' => $record->description,
    'action_id' => $a->id, 'item' => $a->item, 'measure' => $a->measure, 'action_description' => $a->description, 'responsible_name' => $a->responsible_name,
    'due_on' => $a->due_on, 'completed_on' => $a->completed_on, 'status' => $a->status, 'effectiveness' => $a->effectiveness, 'notes' => $a->notes, 'action_revision' => $a->revision,
])->toArray(); ?>
<?= $this->element('nonconformity_table', ['rows' => $rows, 'canManage' => $canManage]) ?></div></section>
<section class="pcm-panel p-4"><h2 class="h4">Histórico de alterações</h2><p class="small text-body-secondary">Registros preservados com autoria e valores anteriores e posteriores.</p>
<?php foreach ($history as $event): ?>
<details class="pcm-nc-history mb-2"><summary><?= h($event->created->format('d/m/Y H:i:s')) ?> · <?= h($event->actor_name) ?> · <?= $event->object_type === 'action' ? 'Ação' : 'NC' ?> #<?= (int)$event->object_id ?> · <?= $event->operation === 'create' ? 'Cadastro' : 'Atualização' ?></summary>
<?php foreach (['before_json' => 'Antes', 'after_json' => 'Depois'] as $key => $label): ?><h3 class="h6 mt-3"><?= h($label) ?></h3><dl class="row">
<?php foreach ((json_decode($event->$key, true) ?? []) as $field => $value): ?><dt class="col-md-4"><?= h(['number'=>'NC n°','unit'=>'Unidade / Setor','origin'=>'Origem','occurred_on'=>'Data NC','description'=>'Descrição','item'=>'Item NC','measure'=>'Medida','responsible_name'=>'Responsável','due_on'=>'Prazo','completed_on'=>'Conclusão','status'=>'Status','effectiveness'=>'Eficácia','notes'=>'Observação','revision'=>'Revisão','created'=>'Cadastro','modified'=>'Atualização','deadline_cycle'=>'Ciclo de prazo','id'=>'ID','nonconformity_id'=>'NC interna'][$field] ?? $field) ?></dt><dd class="col-md-8 pcm-nc-text"><?= h($field === 'status' ? (NC::STATUSES[$value] ?? $value) : (is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE))) ?></dd><?php endforeach; ?></dl><?php endforeach; ?>
</details><?php endforeach; ?>
<nav class="d-flex gap-3 mt-3" aria-label="Histórico"><?= $this->Paginator->prev('Anterior') ?><?= $this->Paginator->counter('Página {{page}} de {{pages}}') ?><?= $this->Paginator->next('Próxima') ?></nav></section>
