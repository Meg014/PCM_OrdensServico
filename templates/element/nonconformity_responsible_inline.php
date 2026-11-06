<?php
$registeredResponsibles ??= [];
$selected = null;
foreach ($registeredResponsibles as $contact) if ((int)$contact->id === (int)($itemValues['responsible_id'] ?? 0)) $selected = $contact;
$name = $itemValues['responsible_name'] ?? '';
// Historical name is provenance; display the linked current name without overwriting it.
if ($selected && !array_key_exists('responsible_email', $itemValues)) $name = $selected->name;
$email = $itemValues['responsible_email'] ?? $selected?->email ?? '';
$listId = $domPrefix . '-responsibles';
?>
<div class="col-12" data-nc-inline-responsible><div class="row g-3">
<div class="col-12 col-md-6">
<?= $this->Form->hidden($prefix . 'responsible_id', ['value' => $itemValues['responsible_id'] ?? '', 'id' => $domPrefix . '-responsible-id', 'data-nc-contact-id' => true]) ?>
<?= $this->Form->control($prefix . 'responsible_name', ['label' => 'Quem? (nome do responsável)', 'value' => $name, 'id' => $domPrefix . '-responsible-name', 'class' => 'form-control', 'list' => $listId, 'autocomplete' => 'off', 'maxlength' => 150, 'required' => true, 'data-nc-contact-name' => true]) ?>
<datalist id="<?= h($listId) ?>"><?php foreach ($registeredResponsibles as $contact): if (!$contact->active) continue; ?><option value="<?= h($contact->name) ?>" data-id="<?= (int)$contact->id ?>" data-email="<?= h($contact->email) ?>"><?= h($contact->email ?: 'E-mail pendente — revisar cadastro') ?></option><?php endforeach; ?></datalist>
</div><div class="col-12 col-md-6">
<?= $this->Form->control($prefix . 'responsible_email', ['type' => 'email', 'label' => 'E-mail *', 'value' => $email, 'id' => $domPrefix . '-responsible-email', 'class' => 'form-control', 'maxlength' => 254, 'required' => true, 'data-nc-required-email' => true, 'data-nc-contact-email' => true]) ?>
</div></div>
<p class="small text-body-secondary mt-2 mb-0">Selecione um nome sugerido ou informe nome e e-mail para cadastrar um novo responsável ao salvar. Para corrigir o e-mail de um cadastro existente, use <?= $this->Html->link('Responsáveis', '/pcm/nao-conformidades/responsaveis', ['target' => '_blank', 'rel' => 'noopener']) ?> e recarregue o formulário. Não é necessário login para o responsável.</p>
</div>
