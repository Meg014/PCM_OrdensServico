<?php
/** Shared OS description presentation; never used for task/entry/NC observations. */
$text = \App\Service\Protheus\OrderDescriptions::display($description ?? null);
?>
<?php if (mb_strlen($text) > 600): ?>
<div class="pcm-maintenance-description pcm-maintenance-description-preview"><?= h(mb_substr($text, 0, 600)) ?>…</div>
<details class="mt-2"><summary>Expandir descrição</summary><div class="pcm-maintenance-description mt-2"><?= h($text) ?></div></details>
<?php else: ?><div class="pcm-maintenance-description"><?= h($text) ?></div><?php endif; ?>
