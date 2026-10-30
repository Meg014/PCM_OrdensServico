<?php
if (!isset($notifications)) {
    try {
        $notifications = (new \App\Service\NonconformityService())->notifications((int)$currentUser->id);
    } catch (\Throwable) {
        // An optional alert component must not take existing OS pages offline.
        $notifications = ['count' => 0, 'items' => [], 'unavailable' => true];
    }
}
?>
<details class="pcm-nc-bell">
<summary aria-label="Notificações de Não Conformidades" title="Notificações de Não Conformidades">
<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
<span class="badge text-bg-danger" aria-live="polite"><?= !empty($notifications['unavailable']) ? '!' : (int)$notifications['count'] ?></span>
</summary>
<div class="pcm-nc-bell-list"><strong>Não Conformidades</strong>
<?php if (!empty($notifications['unavailable'])): ?><p class="small mt-2 mb-0">Notificações temporariamente indisponíveis.</p>
<?php elseif (!$notifications['items']): ?><p class="small mt-2 mb-0">Nenhuma notificação.</p><?php endif; ?>
<?php foreach ($notifications['items'] as $notification): ?>
<?= $this->Form->create(null, ['url' => '/pcm/nao-conformidades/notificacoes/' . $notification->id . '/abrir']) ?>
<button class="btn btn-sm text-start w-100 <?= $notification->read_at === null ? 'fw-bold' : '' ?>" type="submit">
<?= h(\App\Service\NonconformityService::EVENTS[$notification->event]) ?> · Ação #<?= (int)$notification->action_id ?><br>
<small>Prazo: <?= h($notification->due_on->format('d/m/Y')) ?><?= $notification->read_at === null ? ' · Não lida' : ' · Lida' ?></small></button>
<?= $this->Form->end() ?>
<?php endforeach; ?></div></details>
