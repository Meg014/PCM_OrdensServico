<?php
/** @var \App\View\AppView $this */
/** @var int $snapshotId */
echo $this->Html->script('pcm-protheus-order', ['block' => true, 'defer' => true]);
?>
<section class="pcm-dashboard-section" data-protheus-order
    data-url="<?= h($protheusUrl ?? $this->Url->build(['_name' => 'pcm-order-protheus', 'id' => $snapshotId])) ?>">
    <div data-protheus-detail aria-live="polite" aria-busy="true">
        <div class="pcm-section-title"><p>PROTHEUS</p><h2>Detalhes da manutenção</h2></div>
        <p class="text-body-secondary" role="status">Carregando dados complementares…</p>
    </div>
    <div data-protheus-history aria-live="polite"></div>
    <noscript><p class="text-body-secondary">Ative o JavaScript para consultar os detalhes complementares do Protheus.</p></noscript>
    <dialog class="pcm-panel p-4" data-protheus-dialog aria-labelledby="protheus-dialog-title"
        style="width: min(1100px, 95vw); max-height: 90vh; overflow: auto; color: inherit; background: var(--bs-body-bg);">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 id="protheus-dialog-title">Detalhe da OS no Protheus</h2>
            <button type="button" class="btn btn-outline-secondary" data-protheus-close>Fechar</button>
        </div>
        <div data-protheus-selected aria-live="polite"></div>
    </dialog>
</section>
