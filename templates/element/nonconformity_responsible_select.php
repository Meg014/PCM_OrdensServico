<?php
$registeredResponsibles ??= []; $selected ??= null; $legacyName ??= ''; $fieldName ??= 'responsible_id'; $formId ??= null; $required ??= false;
$parts = explode('.', $fieldName); $fieldName = array_shift($parts) . implode('', array_map(static fn ($part) => '[' . $part . ']', $parts));
?>
<div data-nc-responsible-picker>
<input type="search" class="form-control form-control-sm mb-1" aria-label="Pesquisar responsável por nome" placeholder="Pesquisar nome…" data-nc-responsible-search autocomplete="off">
<select class="form-select form-select-sm" name="<?= h($fieldName) ?>" aria-label="Quem?" <?= $formId ? 'form="' . h($formId) . '"' : '' ?> <?= $required ? 'required' : '' ?>>
<option value=""><?= h($legacyName !== '' && !$selected ? $legacyName . ' — sem vínculo de e-mail' : 'Selecione um responsável') ?></option>
<?php foreach ($registeredResponsibles as $r): if (!$r->active && (int)$r->id !== (int)$selected) continue; $emailPending = !filter_var($r->email, FILTER_VALIDATE_EMAIL); ?>
<option value="<?= (int)$r->id ?>" <?= (int)$r->id === (int)$selected ? 'selected' : ($emailPending ? 'disabled' : '') ?>><?= h($r->name . (!$r->active ? ' (inativo)' : '') . ($emailPending ? ' (e-mail pendente)' : '')) ?></option>
<?php endforeach; ?></select></div>
