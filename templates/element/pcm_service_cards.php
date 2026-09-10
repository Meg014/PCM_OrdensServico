<?php
$serviceCards = \App\Service\PcmServiceClassifier::CARD_CLASSES;
?>
<div class="row g-3 pcm-maintenance-type-cards" aria-label="Classificações de serviço das OS em aberto">
<?php foreach ($serviceCards as $key => $classification): ?>
    <div class="col-12 col-md-4">
        <a class="pcm-maintenance-type-card pcm-service-card text-decoration-none"
           href="<?= h($this->Url->build($cardRoute + ['?' => array_merge($filters, [
               'status' => \App\Service\WorkOrderStatusResolver::OPEN, 'classification' => $classification,
           ]), '#' => 'orders'])) ?>">
            <div><p><?= h(\App\Service\PcmServiceClassifier::LABELS[$classification]) ?></p><small>OS em aberto</small></div>
            <strong><?= number_format((int)($indicators[$key] ?? 0), 0, ',', '.') ?></strong>
        </a>
    </div>
<?php endforeach; ?>
</div>
