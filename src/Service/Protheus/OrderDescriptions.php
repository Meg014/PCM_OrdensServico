<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Text from confirmed fields only; preserves internal whitespace and line breaks. */
final class OrderDescriptions
{
    public const MISSING = 'Descrição da manutenção não informada.';

    /** Match only the five known questions, wherever placed; other questions stay intact. */
    public static function maintenance(?string $original): ?string
    {
        if ($original === null) return null;
        $questions = [
            'as\s+ferramentas\s+foram\s+limpas\s+antes\s+do\s+servi[cç]o',
            'o\s+equipamento\s+foi\s+limpo\s+ap[oó]s\s+o\s+servi[cç]o',
            'toda\s+a\s+sobra\s+de\s+material\s+foi\s+recolhida',
            'as\s+condi[cç][oõ]es\s+de\s+seguran[cç]a\s*(?:\(\s*prote[cç][aã]o\s*[/,;-]\s*bot[oõ]es\s*[/,;-]\s*sinaliza[cç][aã]o\s*\)\s*)?est[aã]o\s+conforme\s*\(?\s*nr\s*[-.]?\s*12\s*\)?',
            'a\s+conclus[aã]o\s+do\s+servi[cç]o\s*,?\s*e\s+as\s+condi[cç][oõ]es\s+de\s+seguran[cç]a\s+foram\s+mantidas\s+conforme\s*\(?\s*nr\s*[-.]?\s*12\s*\)?',
        ];
        $answer = '(?:\(\s*[x✓✔]?\s*\)|\[\s*[x✓✔]?\s*\]|[☐☑])\s*(?:n[aã]o\s+aplic[aá]vel|sim|n[aã]o)\b';
        $bare = '(?:sim|n[aã]o(?:[\t ]+aplic[aá]vel)?)(?:[\t ]+(?:sim|n[aã]o(?:[\t ]+aplic[aá]vel)?))*(?=[\t ]*(?:\R|$))';
        $pattern = '~(?<![\pL\pN])(?:\d{1,2}[.)-][\t ]*)?(?:' . implode('|', $questions) . ')'
            . '(?=\s*(?:[?!:.;]|\R|$|\(|\[|[☐☑]))[\t ]*[?!:.;]?'
            . '(?:\s*(?:' . $answer . '|' . $bare . '))*~iu';
        $cleaned = preg_replace($pattern, '', $original, -1, $count);
        if ($cleaned === null || $count === 0) return $original;
        // Preserve activity text and its internal line breaks; remove only the resulting blank edges.
        return trim($cleaned);
    }

    public static function display(?string $original): string
    {
        $cleaned = self::maintenance($original);
        return self::text($cleaned) === null ? self::MISSING : $cleaned;
    }

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
            if ($text !== null) return self::display($text);
        }

        return null;
    }
}
