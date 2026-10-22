<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Text from confirmed fields only; preserves internal whitespace and line breaks. */
final class OrderDescriptions
{
    public static function text(mixed $value): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return null;
        $value = str_replace("\0", '', $value);
        if (preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) > strlen($value) / 10) return null;
        $value = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = preg_replace('/^[\p{Z}\s\x{FEFF}]+|[\p{Z}\s\x{FEFF}]+$/u', '', $value);

        return preg_match('/[\p{L}\p{N}]/u', $value) === 1 ? $value : null;
    }

    public static function best(mixed $original, array $entries, mixed $service): ?string
    {
        foreach ([$original, ...$entries, $service] as $candidate) {
            $text = self::text($candidate);
            if ($text !== null) return $text;
        }

        return null;
    }
}
