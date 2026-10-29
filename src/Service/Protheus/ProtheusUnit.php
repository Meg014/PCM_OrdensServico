<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use InvalidArgumentException;

/** Single unit classifier: exact official cost-center codes, never prefixes. */
final class ProtheusUnit
{
    public const ALL = '';
    public const ADMIN = 'admin';
    public const FACTORY = 'factory';
    public const MILL = 'mill';
    public const EXTERNAL = 'external';
    public const SECTORS = 'sectors';
    public const OTHER = 'other';
    public const VALUES = ['', 'admin', 'factory', 'mill', 'external', 'sectors', 'other'];
    public const LABELS = ['admin' => 'Adm', 'factory' => 'Fábrica', 'mill' => 'Usina',
        'external' => 'Externo', 'sectors' => 'Setores', 'other' => 'Não classificados'];
    public const BUTTONS = ['' => 'Todas', 'factory' => 'Fábrica', 'mill' => 'Usina', 'admin' => 'Adm',
        'external' => 'Externo', 'sectors' => 'Setores'];
    private static ?array $catalog = null;
    private static ?array $lookup = null;
    private static array $expressions = [];

    public static function records(): array
    {
        self::$catalog ??= require dirname(__DIR__, 3) . '/resources/data/cost-center-units.php';
        return self::$catalog['records'];
    }

    public static function validate(mixed $value): string
    {
        if (!is_string($value) || !in_array(trim($value), self::VALUES, true)) throw new InvalidArgumentException('Unidade inválida.');
        return trim($value);
    }

    public static function metadata(?string $costCenter): ?array
    {
        if (self::$lookup === null) {
            self::$lookup = [];
            foreach (self::records() as $record) {
                foreach ([$record['code'], $record['protheus_code']] as $code) self::$lookup['code:' . $code] = $record;
            }
        }
        $code = preg_replace('/\s+/u', '', (string)$costCenter);
        return self::$lookup['code:' . $code] ?? null;
    }

    public static function classify(?string $costCenter): string
    {
        return self::metadata($costCenter)['unit'] ?? self::OTHER;
    }

    public static function label(?string $costCenter): string
    {
        return self::LABELS[self::classify($costCenter)];
    }

    public static function sourceGroup(?string $costCenter): string
    {
        return self::metadata($costCenter)['source_unit'] ?? '';
    }

    /** JSON is built only from the checked-in catalog; request values remain bound. */
    public static function expression(string $costCenter): string
    {
        $code = self::normalized($costCenter);
        return "COALESCE((SELECT MAX(mapped_unit.[value]) FROM " . self::mappingSql()
            . " mapped_unit WHERE mapped_unit.[key] COLLATE DATABASE_DEFAULT = {$code}), 'other')";
    }

    public static function predicate(string $costCenter, string $selector): string
    {
        $code = self::normalized($costCenter);
        $source = self::mappingSql();
        return "({$selector} = '' OR ({$selector} <> 'other' AND EXISTS (SELECT 1 FROM {$source} mapped_unit"
            . " WHERE mapped_unit.[key] COLLATE DATABASE_DEFAULT = {$code} AND mapped_unit.[value] COLLATE DATABASE_DEFAULT = {$selector}))"
            . " OR ({$selector} = 'other' AND NOT EXISTS (SELECT 1 FROM {$source} mapped_unit WHERE mapped_unit.[key] COLLATE DATABASE_DEFAULT = {$code})))";
    }

    public static function classified(string $costCenter = 'j.TJ_CCUSTO'): string
    {
        return '(EXISTS (SELECT 1 FROM ' . self::mappingSql() . ' mapped_unit WHERE mapped_unit.[key] COLLATE DATABASE_DEFAULT = ' . self::normalized($costCenter) . '))';
    }

    private static function normalized(string $costCenter): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$costCenter}, ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '')";
    }

    private static function mappingSql(): string
    {
        if (isset(self::$expressions['mapping'])) return self::$expressions['mapping'];
        $mapping = new \stdClass();
        foreach (self::records() as $record) {
            foreach ([$record['code'], $record['protheus_code']] as $code) {
                if (isset($mapping->{$code}) && $mapping->{$code} !== $record['unit']) throw new \RuntimeException('Centro de custo ambíguo no catálogo.');
                $mapping->{$code} = $record['unit'];
            }
        }
        $json = json_encode($mapping, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return self::$expressions['mapping'] = "OPENJSON(N'" . $json . "')";
    }
}
