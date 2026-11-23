<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Summary only: never rewrites STQ execution notes or historical entry descriptions. */
final class ExecutedServicePresentation
{
    public const CHECKLIST = 'CHECKLIST EXECUTADO';

    public static function display(?string $original, array $order): string
    {
        return self::resolve($original, $order)['text'];
    }

    public static function resolve(?string $original, array $order): array
    {
        $known = array_key_exists('TJ_TERMINO', $order) || array_key_exists('ending', $order);
        $situation = trim((string)($order['TJ_SITUACA'] ?? $order['situation'] ?? ''));
        $ending = trim((string)($order['TJ_TERMINO'] ?? $order['ending'] ?? ''));
        $status = $known || $situation !== '' ? (new \App\Service\WorkOrderStatusResolver())->resolve($situation, $ending) : 'DESCONHECIDA';
        $real = OrderDescriptions::text($original) !== null;
        $checklist = RouteChecklist::isOrder($order);
        $description = array_key_exists('original_description', $order) ? $order['original_description'] : ($order['description'] ?? null);
        if ($status === 'EM ABERTO') {
            [$text, $source] = [$checklist ? 'CHECKLIST EM ANDAMENTO' : 'SERVIÇO EM ANDAMENTO', 'in_progress'];
        } elseif ($real) {
            [$text, $source] = [str_replace("\0", '', $original), 'totvs_execution'];
        } elseif ($status === 'CANCELADA') {
            [$text, $source] = ['Sem serviço executado registrado', 'missing'];
        } elseif ($checklist && $status === 'FECHADA') {
            [$text, $source] = [self::CHECKLIST, 'checklist_status'];
        } elseif ($status === 'FECHADA' && OrderDescriptions::text($description) !== null) {
            [$text, $source] = [$description, 'order_description'];
        } else {
            [$text, $source] = [OrderDescriptions::MISSING, 'missing'];
        }
        $label = match ($source) {
            'totvs_execution' => 'Apontamento de execução no TOTVS (STQ010.TQ_OBSERVA)',
            'order_description' => 'Descrição original da O.S. (sem apontamento confirmado)',
            'checklist_status' => 'Texto derivado da situação e do tipo; não comprova execução',
            'in_progress' => 'Texto derivado da situação da O.S.',
            default => 'Sem serviço executado registrado',
        };
        return ['text' => $text, 'source' => $source, 'source_label' => $label,
            'derived' => in_array($source, ['in_progress', 'checklist_status'], true), 'status' => $status];
    }
}
