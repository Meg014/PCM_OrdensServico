<?php $this->assign('title', 'Redefinir senha'); ?>
<header class="pcm-page-header"><div><h1>Redefinir senha</h1><p><?= h($user->nome) ?> — <?= h($user->email) ?></p></div></header>
<section class="pcm-panel p-4">
<?= $this->Form->create($user) ?>
<?= $this->Form->control('password', ['label' => 'Nova senha (8 a 72 caracteres)', 'type' => 'password', 'value' => '', 'autocomplete' => 'new-password', 'class' => 'form-control']) ?>
<p class="text-body-secondary mt-3">As sessões anteriores deste usuário serão encerradas.
<?= $user->role === 'TV' ? 'A TV deverá entrar novamente, sem troca obrigatória de senha.' : 'A senha definida é temporária. No próximo acesso, o usuário deverá criar sua própria senha.' ?></p>
<button class="btn pcm-primary-action">Redefinir senha</button> <?= $this->Html->link('Voltar', '/usuarios', ['class' => 'btn pcm-secondary-action']) ?>
<?= $this->Form->end() ?></section>
