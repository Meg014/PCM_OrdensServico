<?php
$serviceCards = array_diff_key(\App\Service\PcmServiceClassifier::CARD_CLASSES, ['offseason' => true]);
?>
<div class="row g-3 pcm-maintenance-type-cards" aria-label="Classificações de serviço das OS em aberto">
<?php foreach ($serviceCards as $key => $classification): ?>
    <div class="col-12 col-md-6">
        <a class="pcm-maintenance-type-card pcm-service-card text-decoration-none"
           href="<?= h($this->Url->build($cardRoute + ['?' => \App\Service\PcmIndicatorService::drilldownFilters($filters, $key), '#' => 'orders'])) ?>">
            <div><p><?= h(\App\Service\PcmServiceClassifier::LABELS[$classification]) ?></p><small>OS em aberto</small></div>
            <strong><?= number_format((int)($indicators[$key] ?? 0), 0, ',', '.') ?></strong>
        </a>
    </div>
<?php endforeach; ?>
</div>
