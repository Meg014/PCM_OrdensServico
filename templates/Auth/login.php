<?php $this->assign('title', 'Entrar'); ?>
<div class="row justify-content-center py-5"><div class="col-12 col-md-6 col-lg-4">
    <section class="pcm-panel p-4">
        <p class="pcm-eyebrow">PCM | ACESSO</p><h1 class="h3">Entrar no PCM</h1>
        <p class="text-body-secondary">Informe seu e-mail e senha.</p>
        <?= $this->Form->create(null) ?>
        <div class="mb-3"><?= $this->Form->control('email', ['type' => 'email', 'label' => 'E-mail', 'required' => true, 'class' => 'form-control', 'autocomplete' => 'username']) ?></div>
        <div class="mb-4"><?= $this->Form->control('password', ['type' => 'password', 'label' => 'Senha', 'required' => true, 'value' => '', 'class' => 'form-control', 'autocomplete' => 'current-password']) ?></div>
        <button class="btn pcm-primary-action w-100">Entrar</button>
        <?= $this->Form->end() ?>
    </section>
</div></div>
