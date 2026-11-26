<?php
declare(strict_types=1);
namespace App\Service\Protheus;
use InvalidArgumentException;

final class OrderAuditSelection
{
    private const ALL = '(1 = 1 /* explicit audit consultation */)';
    private const EXACT_NUMBER = ' AND j.TJ_ORDEM = CAST(:drilldown_number AS VARCHAR(100))';

    public static function withDrilldownNumber(string $sql): string
    {
        $anchor = "j.D_E_L_E_T_ <> '*'";
        $position = strpos($sql, $anchor);
        return str_starts_with($sql, 'WITH scoped AS (') && $position !== false
            ? substr_replace($sql, $anchor . self::EXACT_NUMBER, $position, strlen($anchor)) : $sql;
    }

    public static function withoutDrilldownNumber(string $sql): string
    {
        return str_replace(self::EXACT_NUMBER, '', $sql);
    }

    public static function forListing(array $query): array
    {
        $selection = self::normalize($query);
        if (($query['situacao'] ?? '') === 'C' && in_array($selection['order_status'], ['', 'all'], true)) {
            $selection['order_status'] = 'cancelled';
        }
        if (in_array($selection['order_status'], ['', 'all'], true)) {
            $exact = trim((string)($query['os'] ?? '')) !== '';
            $selection['order_status'] = $exact && empty($query['situacao'])
                && empty($query['card']) && empty($query['backlog_age']) ? 'all' : '';
        }
        return $selection;
    }

    public static function normalize(array $query): array
    {
        $default = empty($query['situacao']) && empty($query['card']) && empty($query['backlog_age']) && empty($query['historico']) && empty($query['safra']) ? 'all' : '';
        $state = $query['order_status'] ?? $default;
        $user = $query['initial_user'] ?? '';
        if (!is_string($state) || !in_array($state, ['', 'all', 'open', 'closed', 'cancelled'], true)
            || !is_string($user) || strlen($user) > 100 || preg_match('/[\x00-\x1F\x7F]/', $user)) {
            throw new InvalidArgumentException('Situação ou usuário inválido.');
        }
        return ['order_status' => $state, 'initial_user' => trim($user)];
    }

    private static function clause(string $state, bool $user): string
    {
        $status = match ($state) {
            'open' => "(j.TJ_SITUACA IS NULL OR j.TJ_SITUACA <> 'C') AND (j.TJ_TERMINO IS NULL OR j.TJ_TERMINO <> 'S')",
            'closed' => "j.TJ_TERMINO = 'S' AND (j.TJ_SITUACA IS NULL OR j.TJ_SITUACA <> 'C')",
            'cancelled' => "j.TJ_SITUACA = 'C'",
            default => '1 = 1',
        };
        return ' AND (/* order audit selection */ ' . $status . ')'
            . ($user ? ' AND j.TJ_USUAINI = CAST(:audit_initial_user AS VARCHAR(100))' : '');
    }

    public static function apply(string $sql, string $state, bool $user): string
    {
        if ($sql === ProtheusQueries::historicalServiceDefinitions()) return $sql;
        $anchor = "j.D_E_L_E_T_ <> '*'";
        $position = strpos($sql, $anchor);
        if ($position === false) return $sql;
        if ($state !== '') $sql = str_replace(ProtheusOperationalEligibility::notCanceled('j'), self::ALL, $sql);
        return substr_replace($sql, $anchor . self::clause($state, $user), $position, strlen($anchor));
    }

    public static function remove(string $sql): string
    {
        foreach (['', 'all', 'open', 'closed', 'cancelled'] as $state) {
            foreach ([true, false] as $user) $sql = str_replace(self::clause($state, $user), '', $sql);
        }
        return str_replace(self::ALL, ProtheusOperationalEligibility::notCanceled('j'), $sql);
    }
}
