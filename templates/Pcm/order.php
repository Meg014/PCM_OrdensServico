<?php $this->assign('title', 'OS ' . $snapshot->source_order_number); ?>
<header class="pcm-page-header"><div><p class="pcm-eyebrow">PCM | ORDEM DE SERVIÇO</p><h1>OS <?= h($snapshot->source_order_number) ?></h1><p class="pcm-updated">Snapshot de <strong><?= h($snapshot->report_date->format('d/m/Y')) ?></strong></p></div><div><?= $this->Html->link('Voltar ao setor', ['_name' => 'pcm-sector', 'code' => $snapshot->maintenance_area_code], ['class' => 'btn btn-outline-secondary']) ?></div></header>
<div class="row g-4">
<?php $blocks = [
    'Identificação e classificação' => ['Filial' => $snapshot->branch_code, 'Área Manut.' => $snapshot->maintenance_area_code,
        'Centro de Custo' => $snapshot->cost_center_code, 'Serviço' => $snapshot->service_code,
        'Nome Serviço' => $snapshot->service_name, 'Tipo Manut.' => $snapshot->maintenance_type,
        'Equipamento' => $snapshot->equipment_code, 'Nome do bem' => $snapshot->equipment_name,
        'Tipo O.S.' => $snapshot->order_type, 'Data de origem' => $snapshot->origin_date?->format('d/m/Y'),
        'Situação' => $snapshot->source_situation, 'Término' => $snapshot->finished_raw, 'STATUS' => $snapshot->treated_status],
    'PLANEJAMENTO / REGISTRO' => [
        'P. In. Man. — data/hora' => $this->PcmTime->format($snapshot->maintenance_planned_start),
        'P. Fim Man. — data/hora' => $this->PcmTime->format($snapshot->maintenance_planned_end)],
    'EXECUÇÃO REAL' => [
        'Real. Início — data/hora' => $this->PcmTime->format($snapshot->general_actual_start),
        'Real. Fim — data/hora' => $this->PcmTime->format($snapshot->general_actual_end)],
    'DADOS ADICIONAIS DO TOTVS' => [
        'R. In. Man. — data/hora' => $this->PcmTime->format($snapshot->maintenance_actual_start),
        'R. Fim Man. — data/hora' => $this->PcmTime->format($snapshot->maintenance_actual_end)],
]; foreach ($blocks as $title => $items): ?>
<div class="col-lg-6"><section class="pcm-detail-card"><h2><?= h($title) ?></h2><dl>
<?php foreach ($items as $label => $value): ?><div><dt><?= h($label) ?></dt><dd><?= h($value === null || $value === '' ? '—' : $value) ?></dd></div><?php endforeach; ?>
</dl></section></div><?php endforeach; ?></div>
<section class="pcm-dashboard-section"><div class="pcm-section-title"><p>HISTÓRICO DA OS</p><h2>Snapshots existentes</h2></div><div class="pcm-panel p-4"><div class="pcm-timeline"><?php foreach ($history as $item): ?><article><time><?= h($item->report_date->format('d/m/Y')) ?></time><strong><?= h($item->treated_status) ?></strong><span>Situação TOTVS: <?= h($item->source_situation ?: '—') ?> · Início real: <?= h($this->PcmTime->format($item->general_actual_start)) ?> · Término: <?= h($item->finished_raw ?: '—') ?></span><?php if ($changes[(int)$item->id] === []): ?><span><?= count($history) === 1 ? 'Primeiro e único snapshot disponível.' : 'Sem mudança relevante observada.' ?></span><?php else: ?><ul><?php foreach ($changes[(int)$item->id] as $change): ?><li><?= h($change) ?></li><?php endforeach; ?></ul><?php endif; ?></article><?php endforeach; ?></div></div></section>
<details class="pcm-panel pcm-raw-payload mt-4"><summary>Dados técnicos brutos do TOTVS</summary><pre><?= h(json_encode($snapshot->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
