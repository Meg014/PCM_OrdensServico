<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Confirmed asset-code prefixes, never equipment descriptions or maintenance types. */
final class RouteChecklist
{
    public const AGES = ['route_on_time' => 'EM DIA', 'route_attention' => 'ATENÇÃO',
        'route_critical' => 'CRÍTICO', 'route_unknown' => 'Sem data válida',
        'route_future' => 'Data futura'];

    public static function predicate(string $code = 'TJ_CODBEM', string $area = 'TJ_CODAREA'): string
    {
        $code = "UPPER(LTRIM(RTRIM({$code})))";
        return "(({$area} IN ('ELETRI', 'INSTRU') AND ({$code} = 'PREVELE FAB' OR {$code} LIKE 'PREVELE FAB %'))"
            . " OR ({$area} = 'MECANI' AND ({$code} = 'PREVLUB FAB' OR {$code} LIKE 'PREVLUB FAB %'"
            . " OR {$code} = 'PREVMEC FAB' OR {$code} LIKE 'PREVMEC FAB %')))";
    }

    public static function ageCase(): string
    {
        return "CASE WHEN age_days IS NULL THEN 'route_unknown' WHEN age_days < 0 THEN 'route_future'"
            . " WHEN UPPER(LTRIM(RTRIM(TJ_CODBEM))) = 'PREVELE FAB' OR UPPER(LTRIM(RTRIM(TJ_CODBEM))) LIKE 'PREVELE FAB %'"
            . " THEN CASE WHEN age_days <= 17 THEN 'route_on_time' WHEN age_days <= 20 THEN 'route_attention' ELSE 'route_critical' END"
            . " ELSE CASE WHEN age_days <= 7 THEN 'route_on_time' WHEN age_days <= 14 THEN 'route_attention' ELSE 'route_critical' END END";
    }

    /** Presentation follows the selected sector; SQL classification always follows the asset family. */
    public static function ranges(string $area): array
    {
        $mechanical = ['route_on_time' => '0 a 7 dias', 'route_attention' => '8 a 14 dias', 'route_critical' => 'Mais de 14 dias'];
        $electrical = ['route_on_time' => '0 a 17 dias', 'route_attention' => '18 a 20 dias', 'route_critical' => 'Mais de 20 dias'];
        if (in_array($area, ['ELETRI', 'INSTRU'], true)) return $electrical;
        if ($area === 'MECANI') return $mechanical;
        $result = [];
        foreach ($mechanical as $key => $range) $result[$key] = 'Mecânica: ' . $range . ' · Elétrica: ' . $electrical[$key];
        return $result;
    }

    public static function unitPredicate(): string
    {
        $route = self::predicate('n.TJ_CODBEM', 'n.TJ_CODAREA');
        return "(({$route} AND f.unit IN ('', 'factory')) OR (NOT {$route} AND "
            . ProtheusUnit::predicate('n.TJ_CCUSTO', 'f.unit') . '))';
    }

    public static function selection(string $age, array $backlog, array $checklists): array
    {
        $route = str_starts_with($age, 'route_');
        $data = $route ? $checklists : $backlog;
        $all = in_array($age, ['all', 'route_all'], true);
        $label = $all ? 'Total em aberto' : (self::AGES + ProtheusSectorService::BACKLOG_AGES)[$age];
        if ($route && isset(self::ranges($checklists['area'] ?? '')[$age])) {
            $label .= ' · ' . self::ranges($checklists['area'] ?? '')[$age];
        }
        return ['label' => ($route ? 'Checklists das rotas · ' : 'Backlog · ') . $label,
            'total' => $all ? $data['total'] : $data['ages'][$age]];
    }
}
