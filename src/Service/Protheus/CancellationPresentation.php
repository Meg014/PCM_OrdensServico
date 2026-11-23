<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Presentation only: a default message never becomes an original cancellation reason. */
final class CancellationPresentation
{
    public const DEFAULT_MESSAGE = 'O.S ABERTA PARA TESTE';
    public const DEFAULT_SOURCE = 'Motivo padrão — não registrado no TOTVS';

    public static function present(array $record = []): array
    {
        $reason = $record['reason'] ?? null;
        $real = OrderDescriptions::text($reason) !== null;
        return array_replace($record, [
            'reason' => $real ? $reason : null,
            'message' => $real ? $reason : self::DEFAULT_MESSAGE,
            'source' => $real ? ($record['source'] ?? 'totvs_cancellation') : 'presentation_default',
            'source_label' => $real ? 'Motivo original registrado no TOTVS' : self::DEFAULT_SOURCE,
            'derived' => !$real,
            'user' => $record['user'] ?? null,
            'date' => $record['date'] ?? null,
            'time' => $record['time'] ?? null,
        ]);
    }
}
