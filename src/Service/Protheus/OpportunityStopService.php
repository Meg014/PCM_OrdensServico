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
        $fixed = ['card' => 'opportunity', 'card_status' => 'EM ABERTO', 'status' => 'EM ABERTO'];
        $filters = array_replace($query, $fixed);
        $data = $this->loader !== null
            ? ($this->loader)($area, $filters, $export, $exportPage)
            : ($this->sectorService ?? new ProtheusSectorService())->load($area, $filters, $export, $exportPage);
        $data['area'] = $area;
        $data['area_name'] = $area === '' ? 'Todas' : self::WORKSHOPS[$area];
        $data['total'] = $data['available'] ? (int)$data['breakdown']['opportunity']['open'] : null;

        return $data;
    }

    /** Returns only the two workshops validated for opportunity stops. */
    public function workshops(): array
    {
        return self::WORKSHOPS;
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
                    $options[$code] = trim((string)($row['name'] ?? '')) ?: $code;
                }
            }

            return $options;
        } catch (Throwable) {
            return [];
        }
    }
}
