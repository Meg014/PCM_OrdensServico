<?php
$this->assign('title', 'Responsáveis'); echo $this->element('nonconformity_assets');
$this->Html->script('pcm-nonconformities', ['block' => true, 'defer' => true, 'timestamp' => 'force']);
$v = static fn ($key, $default = '') => is_scalar($values[$key] ?? null) ? $values[$key] : $default;
?>
<div class="pcm-nc-responsibles">
<header class="pcm-page-header">
    <div><p class="pcm-eyebrow">PCM | NÃO CONFORMIDADES</p><h1>Responsáveis</h1><p class="pcm-nc-subtitle">Organize os contatos e vincule as pessoas às ações de melhoria.</p></div>
    <?= $this->Html->link('← Voltar às NCs', '/pcm/nao-conformidades', ['class' => 'btn btn-outline-secondary']) ?>
</header>
<div class="pcm-nc-responsible-grid">
<section class="pcm-panel pcm-nc-contact-form">
    <div class="pcm-nc-section-heading"><div><span class="pcm-nc-kicker">CADASTRO</span><h2><?= $responsible->isNew() ? 'Novo responsável' : 'Editar responsável' ?></h2><p>Preencha os dados de contato.</p></div></div>
    <?= $this->Form->create(null) ?>
    <?= $this->Form->hidden('revision', ['value' => $v('revision', 0)]) ?>
    <?php foreach (['name' => ['Nome completo', 'text', 150, 'Ex.: Maria da Silva'], 'email' => ['E-mail', 'email', 254, 'nome@empresa.com.br'], 'sector' => ['Setor', 'text', 150, 'Ex.: Qualidade']] as $key => [$label, $type, $max, $placeholder]): ?>
    <div class="pcm-nc-contact-field"><?= $this->Form->control($key, ['label' => $label . ($key !== 'sector' ? ' *' : ' (opcional)'), 'type' => $type, 'value' => $v($key), 'maxlength' => $max, 'required' => $key !== 'sector', 'placeholder' => $placeholder, 'class' => 'form-control'] + ($key === 'email' ? ['data-nc-required-email' => true, 'pattern' => '[^\s@]+@[^\s@]+\.[^\s@]+', 'title' => 'Informe um endereço de e-mail válido, como nome@empresa.com.br.'] : [])) ?></div>
    <?php endforeach; ?>
    <div class="pcm-nc-contact-field"><?= $this->Form->control('active', ['label' => 'Situação', 'type' => 'select', 'options' => [1 => 'Ativo', 0 => 'Inativo'], 'value' => $v('active', 1), 'class' => 'form-select']) ?></div>
    <div class="pcm-nc-contact-note">Os responsáveis receberão notificações automáticas por e-mail sobre novas ações e prazos de vencimento. Não é necessário possuir login no PCM.</div>
    <div class="pcm-nc-contact-buttons"><button class="btn btn-primary">Salvar responsável</button><?= $this->Html->link('Novo cadastro', '/pcm/nao-conformidades/responsaveis', ['class' => 'btn btn-outline-secondary']) ?></div>
    <?= $this->Form->end() ?>
