<?php
declare(strict_types=1);

namespace App\Service;

/** Excel-only presentation. Never infers execution from an ambiguous order observation. */
final class ActionPlanObservation
{
    /** Exact known questions, including the short form verified in O.S. 007334/01. */
    private const QUESTIONS = [
        '(?:as\s+ferramentas\s+foram\s+limpas|ferramentas\s+limpas)\s+antes\s+do\s+servi[cç]o',
        '(?:o\s+equipamento\s+foi\s+limpo|equipamento\s+limpo)\s+ap[oó]s\s+o\s+servi[cç]o',
        '(?:toda\s+a\s+sobra\s+de\s+material\s+foi\s+recolhida|sobras\s+de\s+materiais\s+recolhidas)',
        '(?:prote[cç][oõ]es\s*[,/]\s*bot[oõ]es\s+e\s+sinaliza[cç][aã]o\s+conforme|as\s+condi[cç][oõ]es\s+de\s+seguran[cç]a\s*(?:\(\s*prote[cç][aã]o\s*[/,;-]\s*bot[oõ]es\s*[/,;-]\s*sinaliza[cç][aã]o\s*\)\s*)?est[aã]o\s+conforme)\s*\(?\s*nr\s*[-.]?\s*12\s*\)?',
        '(?:condi[cç][oõ]es\s+de\s+seguran[cç]a\s+mantidas\s+ap[oó]s\s+o\s+servi[cç]o|a\s+conclus[aã]o\s+do\s+servi[cç]o\s*,?\s*e\s+as\s+condi[cç][oõ]es\s+de\s+seguran[cç]a\s+foram\s+mantidas\s+conforme\s*\(?\s*nr\s*[-.]?\s*12\s*\)?)',
    ];

    /**
     * Only recognized question/answer spans are removed. Other wording/whitespace stays intact.
     * complete_checklist describes the matched questions only; it is never proof of execution.
     */
    public static function analyze(?string $original): array
    {
        if ($original === null || $original === '') return ['text' => '', 'questions' => 0, 'complete_checklist' => false];
        if (!mb_check_encoding($original, 'UTF-8')) throw new \DomainException('A observação da O.S. não está em UTF-8 válido. Nenhum texto foi substituído.');
        $text = $original;
        $matched = 0;
        $answer = '(?:sim|n[aã]o[\t ]+aplic[aá]vel|n[aã]o|n[\t ]*/[\t ]*a)(?![\pL\pN])';
        $box = '(?:\([\t ]*[x✓✔]?[\t ]*\)|\[[\t ]*[x✓✔]?[\t ]*\]|[☐☑])';
        $boxedAnswers = '(?:[\t ]*' . $box . '[\t ]*' . $answer . ')*';
        $bareAnswers = '(?:[\t ]*' . $answer . ')+(?=[\t ]*(?:\r\n|\r|\n|$))';
        foreach (self::QUESTIONS as $question) {
            // Anchored to a question line: quoted prose and unrelated safety notes are not erased.
            $pattern = '~(?:^|(?<=\n)|(?<=\r))[\t ]*(?:[1-5][.)-][\t ]*)?' . $question
                . '[\t ]*[?:][\t ]*(?:' . $bareAnswers . '|' . $boxedAnswers . ')'
                . '(?:[\t ]*(?:\r\n|\r|\n|$))?~iu';
            $text = preg_replace($pattern, '', $text, -1, $count);
            if ($text === null) throw new \DomainException('Não foi possível apresentar a observação da O.S.');
            if ($count > 0) $matched++;
        }
        return ['text' => $text, 'questions' => $matched, 'complete_checklist' => $matched === 5];
    }

    public static function withoutChecklist(?string $original): string
    {
        return self::analyze($original)['text'];
    }

    /** Receives the SQL-converted text, not guessed/hex-decoded binary. */
    public static function forExcel(?string $original): string
    {
        // NUL/padding are storage artifacts, not employee wording. Keep leading/internal spaces.
        return rtrim(self::withoutChecklist($original === null ? null : str_replace("\0", '', $original)), " \t\r\n");
    }
}
