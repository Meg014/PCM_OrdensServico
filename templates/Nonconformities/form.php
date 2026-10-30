<?php
use App\Service\NonconformityService as NC;
$this->assign('title', $record->isNew() ? 'Nova Não Conformidade' : 'Editar Não Conformidade');
echo $this->element('nonconformity_assets');
$this->Html->script('pcm-nonconformities', ['block' => true, 'defer' => true, 'timestamp' => 'force']);
$formValues ??= $record->toArray(); $items ??= [];
$value = static fn ($key, $default = '') => is_scalar($formValues[$key] ?? null) || ($formValues[$key] ?? null) instanceof \Cake\I18n\Date ? $formValues[$key] : $default;
$date = NC::iso($value('occurred_on', (new NC())->today()));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date ?? '')) $date = implode('/', array_reverse(explode('-', $date)));
?>
<header class="pcm-page-header"><h1><?= h($this->fetch('title')) ?></h1></header>
<?= $this->Form->create(null, ['data-nc-complete-form' => true, 'data-max-fields' => (int)ini_get('max_input_vars')]) ?>
<?= $this->Form->hidden('revision', ['value' => $value('revision', $record->revision ?? 0)]) ?>
<section class="pcm-panel p-4 mb-4"><h2 class="h5">Dados da NC</h2><div class="row g-3">
<?php foreach ([
    'number' => ['label' => 'NC nº', 'maxlength' => 80],
    'origin' => ['label' => 'Origem', 'type' => 'select', 'options' => array_combine(NC::ORIGINS, NC::ORIGINS), 'empty' => 'Selecione', 'required' => true],
    'occurred_on' => ['label' => 'Data NC', 'value' => $date, 'placeholder' => 'dd/mm/aaaa', 'maxlength' => 10, 'required' => true],
    'unit' => ['label' => 'Unidade / Setor', 'required' => true, 'maxlength' => 150],
] as $key => $options): ?><div class="col-12 col-md-6"><?= $this->Form->control($key, $options + ['type' => 'text', 'value' => $value($key), 'class' => 'form-control']) ?></div><?php endforeach; ?>
<div class="col-12"><?= $this->Form->control('description', ['type' => 'textarea', 'label' => 'Não conformidade (Se aplicável)', 'value' => $value('description'), 'class' => 'form-control', 'rows' => 4, 'maxlength' => 20000]) ?></div>
</div><p class="small text-body-secondary mt-2 mb-0">NC nº e Item NC podem ficar vazios. Nenhuma numeração é criada automaticamente.</p></section>
<section class="pcm-panel p-4 mb-4"><div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h5 mb-0">Itens / Ações</h2><button class="btn btn-outline-primary" type="submit" name="add_item" value="1" formnovalidate data-add-nc-item>+ Adicionar item</button></div>
<div data-nc-items data-next-index="<?= count($items) ?>">
<?php foreach (array_values($items) as $index => $itemValues): if (!is_array($itemValues)) continue; ?>
<fieldset class="pcm-nc-item mb-4" data-nc-item><legend class="h6">Item / Ação <?= $index + 1 ?></legend>
<?= $this->element('nonconformity_action_fields', ['prefix' => 'actions.' . $index . '.', 'domPrefix' => 'item-' . $index, 'itemValues' => $itemValues]) ?>
<?php if (empty($itemValues['id'])): ?><button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-remove-nc-item>Remover item não salvo</button><?php endif; ?></fieldset>
<?php endforeach; ?></div>
<template data-nc-item-template><fieldset class="pcm-nc-item mb-4" data-nc-item><legend class="h6">Novo item / ação</legend>
<?= $this->element('nonconformity_action_fields', ['prefix' => 'actions.__INDEX__.', 'domPrefix' => 'item-__INDEX__', 'itemValues' => ['status' => 'not_started']]) ?>
<button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-remove-nc-item>Remover item não salvo</button></fieldset></template>
<p class="small text-body-secondary mb-0">Quem? não exige login. Informe Data conclusão para Realizado = Concluída. A eficácia é registrada separadamente. Os itens já salvos não serão excluídos.</p></section>
<?= $this->Form->hidden('form_complete', ['value' => '1']) ?>
<p role="alert" data-nc-form-status class="text-danger"></p>
<button class="btn btn-primary">Salvar NC e itens</button> <?= $this->Html->link('Voltar', $record->isNew() ? '/pcm/nao-conformidades' : '/pcm/nao-conformidades/' . $record->id, ['class' => 'btn btn-outline-secondary']) ?>
<?= $this->Form->end() ?>
