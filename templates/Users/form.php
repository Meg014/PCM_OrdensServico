<?php $this->assign('title', $user->isNew() ? 'Cadastrar usuário' : 'Editar usuário'); ?>
<header class="pcm-page-header"><h1><?= h($this->fetch('title')) ?></h1></header>
<section class="pcm-panel p-4">
<?= $this->Form->create($user) ?>
<div class="row g-3">
<?php foreach (['nome' => ['label' => 'Nome/identificação'], 'email' => ['label' => 'E-mail usado para login', 'type' => 'email'], 'role' => ['label' => 'Perfil', 'options' => ['USUARIO' => 'USUARIO', 'ADMIN' => 'ADMIN', 'TV' => 'TV']], 'area_code' => ['label' => 'Setor Protheus (opcional, informativo)', 'options' => $areas, 'value' => $selectedAreaCode, 'empty' => 'Sem alteração de setor']] as $field => $options): ?>
<div class="col-md-6"><?= $this->Form->control($field, $options + ['class' => 'form-control']) ?></div>
<?php endforeach; ?>
<?php if ($user->isNew()): ?><div class="col-md-6"><?= $this->Form->control('password', ['label' => 'Senha inicial (12 a 72 caracteres)', 'type' => 'password', 'value' => '', 'autocomplete' => 'new-password', 'class' => 'form-control']) ?><small>Temporária para ADMIN/USUARIO; a conta TV não exige troca.</small></div><?php endif; ?>
<div class="col-12"><?= $this->Form->control('ativo', ['label' => 'Usuário ativo', 'type' => 'checkbox', 'default' => true]) ?></div>
</div><p class="text-body-secondary mt-3">ADMIN e USUARIO consultam todos os setores, O.S. e históricos. Somente ADMIN administra usuários. TV acessa exclusivamente o Modo Apresentação. O setor associado não restringe o acesso.</p>
<?php if (!$areasAvailable): ?><p class="alert alert-secondary">Setores do Protheus temporariamente indisponíveis. Não é possível atribuir um setor agora.</p><?php endif; ?>
<button class="btn pcm-primary-action">Salvar</button> <?= $this->Html->link('Voltar', '/usuarios', ['class' => 'btn pcm-secondary-action']) ?>
<?php if (!$user->isNew()): ?><?= $this->Html->link('Redefinir senha', '/usuarios/' . $user->id . '/senha', ['class' => 'btn pcm-secondary-action']) ?><?php endif; ?>
<?= $this->Form->end() ?></section>
