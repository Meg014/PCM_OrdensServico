<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** The overlay changes the STJ area before filters, grouping, pagination and resource joins. */
final class ReclassificationQueries
{
    public const PREVIEW = <<<'SQL'
SELECT j.TJ_FILIAL, j.TJ_ORDEM, j.TJ_CODBEM, j.TJ_SERVICO, j.TJ_CODAREA,
    j.TJ_SITUACA, j.TJ_TERMINO, CONVERT(varchar(max),j.TJ_OBSERVA) AS descricao,
    b.T9_NOME AS equipment_name, b.T9_CODFAMI AS equipment_family, s.T4_NOME AS service_name
FROM OPENJSON(CAST(:numbers AS nvarchar(max))) WITH (number varchar(50) '$') n
JOIN dbo.STJ010 j ON j.TJ_ORDEM=n.number AND j.TJ_FILIAL=CAST(:branch AS varchar(20)) AND j.D_E_L_E_T_<>'*'
OUTER APPLY (SELECT TOP (1) b.T9_NOME, b.T9_CODFAMI FROM dbo.ST9010 b
    WHERE b.T9_CODBEM=j.TJ_CODBEM AND b.T9_FILIAL IN (j.TJ_FILIAL,'') AND b.D_E_L_E_T_<>'*'
    ORDER BY b.T9_FILIAL DESC,b.R_E_C_N_O_) b
OUTER APPLY (SELECT TOP (1) s.T4_NOME FROM dbo.ST4010 s
    WHERE s.T4_SERVICO=j.TJ_SERVICO AND s.T4_FILIAL IN (j.TJ_FILIAL,'') AND s.D_E_L_E_T_<>'*'
    ORDER BY s.T4_FILIAL DESC,s.R_E_C_N_O_) s
SQL;

    private const SOURCE = 'dbo.STJ010 j';
    private const VIEW = 'pcm_effective_orders j';
    private const START = 'WITH pcm_effective_orders AS (';
    private const END = "\n) /* pcm effective area */\n";

    public static function applicable(string $sql): bool
    {
        return $sql !== self::PREVIEW && str_contains($sql, self::SOURCE) && str_contains($sql, 'TJ_CODAREA');
    }

    public static function apply(string $sql): string
    {
        // Project the STJ columns used by this fixed query. Optional history columns
        // can also appear as NULL aliases on installations where the columns are absent.
        preg_match_all('/\bTJ_[A-Z0-9]+\b/', $sql, $matches);
        $columns = ['o.D_E_L_E_T_', 'o.R_E_C_N_O_'];
        foreach (array_unique($matches[0]) as $column) {
            if ($column === 'TJ_CODAREA') continue;
            if (in_array($column, ['TJ_USUAINI', 'TJ_USUAFIM'], true) && !str_contains($sql, 'j.' . $column)) continue;
            $columns[] = 'o.' . $column;
        }
        // The historical dashboard reuses the complete source in several aggregates.
        // A hash join avoids repeated JSON lookups there. Identity lookups need their indexed plans.
        $join = str_contains($sql, '/* PCM historical dashboard */') ? 'LEFT HASH JOIN' : 'LEFT JOIN';
        $cte = self::START . "\nSELECT " . implode(', ', $columns) . ',
    o.TJ_CODAREA AS pcm_original_area, m.destination AS pcm_destination,
    COALESCE(m.destination, o.TJ_CODAREA) AS TJ_CODAREA
FROM dbo.STJ010 o
' . $join . ' OPENJSON(CAST(:pcm_areas AS nvarchar(max)))
    WITH (branch varchar(20), number varchar(50), destination varchar(20)) m
    ON m.branch COLLATE DATABASE_DEFAULT=o.TJ_FILIAL AND m.number COLLATE DATABASE_DEFAULT=o.TJ_ORDEM' . self::END;
        $body = str_replace([self::SOURCE, 'j.TJ_CODAREA AS original_area', 'CAST(NULL AS varchar(20)) AS reclassified_area'],
            [self::VIEW, 'j.pcm_original_area AS original_area', 'j.pcm_destination AS reclassified_area'], $sql);

        return $cte . (str_starts_with($body, 'WITH ') ? ', ' . substr($body, 5) : $body);
    }

    /** The driver accepts only an exact transformation of an already permitted SELECT. */
    public static function original(string $sql): ?string
    {
        if (!str_starts_with($sql, self::START)) return null;
        $pos = strpos($sql, self::END);
        if ($pos === false) return null;
        $body = substr($sql, $pos + strlen(self::END));
        if (str_starts_with($body, ', ')) $body = 'WITH ' . substr($body, 2);
        return str_replace([self::VIEW, 'j.pcm_original_area AS original_area', 'j.pcm_destination AS reclassified_area'],
            [self::SOURCE, 'j.TJ_CODAREA AS original_area', 'CAST(NULL AS varchar(20)) AS reclassified_area'], $body);
    }
}
