<?php $this->assign('title', 'Gerenciar Não Conformidades'); ?>
<header class="pcm-page-header"><h1>Gerenciar Não Conformidades</h1></header>
<section class="pcm-panel p-4">
<p>Usuário selecionado: <strong><?= h($user->nome) ?></strong>.</p>
<p>Responsável atual: <strong><?= h($manager?->nome ?? 'Nenhum') ?></strong>.</p>
<p>Somente um usuário pode ter esta permissão. Administradores podem editar o módulo sem ela, mas não recebem alertas automaticamente.</p>
<?php if ($user->ativo && in_array($user->role, ['ADMIN', 'USUARIO'], true)): ?>
<?= $this->Form->create(null) ?>
<?= $this->Form->hidden('revision', ['value' => $assignment->revision]) ?>
<?= $this->Form->hidden('mode', ['value' => (int)$assignment->user_id === (int)$user->id ? 'revoke' : 'grant']) ?>
<?php if ($manager): ?>
<div class="alert alert-warning">Já existe um responsável ativo: <?= h($manager->nome) ?>. A alteração preserva os registros e históricos anteriores.</div>
<?= $this->Form->control('confirmed', ['type' => 'checkbox', 'value' => '1', 'required' => true, 'label' => (int)$assignment->user_id === (int)$user->id ? 'Confirmo a revogação da permissão' : 'Confirmo a substituição do responsável atual por este usuário']) ?>
<?php endif; ?>
<button class="btn btn-primary mt-3"><?= (int)$assignment->user_id === (int)$user->id ? 'Revogar permissão' : 'Conceder permissão' ?></button>
<?= $this->Form->end() ?>
<?php else: ?><p class="alert alert-secondary">Somente contas ativas ADMIN ou USUARIO podem ser designadas. TV permanece restrito à apresentação.</p><?php endif; ?>
<?= $this->Html->link('Voltar aos usuários', '/usuarios', ['class' => 'btn btn-outline-secondary mt-3']) ?>
</section>
