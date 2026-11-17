<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Fixed presentation scopes, reversible for the read-only SQL allowlist. */
final class SectorPresentationScope
{
    private const PERIOD_ANCHOR = "AND (f.equipment = '' OR n.TJ_CODBEM = f.equipment)";
    private const PERIOD = " AND (:sector_start = '' OR n.indicator_date >= TRY_CONVERT(date, NULLIF(:sector_start_value, ''), 23))"
        . " AND (:sector_end = '' OR n.indicator_date <= TRY_CONVERT(date, NULLIF(:sector_end_value, ''), 23))";

    public static function period(string $sql): string
    {
        return str_replace(self::PERIOD_ANCHOR, self::PERIOD_ANCHOR . self::PERIOD, $sql);
    }

    public static function withoutPeriod(string $sql): string
    {
        return str_replace(self::PERIOD, '', $sql);
    }

    private static function dashboardClause(bool $completedOnly = false): string
    {
        if ($completedOnly) {
            return ' AND (CASE WHEN ' . ProtheusOperationalEligibility::closed('j') . ' THEN CASE WHEN '
                . "TRY_CONVERT(date, NULLIF(j.TJ_DTPRFIM, ''), 112) >= CONVERT(date, :dashboard_start, 23) "
                . "AND TRY_CONVERT(date, NULLIF(j.TJ_DTPRFIM, ''), 112) <= CONVERT(date, :dashboard_end, 23) "
                . 'THEN 1 ELSE 0 END ELSE 1 END = 1)';
        }
        $date = \App\Service\PcmPeriod::sqlDate();

        return " AND ({$date}) >= CONVERT(date, :dashboard_start, 23)"
            . " AND ({$date}) <= CONVERT(date, :dashboard_end, 23)";
    }

    public static function dashboardPeriod(string $sql, bool $completedOnly = false): string
    {
        $anchor = "j.D_E_L_E_T_ <> '*'";
        $position = strpos($sql, $anchor);

        return $position === false ? $sql : substr_replace($sql, $anchor . self::dashboardClause($completedOnly), $position, strlen($anchor));
    }

    public static function withoutDashboardPeriod(string $sql): string
    {
        return str_replace([self::dashboardClause(), self::dashboardClause(true)], '', $sql);
    }

    public static function areas(string $sql): string
    {
        foreach (['CAST(:area AS VARCHAR(100))', 'CAST(:scope_area AS VARCHAR(100))', 'f.area'] as $expression) {
            $original = 'j.TJ_CODAREA = ' . $expression;
            $sql = str_replace($original, self::areaPredicate($expression), $sql);
        }
        return $sql;
    }

    public static function withoutAreas(string $sql): string
    {
        foreach (['CAST(:area AS VARCHAR(100))', 'CAST(:scope_area AS VARCHAR(100))', 'f.area'] as $expression) {
            $original = 'j.TJ_CODAREA = ' . $expression;
            $sql = str_replace(self::areaPredicate($expression), $original, $sql);
        }
        return $sql;
    }

    private static function areaPredicate(string $expression): string
    {
        return "',' + CASE WHEN j.TJ_CODAREA = 'INSTRU' THEN 'ELETRI' ELSE j.TJ_CODAREA END + ',' = "
            . "REPLACE(',' + " . $expression . " + ',', ',INSTRU,', ',ELETRI,')";
    }
}
