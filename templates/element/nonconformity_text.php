<?php $text = is_scalar($text ?? null) ? (string)$text : ''; ?>
<?php if (mb_strlen($text) > 240): ?><details class="pcm-nc-cell-text"><summary><?= h(mb_substr($text, 0, 100)) ?>… <span class="text-body-secondary">Expandir</span></summary><div class="pcm-nc-text mt-2"><?= h($text) ?></div></details>
<?php else: ?><div class="pcm-nc-text"><?= h($text === '' ? '—' : $text) ?></div><?php endif; ?>
