<?php
$this->assign('title', $action->isNew() ? 'Nova ação' : 'Atualizar ação'); echo $this->element('nonconformity_assets');
?>
<header class="pcm-page-header"><div><h1><?= h($this->fetch('title')) ?></h1><p>NC nº <?= h($record->number ?? 'Sem numeração') ?> · <?= h($record->unit) ?></p></div></header>
<section class="pcm-panel p-4"><?= $this->Form->create(null) ?>
<?= $this->element('nonconformity_action_fields', ['itemValues' => $action->toArray()]) ?>
<p class="small text-body-secondary mt-3">Data conclusão deve ser informada para Realizado = Concluída. A eficácia é independente da conclusão.</p>
<button class="btn btn-primary">Salvar ação</button> <?= $this->Html->link('Voltar à NC', '/pcm/nao-conformidades/' . $record->id, ['class' => 'btn btn-outline-secondary']) ?>
<?= $this->Form->end() ?></section>
