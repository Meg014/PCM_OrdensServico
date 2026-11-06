<?php
use App\Service\NonconformityService as NC;
$dueLabel = $action->due_on?->format('d/m/Y') ?? 'Sem prazo';
$statusLabel = NC::STATUSES[$action->status];
$noticeText = $event === 'overdue' ? 'O prazo desta atividade venceu em ' . $dueLabel . '. Regularize a pendência no PCM.' : 'A atividade deve ser concluída até ' . $dueLabel . '.';
if ($action->due_on === null) $noticeText = 'Consulte o PCM para acompanhar a definição do prazo desta atividade.';
?>
<?php if ($isTest): ?><p style="margin:0 0 18px;padding:10px 12px;border:1px solid #9bcbd0;background:#eef9fa;font-size:12px;color:#12626c"><strong>TESTE CONTROLADO</strong> — Dados ilustrativos. Esta mensagem não representa uma atribuição real.</p><?php endif; ?>
<p style="font-size:14px;line-height:1.6;margin:0 0 12px">Olá, <?= h($responsible->name) ?>!</p>
<p style="font-size:14px;line-height:1.6;margin:0 0 20px"><?= h($meta['intro']) ?></p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;line-height:1.5">
<tr><td width="50%" valign="top" style="padding:0 12px 14px 0"><span style="font-size:10px;color:#77838b">NÃO CONFORMIDADE</span><br><strong>NC <?= h($nc->number ?? 'Sem número') ?></strong></td><td width="50%" valign="top" style="padding:0 0 14px"><span style="font-size:10px;color:#77838b">ITEM</span><br><strong><?= h($action->item ?? 'Sem número') ?></strong></td></tr>
<tr><td width="50%" valign="top" style="padding:0 12px 14px 0"><span style="font-size:10px;color:#77838b">PRAZO FINAL</span><br><strong><?= h($dueLabel) ?></strong></td><td width="50%" valign="top" style="padding:0 0 14px"><span style="font-size:10px;color:#77838b">SITUAÇÃO</span><br><span style="display:inline-block;padding:3px 9px;border-radius:12px;background:#fff0e5;color:#ad4b13;font-size:11px"><?= h($statusLabel) ?></span></td></tr>
<tr><td colspan="2" style="padding:0 0 18px"><span style="font-size:10px;color:#77838b">RESPONSÁVEL</span><br><?= h($responsible->name) ?></td></tr>
</table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #e6ebee;font-size:14px;line-height:1.7">
<?php foreach (['O QUE? — AÇÃO A EXECUTAR' => $action->description, 'DESCRIÇÃO DA NÃO CONFORMIDADE' => $nc->description, 'MEDIDA' => $action->measure, 'ORIGEM' => $nc->origin] as $label => $content): ?>
<tr><td style="padding:16px 0 0;word-break:break-word;overflow-wrap:anywhere"><p style="margin:0 0 5px;color:#77838b;font-size:10px;font-weight:bold"><?= h($label) ?></p><div style="white-space:pre-wrap"><?= h($content ?: 'Não informado') ?></div></td></tr>
<?php endforeach; ?></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:20px"><tr><td style="padding:14px 16px;background:<?= h($meta['background']) ?>;color:<?= h($meta['color']) ?>;border-radius:6px;font-size:12px;line-height:1.7"><strong><?= h($meta['notice']) ?></strong><br><?= h($noticeText) ?></td></tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:14px"><tr><td align="center" bgcolor="#07808b" style="border-radius:6px"><a href="<?= h($link) ?>" style="display:block;padding:14px 18px;color:#fff;font-size:14px;font-weight:bold;line-height:1.5;text-decoration:none">Consultar ação no PCM</a></td></tr></table>