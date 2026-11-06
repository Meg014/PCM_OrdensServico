<?php
use App\Service\NonconformityService as NC;
$prefix ??= ''; $domPrefix ??= 'nc-item'; $itemValues ??= [];
$value = static fn ($key, $default = '') => is_scalar($itemValues[$key] ?? null) || ($itemValues[$key] ?? null) instanceof \DateTimeInterface || ($itemValues[$key] ?? null) instanceof \Cake\I18n\Date ? $itemValues[$key] : $default;
$dateBr = static function ($v): string { $s = NC::iso($v) ?? ''; return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s) ? implode('/', array_reverse(explode('-', $s))) : $s; };
?>
<?= $this->Form->hidden($prefix . 'id', ['value' => $value('id'), 'id' => $domPrefix . '-id']) ?>
<?= $this->Form->hidden($prefix . 'revision', ['value' => $value('revision', 0), 'id' => $domPrefix . '-revision']) ?>
<div class="row g-3">
<?= $this->element('nonconformity_responsible_inline', ['prefix' => $prefix, 'domPrefix' => $domPrefix, 'itemValues' => $itemValues]) ?>
<?php foreach ([
    'item' => ['label' => 'Item NC', 'maxlength' => 80],
    'due_on' => ['label' => 'Quando? (When)', 'placeholder' => 'DD/MM/AAAA', 'maxlength' => 10, 'value' => $dateBr($value('due_on')), 'inputmode' => 'numeric', 'pattern' => '[0-9]{2}/[0-9]{2}/[0-9]{4}', 'title' => 'Data no formato DD/MM/AAAA', 'data-nc-date' => true],
    'completed_on' => ['label' => 'Data conclusão', 'placeholder' => 'DD/MM/AAAA', 'maxlength' => 10, 'value' => $dateBr($value('completed_on')), 'inputmode' => 'numeric', 'pattern' => '[0-9]{2}/[0-9]{2}/[0-9]{4}', 'title' => 'Data no formato DD/MM/AAAA', 'data-nc-date' => true],
    'status' => ['label' => 'Realizado', 'type' => 'select', 'options' => NC::STATUSES, 'value' => $value('status', 'not_started')],
] as $key => $options): ?><div class="col-12 col-md-6 col-xl-4"><?= $this->Form->control($prefix . $key, $options + ['type' => 'text', 'value' => $value($key), 'id' => $domPrefix . '-' . $key, 'class' => 'form-control']) ?></div><?php endforeach; ?>
<div class="col-12 col-md-6 col-xl-4"><?= $this->Form->control($prefix . 'measure', ['type' => 'select', 'label' => 'Medida', 'options' => array_combine(NC::MEASURES, NC::MEASURES) + ($value('id') && !in_array($value('measure'), NC::MEASURES, true) ? [$value('measure') => $value('measure') . ' (histórico — manter)'] : []), 'empty' => 'Selecione', 'value' => $value('measure'), 'id' => $domPrefix . '-measure', 'class' => 'form-select', 'required' => true]) ?></div>
<?php foreach (['description' => 'O Que? (What)', 'effectiveness' => 'Verificação da Eficácia', 'notes' => 'Observação'] as $key => $label): ?>
<div class="col-12 <?= $key === 'measure' ? 'col-xl-4' : '' ?>"><?= $this->Form->control($prefix . $key, ['type' => 'textarea', 'label' => $label, 'value' => $value($key), 'id' => $domPrefix . '-' . $key, 'class' => 'form-control', 'rows' => 3, 'required' => in_array($key, ['description', 'measure'], true), 'maxlength' => $key === 'effectiveness' ? 10000 : 20000]) ?></div>
<?php endforeach; ?></div>
