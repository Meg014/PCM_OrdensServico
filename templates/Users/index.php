<?php $this->assign('title', 'Usuários'); ?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | ADMINISTRAÇÃO</p><h1>Usuários</h1></div>
<?= $this->Html->link('Cadastrar usuário', '/usuarios/novo', ['class' => 'btn pcm-primary-action']) ?></header>
<div class="pcm-panel table-responsive"><table class="table align-middle">
<thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Setor</th><th>Status</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($users as $user): ?>
<tr><td><?= h($user->nome) ?></td><td><?= h($user->email) ?></td><td><?= h($user->role) ?></td><td><?= h($user->maintenance_area?->display_name ?? 'Sem setor') ?></td><td><?= $user->ativo ? 'Ativo' : 'Inativo' ?></td>
<td><?= $this->Html->link('Editar', '/usuarios/' . $user->id . '/editar', ['class' => 'btn btn-sm btn-outline-primary']) ?>
<?= $this->Html->link('Redefinir senha', '/usuarios/' . $user->id . '/senha', ['class' => 'btn btn-sm btn-outline-secondary']) ?><?php if ($user->role === 'TV'): ?>
<?= $this->Form->postLink('Revogar dispositivos TV', '/usuarios/' . $user->id . '/revogar-tv', ['class' => 'btn btn-sm btn-outline-danger', 'confirm' => 'Revogar todos os dispositivos desta TV?']) ?>
<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table>
<footer class="pcm-pagination"><p><?= $this->Paginator->counter('Página {{page}} de {{pages}} • {{count}} usuários') ?></p><ul class="pcm-page-list"><?= $this->Paginator->prev('Anterior') ?><?= $this->Paginator->numbers() ?><?= $this->Paginator->next('Próxima') ?></ul></footer></div>
