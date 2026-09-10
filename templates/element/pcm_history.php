<?php
/** @var array<string, mixed> $history */
?>
<section class="pcm-dashboard-section pcm-history-section" aria-labelledby="history-title">
    <div class="pcm-section-title"><p>HISTÓRICO</p><h2 id="history-title">Evolução entre relatórios</h2></div>
    <?php if (!$history['available']): ?>
        <div class="pcm-panel pcm-history-empty" role="status">
            <strong>Histórico disponível após a importação de novos relatórios.</strong>
            <span>O snapshot atual continua sendo exibido normalmente; nenhum dado histórico foi estimado.</span>
        </div>
    <?php else: ?>
        <div class="pcm-panel pcm-history-filter">
            <?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-3 align-items-end']) ?>
            <?php foreach ($this->getRequest()->getQueryParams() as $key => $value): ?>
                <?php if (!in_array($key, ['history_period', 'history_from', 'history_to', 'page'], true) && is_scalar($value)): ?>
                    <?= $this->Form->hidden((string)$key, ['value' => (string)$value]) ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="col-md-3"><?= $this->Form->control('history_period', ['label' => 'Período histórico', 'options' => ['7' => 'Últimos 7 dias', '30' => 'Últimos 30 dias', 'custom' => 'Período personalizado'], 'value' => $history['period']['mode']]) ?></div>
            <div class="col-md-3"><?= $this->Form->control('history_from', ['type' => 'date', 'label' => 'De', 'value' => $history['period']['from']]) ?></div>
            <div class="col-md-3"><?= $this->Form->control('history_to', ['type' => 'date', 'label' => 'Até', 'value' => $history['period']['to']]) ?></div>
            <div class="col-md-3"><button class="btn pcm-primary-action w-100">Atualizar histórico</button></div>
            <?= $this->Form->end() ?>
        </div>
        <?php $movement = $history['comparison']['movement']; ?>
        <div class="pcm-section-title pcm-movement-title"><p>MOVIMENTAÇÃO DESDE O ÚLTIMO RELATÓRIO</p><h2><?= h((new \Cake\I18n\Date($history['comparison']['previous']['date']))->format('d/m/Y')) ?> → <?= h((new \Cake\I18n\Date($history['comparison']['current']['date']))->format('d/m/Y')) ?></h2></div>
        <div class="row g-3">
            <?php foreach ([['Novas OS', $movement['new'], '+', 'new'], ['Passaram para fechada', $movement['toCompleted'], '+', 'completed'], ['Passaram para em aberto', $movement['toOpen'], '+', 'open'], ['Não presentes no snapshot atual', $movement['absent'], '−', 'absent'], ['Passaram para cancelada', $movement['toCancelled'], '+', 'cancelled']] as [$label, $value, $symbol, $type]): ?>
                <div class="col-sm-6 col-xl"><article class="pcm-movement-card"><span><?= h($label) ?></span><strong><?= h($symbol) ?> <?= number_format((int)$value, 0, ',', '.') ?></strong><?= $this->Html->link('Ver OS', ['_name' => 'pcm-movement', 'type' => $type, '?' => isset($area) ? ['area' => $area->source_code] : []]) ?></article></div>
            <?php endforeach; ?>
        </div>
        <div class="row g-4 mt-1">
            <div class="col-xl-8"><div class="pcm-chart-card"><h3>Evolução da carteira</h3><p>Cada ponto representa um snapshot diário, sem soma entre datas.</p><div class="pcm-chart-wrap"><canvas data-pcm-history="status"></canvas></div></div></div>
            <div class="col-xl-4"><div class="pcm-chart-card"><h3>Evolução da eficiência</h3><p>Variação percentual conforme a fórmula aprovada.</p><div class="pcm-chart-wrap"><canvas data-pcm-history="efficiency"></canvas></div></div></div>
            <div class="col-12"><div class="pcm-chart-card"><h3>Equipamentos com mais ocorrências no período</h3><p>Quantidade de OS distintas observadas; a mesma OS não é somada a cada snapshot.</p><div class="pcm-chart-wrap"><canvas data-pcm-history="equipment"></canvas></div></div></div>
        </div>
        <details class="pcm-panel pcm-transition-panel mt-4"><summary>Transições de STATUS observadas</summary><div class="table-responsive"><table class="table mb-0"><thead><tr><th>STATUS anterior</th><th>STATUS atual</th><th>OS</th></tr></thead><tbody><?php foreach ($movement['transitions'] as $transition): ?><tr><td><?= h($transition['from']) ?></td><td><?= h($transition['to']) ?></td><td><?= number_format((int)$transition['quantity'], 0, ',', '.') ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <script type="application/json" data-pcm-history-payload><?= json_encode(['series' => $history['series'], 'equipment' => $history['equipmentOccurrences']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
</section>
