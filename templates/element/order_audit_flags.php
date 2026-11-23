<?php
$auditFlags = \App\Service\OrderAuditFlags::forOrder(is_array($order) ? $order : $order->toArray());
?>
<?php foreach ($auditFlags as $message): ?>
<small class="d-block text-warning-emphasis"><?= h($message) ?></small>
<?php endforeach; ?>
