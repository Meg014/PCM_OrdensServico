<?php $this->assign('title', 'Plano de Ação'); ?>
<?php $this->Html->script('pcm-action-plan', ['block' => true, 'defer' => true, 'timestamp' => 'force']); ?>
<section class="pcm-dashboard-section">
    <div class="pcm-section-title"><h1>Plano de Ação</h1></div>
    <div class="pcm-panel p-4">
        <p>Selecione o período pela data do primeiro apontamento da O.S. Quando não houver apontamentos, será considerada a data de abertura.</p>
        <?= $this->Form->create(null, ['type' => 'get', 'url' => ['_name' => 'pcm-action-plan-excel'], 'data-action-plan-form' => true]) ?>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-sm-6 col-lg-3"><?= $this->Form->control('date_start', ['type' => 'date', 'label' => ['text' => 'Data inicial', 'class' => 'form-label d-block'], 'class' => 'form-control form-control-lg w-100', 'required' => true, 'min' => '1900-01-01', 'max' => '9999-12-31']) ?></div>
            <div class="col-12 col-sm-6 col-lg-3"><?= $this->Form->control('date_end', ['type' => 'date', 'label' => ['text' => 'Data final', 'class' => 'form-label d-block'], 'class' => 'form-control form-control-lg w-100', 'required' => true, 'min' => '1900-01-01', 'max' => '9999-12-31']) ?></div>
            <div class="col-12 col-sm-6 col-lg-3"><?= $this->Form->control('unidade', [
                'type' => 'select', 'label' => ['text' => 'Unidade', 'class' => 'form-label d-block'],
                'empty' => 'Todas', 'options' => \App\Service\Protheus\ProtheusUnit::LABELS,
                'default' => \App\Service\Protheus\ProtheusUnit::ALL,
                'class' => 'form-select form-select-lg w-100',
            ]) ?></div>
            <div class="col-12 col-sm-6 col-lg-3 d-grid"><button class="btn btn-primary btn-lg" data-action-plan-download><span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true" data-action-plan-spinner></span><span data-action-plan-label>Baixar Plano de Ação</span></button></div>
        </div>
        <?= $this->Form->end() ?>
        <p class="small mt-3 mb-0" role="status" aria-live="polite" data-action-plan-status></p>
    </div>
</section>
