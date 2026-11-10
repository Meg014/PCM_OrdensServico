<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;

final class ActionPlanExecution
{
    private static function identity(string $branch, string $number): string
    {
        return json_encode([rtrim($branch), rtrim($number)], JSON_THROW_ON_ERROR);
    }

    /** Keeps distinct stage contexts, even when two employees entered the same text. */
    public static function attach(array $orders, array $stages): array
    {
        $selected = [];
        foreach ($orders as $order) $selected[self::identity($order['branch'], $order['number'])] = true;
        usort($stages, static fn ($a, $b) => self::sortKey($a) <=> self::sortKey($b));
        $grouped = []; $seen = [];
        foreach ($stages as $stage) {
            $key = self::identity((string)$stage['TQ_FILIAL'], (string)$stage['TQ_ORDEM']);
            if (!isset($selected[$key])) throw new DomainException('Observação de etapa fora das O.S. selecionadas.');
            $text = ActionPlanObservation::executionForExcel($stage['executed_observation'] ?? null);
            if ($text === '') continue;
            $recordKey = $key . ':' . $stage['stage_record'];
            $signature = json_encode($stage, JSON_THROW_ON_ERROR);
            if (isset($seen[$recordKey])) {
                if ($seen[$recordKey] !== $signature) throw new DomainException('Registro de etapa ambíguo.');
                continue;
            }
            $seen[$recordKey] = $signature;
            $grouped[$key][] = ['text' => $text, 'stage' => $stage];
        }
        foreach ($orders as &$order) {
            $notes = $grouped[self::identity($order['branch'], $order['number'])] ?? [];
            $blocks = [];
            foreach ($notes as $note) {
                $prefix = '';
                if (count($notes) > 1) {
                    $context = [];
                    foreach (['TQ_PLANO' => 'Plano', 'TQ_TAREFA' => 'Tarefa', 'TQ_SEQTARE' => 'Seq. tarefa',
                        'TQ_ETAPA' => 'Etapa', 'TQ_SEQETA' => 'Seq. etapa'] as $field => $label) {
                        $value = rtrim((string)($note['stage'][$field] ?? ''));
                        if ($value !== '') $context[] = $label . ' ' . $value;
                    }
                    $prefix = '[' . ($context ? implode(' | ', $context) : 'Registro ' . $note['stage']['stage_record']) . "]\n";
                }
                $blocks[] = $prefix . $note['text'];
            }
            $order['executed_service'] = implode("\n\n", $blocks);
        }
        unset($order);
        return $orders;
    }

    private static function sortKey(array $stage): array
    {
        $key = [];
        foreach (['TQ_FILIAL', 'TQ_ORDEM', 'TQ_PLANO', 'TQ_TAREFA', 'TQ_SEQTARE', 'TQ_ETAPA', 'TQ_SEQETA'] as $field) {
            $key[] = rtrim((string)($stage[$field] ?? ''));
        }
        $key[] = (int)$stage['stage_record'];
        return $key;
    }
}
