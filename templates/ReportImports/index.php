<?php
/**
 * @var \Cake\Datasource\ResultSetInterface<\App\Model\Entity\ReportImport> $imports
 * @var \App\Model\Entity\ReportImport|null $currentImport
 */
$this->assign('title', 'Importações');
$statusPresentation = [
    'success' => ['label' => 'Sucesso', 'class' => 'text-bg-success'],
    'failed' => ['label' => 'Erro', 'class' => 'text-bg-danger'],
    'rejected' => ['label' => 'Rejeitado', 'class' => 'text-bg-danger'],
    'processing' => ['label' => 'Processando', 'class' => 'text-bg-primary'],
];
?>
<header class="pcm-page-header pcm-import-header">
    <div>
        <p class="pcm-eyebrow">PCM | IMPORTAÇÃO DE RELATÓRIOS</p>
        <h1>Importações</h1>
        <p class="pcm-updated">Gerencie os relatórios de Ordens de Serviço exportados do TOTVS.</p>
    </div>
</header>

<section class="pcm-import-summary mb-5" aria-label="Resumo da última importação">
    <div class="row g-3 align-items-stretch">
        <div class="col-12 col-md-4">
            <div class="pcm-summary-item h-100">
                <span>Relatório mais recente</span>
                <strong><?= $currentImport ? h($currentImport->report_date->format('d/m/Y')) : '—' ?></strong>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="pcm-summary-item h-100">
                <span>Registros do último relatório</span>
                <strong><?= $currentImport ? number_format((int)$currentImport->rows_imported, 0, ',', '.') : '0' ?></strong>
            </div>
        </div>
        <div class="col-12 col-md-4 d-grid">
            <?= $this->Html->link(
                'Importar novo relatório',
                ['action' => 'manual'],
                ['class' => 'btn btn-primary pcm-primary-action d-flex align-items-center justify-content-center'],
            ) ?>
        </div>
    </div>
</section>

<section class="pcm-panel">
    <div class="pcm-panel-heading">
        <p class="pcm-eyebrow mb-1">HISTÓRICO DE IMPORTAÇÕES</p>
        <h2>Relatórios processados</h2>
    </div>
    <div class="table-responsive">
        <table class="table pcm-import-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Arquivo</th><th>Data do relatório</th><th>Importado em</th><th>Registros</th>
                    <th>Importados</th><th>Rejeitados</th><th>Status</th><th>Erro</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($imports->items()->isEmpty()): ?>
                <tr><td colspan="8" class="pcm-empty-table">Nenhuma importação registrada.</td></tr>
            <?php else: ?>
                <?php foreach ($imports as $import): ?>
                    <?php $status = $statusPresentation[$import->status]
                        ?? ['label' => ucfirst($import->status), 'class' => 'text-bg-secondary']; ?>
                    <tr>
                        <td class="pcm-file-name"><?= h($import->file_name) ?></td>
                        <td><?= h($import->report_date?->format('d/m/Y')) ?></td>
                        <td><?= h($this->PcmTime->format($import->started_at)) ?></td>
                        <td><?= number_format((int)$import->rows_read, 0, ',', '.') ?></td>
                        <td><?= number_format((int)$import->rows_imported, 0, ',', '.') ?></td>
                        <td><?= number_format((int)$import->rows_rejected, 0, ',', '.') ?></td>
                        <td><span class="badge rounded-pill <?= h($status['class']) ?>"><?= h($status['label']) ?></span></td>
                        <td class="pcm-error-cell"><?= $import->error_message ? 'Falha no processamento' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="pcm-pagination"><?= $this->Paginator->numbers() ?></div>
</section>
