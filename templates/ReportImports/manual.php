<?php $this->assign('title', 'Importar relatório TOTVS'); ?>
<header class="pcm-page-header pcm-import-header">
    <div>
        <p class="pcm-eyebrow">PCM | IMPORTAÇÃO DE RELATÓRIOS</p>
        <h1>Importar relatório TOTVS</h1>
        <p class="pcm-updated">Selecione o relatório CSV ou XLSX de Ordens de Serviço exportado do TOTVS.</p>
    </div>
</header>

<section class="pcm-panel pcm-upload-panel">
    <?= $this->Form->create(null, ['type' => 'file', 'class' => 'pcm-upload-form']) ?>
    <label class="pcm-upload-area" for="report-file">
        <span class="pcm-upload-icon" aria-hidden="true">⇧</span>
        <strong>Selecione um arquivo CSV ou XLSX</strong>
        <span>Clique nesta área para procurar o relatório.</span>
        <span class="pcm-selected-file" id="selected-file">Nenhum arquivo selecionado</span>
    </label>
    <?= $this->Form->file('report_file', [
        'id' => 'report-file',
        'accept' => '.csv,text/csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'required' => true,
        'class' => 'visually-hidden',
    ]) ?>
    <p class="pcm-upload-note">
        O sistema identifica arquivos duplicados automaticamente e mantém o histórico diário das Ordens de Serviço.
    </p>
    <div class="d-flex flex-column flex-sm-row gap-3 justify-content-end">
        <?= $this->Html->link('Cancelar e voltar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        <?= $this->Form->button('Importar relatório', ['class' => 'btn btn-primary pcm-primary-action']) ?>
    </div>
    <?= $this->Form->end() ?>
</section>

<?php $this->start('script'); ?>
<script>
document.getElementById('report-file').addEventListener('change', function () {
    document.getElementById('selected-file').textContent =
        this.files.length ? this.files[0].name : 'Nenhum arquivo selecionado';
});
</script>
<?php $this->end(); ?>
