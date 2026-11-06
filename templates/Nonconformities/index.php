<?php
use App\Service\NonconformityService as NC;
$this->assign('title', 'Não Conformidades'); echo $this->element('nonconformity_assets');
$this->Html->script('pcm-nonconformities', ['block' => true, 'defer' => true, 'timestamp' => 'force']);
?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | QUALIDADE</p><h1>Não Conformidades</h1></div>
<?php if ($canManage): ?><div class="d-flex gap-2 flex-wrap"><?= $this->Html->link('Responsáveis', '/pcm/nao-conformidades/responsaveis', ['class' => 'btn btn-outline-primary']) ?><?= $this->Html->link('Nova Não Conformidade', '/pcm/nao-conformidades/nova', ['class' => 'btn btn-primary']) ?></div><?php endif; ?></header>
<details class="pcm-nc-timeline mb-3"><summary>Como funcionam os avisos de prazo?</summary>
<p class="mt-2"><strong>Exemplo: ação com vencimento em 20/11</strong> <span class="badge text-bg-secondary">Exemplo ilustrativo</span></p>
<ol><li><strong>10/11 — Ação cadastrada</strong><br>O responsável recebe um e-mail informando a nova atividade.</li>
<li><strong>15/11 — Faltam 5 dias</strong><br>Novo e-mail avisando que o prazo está próximo.</li>
<li><strong>19/11 — Falta 1 dia</strong><br>Lembrete urgente.</li>
<li><strong>20/11 — Vencimento</strong><br>A ação aparece como vence hoje; se não for concluída, passa a atrasada no dia seguinte.</li>
<li><strong>21/11 — Primeiro dia de atraso</strong><br>Lembrete inicial; depois, no máximo um a cada 7 dias enquanto pendente.</li></ol>
<p class="small mt-2 mb-0">Os e-mails exigem responsável ativo com e-mail válido e serviço de envio habilitado. Ações concluídas deixam de receber lembretes.</p></details>
<section class="pcm-panel p-3 mb-3">
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'pcm-nc-filters']) ?>
<?= $this->Form->control('q', ['label' => 'Pesquisar', 'value' => $filters['q'], 'class' => 'form-control form-control-sm', 'maxlength' => 150, 'placeholder' => 'NC, item, unidade ou texto']) ?>
<?php foreach ([
    'status' => ['Realizado', NC::STATUSES],
    'responsible' => ['Quem?', $responsibles ? array_combine($responsibles, $responsibles) : []],
    'origin' => ['Origem', array_combine(NC::ORIGINS, NC::ORIGINS)],
    'deadline' => ['Quando?', ['overdue' => 'Atrasada', 'today' => 'Vence hoje', 'soon' => 'Próximos 5 dias', 'none' => 'Sem prazo']],
] as $key => [$label, $options]): ?><?= $this->Form->control($key, ['label' => $label, 'type' => 'select', 'empty' => 'Todos', 'options' => $options, 'value' => $filters[$key], 'class' => 'form-select form-select-sm']) ?><?php endforeach; ?>
<div class="d-flex gap-2"><button class="btn btn-sm btn-primary">Aplicar</button><?= $this->Html->link('Limpar', '/pcm/nao-conformidades', ['class' => 'btn btn-sm btn-outline-secondary']) ?></div>
<?= $this->Form->end() ?></section>
<?php if (!$canManage): ?><p class="small text-body-secondary">Acesso para consulta. Histórico e textos completos estão disponíveis em Detalhes.</p><?php endif; ?>
<section class="pcm-panel"><?= $this->element('nonconformity_table', ['rows' => $records, 'canManage' => $canManage]) ?>
<footer class="pcm-pagination"><span><?= $this->Paginator->counter('Página {{page}} de {{pages}} · {{count}} linhas') ?></span><ul class="pcm-page-list"><?= $this->Paginator->prev('Anterior') ?><?= $this->Paginator->numbers() ?><?= $this->Paginator->next('Próxima') ?></ul></footer></section>