</section>
<div class="pcm-nc-responsible-content">
<section class="pcm-panel pcm-nc-contact-list">
    <div class="pcm-nc-section-heading"><div><span class="pcm-nc-kicker">CONTATOS</span><h2>Cadastrados <span class="pcm-nc-count"><?= count($registeredResponsibles) ?></span></h2><p>Pessoas disponíveis para vincular às ações.</p></div></div>
    <?php if (!$registeredResponsibles): ?>
    <div class="pcm-nc-empty"><span class="pcm-nc-empty-symbol" aria-hidden="true">＋</span><h3>Nenhum responsável cadastrado</h3><p>Preencha o formulário para adicionar o primeiro contato.</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table pcm-nc-admin-table"><thead><tr><th scope="col">Nome</th><th scope="col">E-mail</th><th scope="col">Setor</th><th scope="col">Situação</th><th scope="col">Ações</th></tr></thead><tbody>
    <?php foreach ($registeredResponsibles as $r): ?><tr><td><strong><?= h($r->name) ?></strong></td><td><?= h($r->email ?? 'Sem e-mail') ?></td><td><?= h($r->sector ?: '—') ?></td><td><span class="pcm-nc-person-status <?= $r->active ? 'is-active' : '' ?>"><?= $r->active ? 'Ativo' : 'Inativo' ?></span></td><td><?= $this->Html->link('Editar', '/pcm/nao-conformidades/responsaveis/' . $r->id, ['class' => 'btn btn-sm btn-outline-secondary']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<section class="pcm-panel pcm-nc-association">
    <div class="pcm-nc-section-heading"><div><span class="pcm-nc-kicker">VÍNCULOS</span><h2>Associação assistida</h2><p>Confira os nomes existentes e selecione o cadastro correto. O texto original e o histórico são preservados.</p></div></div>
    <?php if (count($legacyActions) === 0): ?>
    <div class="pcm-nc-empty pcm-nc-empty-inline"><span class="pcm-nc-empty-symbol" aria-hidden="true">✓</span><div><h3>Nenhum vínculo pendente</h3><p>Quando houver ações com nomes sem cadastro vinculado, elas aparecerão aqui.</p></div></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table pcm-nc-admin-table"><thead><tr><th scope="col">Ação</th><th scope="col">Nome original</th><th scope="col">Atividade</th><th scope="col">Ações</th></tr></thead><tbody>
    <?php foreach ($legacyActions as $a): ?><tr><td class="text-nowrap">NC #<?= (int)$a->nonconformity_id ?> / <?= h($a->item ?? $a->id) ?></td><td><strong><?= h($a->responsible_name) ?></strong><span class="pcm-nc-table-caption">Sem e-mail vinculado</span></td><td><?= h(mb_substr($a->description, 0, 160)) ?></td><td><?= $this->Html->link('Vincular responsável', '/pcm/nao-conformidades/' . $a->nonconformity_id . '/acoes/' . $a->id . '/editar', ['class' => 'btn btn-sm btn-outline-primary text-nowrap']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <footer class="pcm-pagination"><span><?= $this->Paginator->counter('Página {{page}} de {{pages}} · {{count}} ações') ?></span><ul class="pcm-page-list"><?= $this->Paginator->prev('Anterior') ?><?= $this->Paginator->numbers() ?><?= $this->Paginator->next('Próxima') ?></ul></footer>
    <?php endif; ?>
</section>
<div class="pcm-nc-operations">
<details class="pcm-panel pcm-nc-admin-details">
    <summary><span><strong>Histórico de responsáveis</strong><small>Últimos 30 eventos de cadastro e edição</small></span><span class="pcm-nc-details-chevron" aria-hidden="true">⌄</span></summary>
    <div class="pcm-nc-details-content">
    <?php if (count($responsibleHistory) === 0): ?><p class="pcm-nc-muted-empty">Nenhuma alteração registrada até o momento.</p><?php endif; ?>
    <?php foreach ($responsibleHistory as $e): ?><details class="pcm-nc-history"><summary><?= h($e->created->format('d/m/Y H:i')) ?> · <?= h($e->actor_name) ?> · #<?= (int)$e->object_id ?></summary><div class="pcm-nc-audit-values"><?php foreach (['before_json' => 'Antes', 'after_json' => 'Depois'] as $key => $label): ?><div><h3><?= h($label) ?></h3><pre><?= h(json_encode(json_decode($e->$key), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></div><?php endforeach; ?></div></details><?php endforeach; ?>
    </div>
</details>
<details class="pcm-panel pcm-nc-admin-details">
    <summary><span><strong>Fila de e-mails</strong><small>Últimos 50 registros de envio</small></span><span class="pcm-nc-details-chevron" aria-hidden="true">⌄</span></summary>
    <div class="pcm-nc-details-content">
    <?php if (count($mailQueue) === 0): ?><p class="pcm-nc-muted-empty">Nenhum e-mail na fila. Os próximos avisos aparecerão aqui.</p><?php else: ?>
    <div class="table-responsive"><table class="table pcm-nc-admin-table"><thead><tr><th scope="col">ID / Ação</th><th scope="col">Destinatário</th><th scope="col">Tipo</th><th scope="col">Agendado</th><th scope="col">Enviado</th><th scope="col">Status / Tentativas</th><th scope="col">Erro</th></tr></thead><tbody>
    <?php foreach ($mailQueue as $m): ?><tr><td>#<?= (int)$m->id ?> / #<?= (int)$m->action_id ?></td><td><?= h($m->recipient) ?></td><td><?= h($m->event) ?></td><td><?= h($m->scheduled_at) ?></td><td><?= h($m->sent_at ?? '—') ?></td><td><?= h($m->status) ?> / <?= (int)$m->attempts ?></td><td><?= h($m->error ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    </div>
</details>
</div>
</div>
</div>
</div>
