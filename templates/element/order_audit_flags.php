<?php
$auditFlags = \App\Service\OrderAuditFlags::forOrder(is_array($order) ? $order : $order->toArray());
?>
<?php foreach ($auditFlags as $key => $message): ?>
<?php if (in_array($key, $excludeFlags ?? [], true)) continue; ?>
<small class="d-block text-warning-emphasis"><?= h($message) ?></small>
<?php endforeach; ?>
