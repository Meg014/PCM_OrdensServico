<?php
use App\Service\NonconformityService as NC;
$prefix ??= ''; $domPrefix ??= 'nc-item'; $itemValues ??= [];
$value = static fn ($key, $default = '') => is_scalar($itemValues[$key] ?? null) || ($itemValues[$key] ?? null) instanceof \DateTimeInterface || ($itemValues[$key] ?? null) instanceof \Cake\I18n\Date ? $itemValues[$key] : $default;
$dateBr = static function ($v): string { $s = NC::iso($v) ?? ''; return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s) ? implode('/', array_reverse(explode('-', $s))) : $s; };
?>
<?= $this->Form->hidden($prefix . 'id', ['value' => $value('id'), 'id' => $domPrefix . '-id']) ?>
<?= $this->Form->hidden($prefix . 'revision', ['value' => $value('revision', 0), 'id' => $domPrefix . '-revision']) ?>
<div class="row g-3">
<?php foreach ([
    'item' => ['label' => 'Item NC', 'maxlength' => 80],
    'responsible_name' => ['label' => 'Quem? (Who)', 'required' => true, 'maxlength' => 150],
    'due_on' => ['label' => 'Quando? (When)', 'placeholder' => 'dd/mm/aaaa', 'maxlength' => 10, 'value' => $dateBr($value('due_on'))],
    'completed_on' => ['label' => 'Data conclusão', 'placeholder' => 'dd/mm/aaaa', 'maxlength' => 10, 'value' => $dateBr($value('completed_on'))],
    'status' => ['label' => 'Realizado', 'type' => 'select', 'options' => NC::STATUSES, 'value' => $value('status', 'not_started')],
] as $key => $options): ?><div class="col-12 col-md-6 col-xl-4"><?= $this->Form->control($prefix . $key, $options + ['type' => 'text', 'value' => $value($key), 'id' => $domPrefix . '-' . $key, 'class' => 'form-control']) ?></div><?php endforeach; ?>
<?php foreach (['measure' => 'Medida', 'description' => 'O Que? (What)', 'effectiveness' => 'Verificação da Eficácia', 'notes' => 'Observação'] as $key => $label): ?>
<div class="col-12 <?= $key === 'measure' ? 'col-xl-4' : '' ?>"><?= $this->Form->control($prefix . $key, ['type' => 'textarea', 'label' => $label, 'value' => $value($key), 'id' => $domPrefix . '-' . $key, 'class' => 'form-control', 'rows' => 3, 'required' => in_array($key, ['description', 'measure'], true), 'maxlength' => $key === 'effectiveness' ? 10000 : 20000]) ?></div>
<?php endforeach; ?></div>
