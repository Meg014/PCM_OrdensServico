<?php $this->assign('title', 'OS ' . $identity['source_order_number']); ?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | ORDENS DE SERVIÇO</p>
<h1>OS <?= h($identity['source_order_number']) ?></h1>
<p>Filial: <?= h($identity['branch_code'] === '' ? '(em branco)' : $identity['branch_code']) ?>
<span class="badge text-bg-secondary">Fonte: Protheus</span></p></div>
<?= $this->Html->link('Pesquisar ordens', ['_name' => 'pcm-orders'], ['class' => 'btn btn-outline-secondary']) ?></header>
<?= $this->element('protheus_order', ['protheusUrl' => $this->Url->build([
    '_name' => 'pcm-protheus-order-data', 'number' => $identity['source_order_number'],
    '?' => ['filial' => $identity['branch_code']],
])]) ?>
