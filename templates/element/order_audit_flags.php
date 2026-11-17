<?php
$auditFlags = \App\Service\OrderAuditFlags::forOrder(is_array($order) ? $order : $order->toArray());
if (!empty($hideFutureDate)) {
    unset($auditFlags['future_date']);
}
?>
<?php foreach ($auditFlags as $message): ?>
<small class="d-block text-warning-emphasis"><?= h($message) ?></small>
<?php endforeach; ?>
