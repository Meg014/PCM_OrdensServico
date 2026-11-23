<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use InvalidArgumentException;

/** Shared listing/export scope, applied before aggregation and pagination. */
final class ChecklistServiceFilter
{
    /** Values verified in ST4010; labels follow the requested route names. */
    public const SERVICES = [
        'PREELE' => 'PREVELE — Elétrica',
        'PREMEC' => 'PREVMEC — Mecânica',
        'PRELUB' => 'PREVLUB — Lubrificação',
        'PREVLA' => 'PREVLAM — Laminadores',
    ];

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
        return ' AND ' . self::servicePredicate()
            . " AND (CAST(:checklist_service AS VARCHAR(100)) = '' OR j.TJ_SERVICO = CAST(:checklist_service_code AS VARCHAR(100)))";
    }

    private static function servicePredicate(): string
    {
        return "j.TJ_SERVICO IN ('" . implode("', '", array_keys(self::SERVICES)) . "')";
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

    /** Confirm the actual service masters, including services with no current orders. */
    public static function options(): string
    {
        return "SELECT DISTINCT RTRIM(s.T4_SERVICO) AS code FROM dbo.ST4010 s WHERE s.D_E_L_E_T_ <> '*' AND "
            . str_replace('j.TJ_SERVICO', 's.T4_SERVICO', self::servicePredicate()) . ' ORDER BY code';
    }
}
