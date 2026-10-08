<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use Closure;
use InvalidArgumentException;
use Throwable;

/** Reuses the established opportunity/open operational scope without redefining it. */
final class OpportunityStopService
{
    public const WORKSHOPS = ['MECANI' => 'MECÂNICA', 'ELETRI' => 'ELÉTRICA'];
    public const UNITS = ['factory' => 'FÁBRICA', 'mill' => 'USINA', 'other' => 'OUTROS'];

    /** Accepts the shared sector service for deterministic tests. */
    public function __construct(
        private ?ProtheusSectorService $sectorService = null,
        private readonly ?Closure $loader = null,
        private ?ProtheusRepository $repository = null,
    ) {
    }

    /** Loads only open opportunity-stop orders using the established card filters. */
    public function load(array $query, bool $export = false, ?int $exportPage = null): array
    {
        $area = $query['area'] ?? '';
        if (!is_string($area) || ($area !== '' && !isset(self::WORKSHOPS[$area]))) {
            throw new InvalidArgumentException('Setor inválido.');
        }
        $area = trim($area);
        $unit = $query['unit'] ?? '';
        if (!is_string($unit) || ($unit !== '' && !isset(self::UNITS[$unit]))) {
            throw new InvalidArgumentException('Unidade inválida.');
        }
        $fixed = ['card' => 'opportunity', 'card_status' => 'EM ABERTO', 'status' => 'EM ABERTO'];
        $filters = array_replace($query, $fixed, ['unit' => $unit, 'opportunity_unit' => $unit]);
        $data = $this->loader !== null
            ? ($this->loader)($area, $filters, $export, $exportPage)
            : ($this->sectorService ?? new ProtheusSectorService())->load($area, $filters, $export, $exportPage);
        $data['area'] = $area;
        $data['area_name'] = $area === '' ? 'Todas' : self::WORKSHOPS[$area];
        $data['unit'] = $unit;
        $data['unit_name'] = $unit === '' ? 'TODAS' : self::UNITS[$unit];
        $data['total'] = $data['available'] ? (int)$data['breakdown']['opportunity']['open'] : null;

        return $data;
    }

    /** Returns only the two workshops validated for opportunity stops. */
    public function workshops(): array
    {
        return self::WORKSHOPS;
    }

    public function units(array $costCenters): array
    {
        $units = array_intersect_key(self::UNITS, array_flip(['factory', 'mill']));
        foreach (array_keys($costCenters) as $code) {
            if (self::unitForCostCenter((string)$code) === 'other') {
                $units['other'] = self::UNITS['other'];
                break;
            }
        }

        return $units;
    }

    public function costCentersForUnit(array $costCenters, string $unit): array
    {
        return $unit === '' ? $costCenters : array_filter(
            $costCenters,
            static fn($code): bool => self::unitForCostCenter((string)$code) === $unit,
            ARRAY_FILTER_USE_KEY,
        );
    }

    public static function unitForCostCenter(string $code): string
    {
        return ProtheusUnit::classify($code);
    }

    public static function costCenterLabel(array $row): string
    {
        $code = trim((string)($row['TJ_CCUSTO'] ?? ''));
        $name = trim((string)($row['cost_center_name'] ?? ''));

        return $name !== '' && $name !== $code ? $code . ' — ' . $name : $code;
    }

    /** Presentation for this page and its Excel only; preserves the source maintenance type. */
    public static function maintenanceTypeLabel(array $row): string
    {
        $service = trim((string)($row['TJ_SERVICO'] ?? ''));
        if (in_array($service, ProtheusSectorService::SERVICES['opportunity'], true)) {
            return 'Parada por Oportunidade';
        }
        $type = trim((string)($row['TJ_TIPO'] ?? ''));

        return ['COR' => 'Corretiva', 'PRE' => 'Preventiva', 'MEL' => 'Melhoria'][$type] ?? $type;
    }

    /** Returns code => official CTT description; the code remains the filter value. */
    public function costCenters(string $area = ''): array
    {
        try {
            $rows = ($this->repository ?? new ProtheusRepository(budgetSeconds: 5))
                ->opportunityCostCenters($area, ProtheusSectorService::SERVICES['opportunity']);

            $options = [];
            foreach ($rows as $row) {
                $code = trim((string)($row['code'] ?? ''));
                if ($code !== '') {
                    $name = trim((string)($row['name'] ?? ''));
                    $options[$code] = $name !== '' && $name !== $code ? $code . ' — ' . $name : $code;
                }
            }

            return $options;
        } catch (Throwable) {
            return [];
        }
    }
}
