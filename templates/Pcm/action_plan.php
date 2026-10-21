<?php $this->assign('title', 'Plano de Ação'); ?>
<section class="pcm-dashboard-section">
    <div class="pcm-section-title"><h1>Plano de Ação</h1></div>
    <div class="pcm-panel p-4">
        <p>Selecione o período pela data do primeiro apontamento da O.S. Quando não houver apontamentos, será considerada a data de abertura.</p>
        <?= $this->Form->create(null, ['type' => 'get', 'url' => ['_name' => 'pcm-action-plan-excel']]) ?>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-sm-6 col-lg-4"><?= $this->Form->control('date_start', ['type' => 'date', 'label' => ['text' => 'Data inicial', 'class' => 'form-label d-block'], 'class' => 'form-control form-control-lg w-100', 'required' => true, 'min' => '1900-01-01', 'max' => '9999-12-31']) ?></div>
            <div class="col-12 col-sm-6 col-lg-4"><?= $this->Form->control('date_end', ['type' => 'date', 'label' => ['text' => 'Data final', 'class' => 'form-label d-block'], 'class' => 'form-control form-control-lg w-100', 'required' => true, 'min' => '1900-01-01', 'max' => '9999-12-31']) ?></div>
            <div class="col-12 col-lg-4 d-grid"><?= $this->Form->button('Baixar Plano de Ação', ['class' => 'btn btn-primary btn-lg']) ?></div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</section>
