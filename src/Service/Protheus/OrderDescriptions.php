<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Text from confirmed fields only; preserves internal whitespace and line breaks. */
final class OrderDescriptions
{
    /** Only a complete standardized suffix is hidden; ambiguous text stays intact. */
    public static function maintenance(?string $original): ?string
    {
        if ($original === null) return null;
        $questions = [
            'As ferramentas foram limpas antes do serviço?',
            'O equipamento foi limpo após o serviço?',
            'Toda a sobra de material foi recolhida?',
            'As condições de segurança estão conforme NR12?',
            'A conclusão do serviço e as condições de segurança foram mantidas conforme NR12?',
        ];
        $normalize = static function (string $text): string {
            $text = mb_strtolower(\Cake\Utility\Text::transliterate($text));
            $text = preg_replace('/\(\s*\)\s*(?:nao\s+aplicavel|sim|nao)/u', '', $text);
            $text = str_replace(['(protecao/botoes/sinalizacao)', '(nr12)', ','], ['', 'nr12', ''], $text);
            return trim((string)preg_replace('/\s+/u', ' ', $text));
        };
        if (!preg_match_all('/(?:^|\R)[\t ]*as\s+ferramentas\s+foram/iu', $original, $matches, PREG_OFFSET_CAPTURE)) return $original;
        $expected = $normalize(implode(' ', $questions));
        foreach ($matches[0] as [$match, $offset]) {
            if ($normalize(substr($original, $offset)) === $expected) return rtrim(substr($original, 0, $offset));
        }
        return $original;
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
            if ($text !== null) return $text;
        }

        return null;
    }
}
