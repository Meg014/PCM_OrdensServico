<?php use App\Service\NonconformityService as NC; ?>
BIOLASA | PCM QUALIDADE
<?= $heading ?>

<?php if ($isTest): ?>TESTE CONTROLADO — Dados ilustrativos. Esta mensagem não representa uma atribuição real.

<?php endif; ?>
Olá, <?= $responsible->name ?>!

<?= $meta['intro'] ?>

<?php foreach (['NC' => $nc->number ?? 'Sem número', 'Item NC' => $action->item ?? 'Sem número', 'Responsável' => $responsible->name,
    'Origem' => $nc->origin, 'Descrição da não conformidade' => $nc->description, 'Medida' => $action->measure, 'O Que? — Atividade' => $action->description,
    'Prazo final' => $action->due_on?->format('d/m/Y') ?? 'Sem prazo', 'Status' => NC::STATUSES[$action->status]] as $label => $value): ?>
<?= $label ?>: <?= $value ?>

<?php endforeach; ?>
<?= $meta['notice'] ?>

Consultar ação no PCM: <?= $link ?>