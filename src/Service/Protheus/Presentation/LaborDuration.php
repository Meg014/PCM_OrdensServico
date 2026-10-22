<?php
declare(strict_types=1);

namespace App\Service\Protheus\Presentation;

use DateTimeImmutable;
use DateTimeZone;

/** Presentation only: elapsed wall-clock time is not assumed to equal paid/recorded hours. */
final class LaborDuration
{
    public static function present(array $row): array
    {
        $raw = $row['hours'] ?? null;
        if (strtoupper(trim($row['unit'] ?? '')) !== 'H') {
            return ['hours_display' => $raw, 'hours_note' => null];
        }
        $quantity = is_string($raw) && preg_match('/^\d+(?:[.,]\d+)?$/D', $raw)
            ? (float)str_replace(',', '.', $raw) : null;
        $start = self::timestamp($row['date'] ?? null, $row['start_time'] ?? null);
        $end = self::timestamp($row['end_date'] ?? null, $row['end_time'] ?? null);
        $seconds = $start !== null && $end !== null && $end >= $start ? $end - $start : null;
        $recorded = 'Quantidade registrada: ' . ($raw ?? '—') . ' H';
        if ($seconds === null) {
            return ['hours_display' => $quantity === null ? $raw : self::format((int)round($quantity * 3600)),
                'hours_note' => 'Duração entre horários indisponível (datas/horários ausentes ou inválidos).'];
        }
        // TOTVS often stores decimal hours rounded to two places (65 min -> 1.08 H).
        $fraction = explode('.', str_replace(',', '.', $raw ?? ''))[1] ?? '';
        $precision = max(2, min(6, strlen(rtrim($fraction, '0'))));
        $same = $quantity !== null && abs($seconds - $quantity * 3600) <= max(1, 1800 * 10 ** -$precision);

        return ['hours_display' => ($same ? '' : 'Duração entre horários: ') . self::format($seconds),
            'hours_note' => $same ? null : ($quantity === null
                ? 'Quantidade registrada indisponível para comparação.'
                : $recorded . ' · Divergência: a duração não considera possíveis intervalos ou ajustes do apontamento.')];
    }

    private static function timestamp(?string $date, ?string $time): ?int
    {
        if ($date === null || $time === null) return null;
        $format = strlen($time) === 5 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        $input = $date . ' ' . $time;
        $value = DateTimeImmutable::createFromFormat('!' . $format, $input, new DateTimeZone('UTC'));

        return $value !== false && $value->format($format) === $input ? $value->getTimestamp() : null;
    }

    private static function format(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;
        $label = $hours > 0 ? $hours . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '') : $minutes . ' min';
        if ($remaining > 0) $label .= ' ' . $remaining . 's';

        return $label . ' (' . number_format($seconds / 3600, 2, ',', '.') . ' h)';
    }
}
