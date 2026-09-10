<?php
$this->assign('title', 'Qualidade dos dados');
?>
<header class="pcm-page-header">
    <div>
        <p class="pcm-eyebrow">PCM | QUALIDADE DOS DADOS</p>
        <h1><?= h($definition['label']) ?></h1>
        <p class="pcm-updated">
            <?= $currentImport ? 'Snapshot de ' . h($currentImport->report_date->format('d/m/Y')) : 'Nenhum snapshot disponível' ?>
        </p>
    </div>
    <div><?= $this->Html->link('Voltar para Análises', ['_name' => 'pcm-analyses'], ['class' => 'btn pcm-secondary-action']) ?></div>
</header>

<div class="alert pcm-quality-alert" role="note">
    <strong><?= h($definition['level']) ?>:</strong>
    esta listagem identifica possíveis inconsistências na fonte TOTVS. Nenhum dado é corrigido automaticamente.
</div>

<section class="pcm-panel" aria-labelledby="quality-table-title">
    <div class="pcm-panel-heading">
        <h2 id="quality-table-title">INCONSISTÊNCIAS ENCONTRADAS</h2>
    </div>
    <div class="table-responsive">
        <table class="table pcm-orders-table align-middle mb-0">
            <thead><tr><th>OS</th><th>Área</th><th>Equipamento/Bem</th><th>Serviço</th><th>Centro de custo</th><th>Tipo da inconsistência</th><th>Valor encontrado</th><th>Ação</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><strong><?= h($order->source_order_number) ?></strong></td>
                    <td><?= h($order->maintenance_area_code ?: '—') ?></td>
                    <td><?= h(trim(($order->equipment_code ?: '—') . ($order->equipment_name ? ' — ' . $order->equipment_name : ''))) ?></td>
                    <td><?= h($order->service_name ?: $order->service_code ?: '—') ?></td>
                    <td><?= h($order->cost_center_code ?: '—') ?></td>
                    <td><span class="badge pcm-quality-badge"><?= h($definition['level']) ?></span><br><small><?= h($definition['label']) ?></small></td>
                    <td class="pcm-quality-value"><?= h($values[(int)$order->id]) ?></td>
                    <td><?= $this->Html->link('Ver OS', ['_name' => 'pcm-order', 'id' => $order->id], ['class' => 'btn btn-sm btn-outline-primary']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($orders) === 0): ?><tr><td colspan="8" class="pcm-empty-table">Nenhuma ocorrência encontrada no snapshot atual.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($orders) > 0): ?>
        <footer class="pcm-pagination">
            <p><?= $this->Paginator->counter('Página {{page}} de {{pages}} • {{count}} OS') ?></p>
            <nav aria-label="Paginação"><ul class="pcm-page-list"><?= $this->Paginator->prev('‹ Anterior') ?><?= $this->Paginator->numbers(['modulus' => 5, 'first' => 1, 'last' => 1]) ?><?= $this->Paginator->next('Próxima ›') ?></ul></nav>
        </footer>
    <?php endif; ?>
</section>
