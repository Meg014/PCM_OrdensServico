<?php $unitMetadata = \App\Service\Protheus\ProtheusUnit::metadata($costCenter ?? null); ?>
<small class="d-block text-body-secondary"><?= h(\App\Service\Protheus\ProtheusUnit::label($costCenter ?? null)) ?><?php if ($unitMetadata['offseason'] ?? false): ?> · Centro de custo de entressafra<?php endif; ?></small>
