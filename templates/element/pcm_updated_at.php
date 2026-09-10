<?php
/** @var \DateTimeInterface|null $lastUpdatedAt */
?>
<?php if ($lastUpdatedAt === null): ?>
    <span>Dados ainda não atualizados</span>
<?php else: ?>
    <span>Dados atualizados em: <strong><?= h($this->PcmTime->format($lastUpdatedAt, 'd/m/Y \\à\\s H:i')) ?></strong></span>
<?php endif; ?>
