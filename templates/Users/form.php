<?php $this->assign('title', $user->isNew() ? 'Cadastrar usuário' : 'Editar usuário'); ?>
<header class="pcm-page-header"><h1><?= h($this->fetch('title')) ?></h1></header>
<section class="pcm-panel p-4">
<?= $this->Form->create($user) ?>
<div class="row g-3">
<?php foreach (['nome' => ['label' => 'Nome'], 'email' => ['label' => 'E-mail', 'type' => 'email'], 'role' => ['label' => 'Perfil', 'options' => ['USUARIO' => 'USUARIO', 'ADMIN' => 'ADMIN', 'TV' => 'TV']], 'area_code' => ['label' => 'Setor Protheus (obrigatório para USUARIO)', 'options' => $areas, 'value' => $selectedAreaCode, 'empty' => 'Selecione o setor']] as $field => $options): ?>
<div class="col-md-6"><?= $this->Form->control($field, $options + ['class' => 'form-control']) ?></div>
<?php endforeach; ?>
<?php if ($user->isNew()): ?><div class="col-md-6"><?= $this->Form->control('password', ['label' => 'Senha (12 a 72 caracteres)', 'type' => 'password', 'value' => '', 'autocomplete' => 'new-password', 'class' => 'form-control']) ?></div><?php endif; ?>
<div class="col-12"><?= $this->Form->control('ativo', ['label' => 'Usuário ativo', 'type' => 'checkbox', 'default' => true]) ?></div>
</div><p class="text-body-secondary mt-3">USUARIO acessa somente seu setor. ADMIN acessa todos os setores. O perfil TV mantém acesso exclusivo à apresentação atual.</p>
<?php if (!$areasAvailable): ?><p class="alert alert-secondary">Setores do Protheus temporariamente indisponíveis. Não é possível atribuir um setor agora.</p><?php endif; ?>
<button class="btn pcm-primary-action">Salvar</button> <?= $this->Html->link('Voltar', '/usuarios', ['class' => 'btn pcm-secondary-action']) ?>
<?= $this->Form->end() ?></section>
