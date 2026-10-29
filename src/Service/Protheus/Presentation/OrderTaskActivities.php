<?php
declare(strict_types=1);

namespace App\Service\Protheus\Presentation;

use App\Service\Protheus\OrderDescriptions;

/** Only explicit STL task/stage pairs. Does not infer missing planning relationships. */
final class OrderTaskActivities
{
    public static function present(array $rows, string $branch, string $number, bool $available): array
    {
        $result = ['planning_state' => 'unavailable', 'recorded_state' => $available ? 'available' : 'unavailable', 'tasks' => []];
        if (!$available) return $result;
        $tasks = [];
        foreach ($rows as $row) {
            if (rtrim((string)($row['TL_FILIAL'] ?? '')) !== $branch || rtrim((string)($row['TL_ORDEM'] ?? '')) !== $number) {
                // Reject the whole optional population on an identity mismatch.
                return self::present([], $branch, $number, false);
            }
            $task = trim((string)($row['TL_TAREFA'] ?? ''));
            $stage = trim((string)($row['TL_ETAPA'] ?? ''));
            if ($task === '' && $stage === '') continue;
            $plan = trim((string)($row['TL_PLANO'] ?? ''));
            $related = trim((string)($row['TL_SEQRELA'] ?? ''));
            $maintenance = trim((string)($row['TL_SEQMAN'] ?? ''));
            $sequence = trim((string)($row['TL_SEQTARE'] ?? ''));
            // Show each task once per plan; retain all record sequences as provenance.
            $key = json_encode([$plan, $task], JSON_THROW_ON_ERROR);
            $context = ['related_sequence' => $related, 'maintenance_sequence' => $maintenance, 'task_sequence' => $sequence];
            if (!isset($tasks[$key])) {
                $tasks[$key] = ['code' => $task === '' ? null : $task, 'description' => $task === '' ? null : OrderDescriptions::text($row['task_description'] ?? null),
                    'plan' => $plan, 'contexts' => [], 'stages' => []];
            }
            if (!in_array($context, $tasks[$key]['contexts'], true)) $tasks[$key]['contexts'][] = $context;
            if ($stage !== '' && !isset($tasks[$key]['stages'][$stage])) {
                $tasks[$key]['stages'][$stage] = ['code' => $stage,
                    'description' => OrderDescriptions::text($row['stage_description'] ?? null), 'contexts' => []];
            }
            if ($stage !== '' && !in_array($context, $tasks[$key]['stages'][$stage]['contexts'], true)) $tasks[$key]['stages'][$stage]['contexts'][] = $context;
        }
        foreach ($tasks as $task) {
            $task['stages'] = array_values($task['stages']);
            $result['tasks'][] = $task;
        }
        return $result;
    }
}
