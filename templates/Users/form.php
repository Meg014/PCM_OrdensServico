<?php $this->assign('title', $user->isNew() ? 'Cadastrar usuário' : 'Editar usuário'); ?>
<header class="pcm-page-header"><h1><?= h($this->fetch('title')) ?></h1></header>
<section class="pcm-panel p-4">
<?= $this->Form->create($user) ?>
<div class="row g-3">
<?php foreach (['nome' => ['label' => 'Nome'], 'email' => ['label' => 'E-mail', 'type' => 'email'], 'role' => ['label' => 'Perfil', 'options' => ['USUARIO' => 'USUARIO', 'ADMIN' => 'ADMIN', 'TV' => 'TV']], 'maintenance_area_id' => ['label' => 'Setor', 'options' => $areas, 'empty' => 'Sem setor']] as $field => $options): ?>
<div class="col-md-6"><?= $this->Form->control($field, $options + ['class' => 'form-control']) ?></div>
<?php endforeach; ?>
<?php if ($user->isNew()): ?><div class="col-md-6"><?= $this->Form->control('password', ['label' => 'Senha (12 a 72 caracteres)', 'type' => 'password', 'value' => '', 'autocomplete' => 'new-password', 'class' => 'form-control']) ?></div><?php endif; ?>
<div class="col-12"><?= $this->Form->control('ativo', ['label' => 'Usuário ativo', 'type' => 'checkbox', 'default' => true]) ?></div>
</div><p class="text-body-secondary mt-3">O setor é informativo. ADMIN e USUARIO podem consultar todos os setores. TV acessa somente a apresentação.</p>
<button class="btn pcm-primary-action">Salvar</button> <?= $this->Html->link('Voltar', '/usuarios', ['class' => 'btn pcm-secondary-action']) ?>
<?= $this->Form->end() ?></section>
