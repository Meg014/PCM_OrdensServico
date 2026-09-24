<?php $this->assign('title', $required ? 'Alterar senha obrigatória' : 'Meu Perfil'); ?>
<header class="pcm-page-header"><div><h1><?= h($required ? 'Crie sua própria senha' : 'Meu Perfil') ?></h1>
<p><?= h($user->nome) ?></p></div></header>
<section class="pcm-panel p-4">
<?php if ($required): ?><p class="alert alert-info">Sua senha é temporária. Defina uma nova senha para acessar o PCM.</p><?php endif; ?>
<?= $this->Form->create($user, ['url' => '/meu-perfil', 'type' => 'post']) ?>
<?php if (!$required): ?><div class="mb-3"><?= $this->Form->control('current_password', ['type' => 'password', 'label' => 'Senha atual', 'value' => '', 'required' => true, 'autocomplete' => 'current-password', 'class' => 'form-control']) ?></div><?php endif; ?>
<div class="mb-3"><?= $this->Form->control('new_password', ['type' => 'password', 'label' => 'Nova senha (12 a 72 caracteres, até 72 bytes)', 'value' => '', 'required' => true, 'autocomplete' => 'new-password', 'class' => 'form-control']) ?></div>
<div class="mb-3"><?= $this->Form->control('password_confirm', ['type' => 'password', 'label' => 'Confirme a nova senha', 'value' => '', 'required' => true, 'autocomplete' => 'new-password', 'class' => 'form-control']) ?></div>
<button class="btn pcm-primary-action">Alterar senha</button>
<?php if (!$required): ?><?= $this->Html->link('Voltar', '/pcm', ['class' => 'btn pcm-secondary-action']) ?><?php endif; ?>
<?= $this->Form->end() ?>
<p class="text-body-secondary mt-3">Dados cadastrais e e-mail de login são alterados somente pelo ADMIN. Para redefinir uma senha esquecida, procure o ADMIN.</p>
</section>
