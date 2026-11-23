<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use InvalidArgumentException;

/** Shared listing/export scope, applied before aggregation and pagination. */
final class ChecklistServiceFilter
{
    public static function normalize(array $filters): array
    {
        $type = $filters['service_scope'] ?? '';
        $service = $filters['checklist_service'] ?? '';
        if (!is_string($type) || !in_array($type, ['', 'checklist'], true)
            || !is_string($service) || strlen($service) > 100 || preg_match('/[\x00-\x1F\x7F]/', $service)) {
            throw new InvalidArgumentException('Filtro de checklist inválido.');
        }
        $filters['service_scope'] = $type;
        $filters['checklist_service'] = $type === 'checklist' ? trim($service) : '';

        return $filters;
    }

    private static function clause(): string
    {
        return ' AND (' . RouteChecklist::predicate('j.TJ_CODBEM', 'j.TJ_CODAREA') . ')'
            . " AND (CAST(:checklist_service AS VARCHAR(100)) = '' OR j.TJ_SERVICO = CAST(:checklist_service_code AS VARCHAR(100)))";
    }

    public static function apply(string $sql): string
    {
        $anchor = "j.D_E_L_E_T_ <> '*'";
        $position = strpos($sql, $anchor);

        return $position === false ? $sql : substr_replace($sql, $anchor . self::clause(), $position, strlen($anchor));
    }

    public static function remove(string $sql): string
    {
        return str_replace(self::clause(), '', $sql);
    }

    /** Services actually used by recognized routes; branch masters override shared masters. */
    public static function options(): string
    {
        $route = RouteChecklist::predicate('j.TJ_CODBEM', 'j.TJ_CODAREA');

        return <<<SQL
SELECT DISTINCT RTRIM(j.TJ_SERVICO) AS code,
    RTRIM(CASE WHEN local_service.matches > 0 THEN local_service.name ELSE shared_service.name END) AS name
FROM dbo.STJ010 j
OUTER APPLY (SELECT COUNT(*) matches, MAX(s.T4_NOME) name FROM dbo.ST4010 s
    WHERE s.T4_SERVICO = j.TJ_SERVICO AND s.T4_FILIAL = j.TJ_FILIAL AND s.D_E_L_E_T_ <> '*') local_service
OUTER APPLY (SELECT COUNT(*) matches, MAX(s.T4_NOME) name FROM dbo.ST4010 s
    WHERE s.T4_SERVICO = j.TJ_SERVICO AND s.T4_FILIAL = '' AND s.D_E_L_E_T_ <> '*') shared_service
WHERE j.D_E_L_E_T_ <> '*' AND {$route} AND NULLIF(RTRIM(j.TJ_SERVICO), '') IS NOT NULL
ORDER BY code, name
SQL;
    }
}
